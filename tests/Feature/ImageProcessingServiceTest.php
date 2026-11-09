<?php

namespace Tests\Feature;

use App\Models\ProductImageCandidate;
use App\Services\ProductImages\Contracts\ImageSearchProvider;
use App\Services\ProductImages\FileImageFinderStore;
use App\Services\ProductImages\ImageDiscoveryManager;
use App\Services\ProductImages\ImageDownloadService;
use App\Services\ProductImages\ImageProcessingService;
use App\Services\ProductImages\ImageProviderRegistry;
use App\Services\ProductImages\Providers\OpenverseProvider;
use App\Services\ProductImages\Providers\SerpApiProvider;
use App\Services\ProductImages\Providers\WikimediaProvider;
use App\Services\ProductImages\RobotsPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ImageProcessingServiceTest extends TestCase
{
    public function test_raster_image_is_verified_and_converted_to_webp(): void
    {
        Storage::fake('public');
        config([
            'image_finder.format' => 'webp',
            'image_finder.min_width' => 800,
            'image_finder.max_file_size' => 10485760,
        ]);
        $image = new Imagick;
        $image->newImage(900, 900, new ImagickPixel('white'));
        $image->setImageFormat('png');
        $source = tempnam(sys_get_temp_dir(), 'source-image-');

        try {
            $image->writeImage($source);
            $image->clear();

            $result = app(ImageProcessingService::class)->process($source, 'public', 'product-images/test');
            $contents = Storage::disk('public')->get($result['path']);
            $processed = new Imagick;
            $processed->readImageBlob($contents);

            $this->assertSame('WEBP', $processed->getImageFormat());
            $this->assertSame(900, $result['width']);
            $this->assertSame(900, $result['height']);
            $this->assertSame(64, strlen($result['hash']));
            $processed->clear();
        } finally {
            @unlink($source);
        }
    }

    public function test_html_error_page_is_not_accepted_as_an_image(): void
    {
        Storage::fake('public');
        config(['image_finder.max_file_size' => 10485760, 'image_finder.min_width' => 800]);
        $source = tempnam(sys_get_temp_dir(), 'html-error-');
        file_put_contents($source, '<html>not an image</html>');

        try {
            $this->expectException(RuntimeException::class);
            app(ImageProcessingService::class)->process($source, 'public', 'product-images/test');
        } finally {
            @unlink($source);
        }
    }

    public function test_minimum_height_from_uploaded_settings_is_enforced(): void
    {
        Storage::fake('public');
        config([
            'image_finder.format' => 'webp',
            'image_finder.min_width' => 800,
            'image_finder.min_height' => 800,
            'image_finder.max_file_size' => 10485760,
        ]);
        $image = new Imagick;
        $image->newImage(900, 700, new ImagickPixel('white'));
        $image->setImageFormat('png');
        $source = tempnam(sys_get_temp_dir(), 'short-image-');

        try {
            $image->writeImage($source);
            $image->clear();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Image dimensions do not meet the configured requirements.');
            app(ImageProcessingService::class)->process($source, 'public', 'product-images/test');
        } finally {
            @unlink($source);
        }
    }

    public function test_private_ip_image_urls_are_blocked_before_http_requests(): void
    {
        $candidate = new ProductImageCandidate(['source_url' => 'https://127.0.0.1/internal.png']);

        $this->expectException(RuntimeException::class);
        app(ImageDownloadService::class)->downloadAndProcess($candidate, 'public', 'product-images/test');
    }

    public function test_download_http_failures_are_reported_without_accepting_html(): void
    {
        Cache::flush();
        config(['app.name' => 'جست‌وجوگر تصاویر محصول', 'image_finder.max_retries' => 1]);
        Http::preventStrayRequests();
        Http::fake([
            'https://8.8.8.8/robots.txt' => Http::response('', 404),
            'https://8.8.8.8/broken.jpg' => Http::response('<html>unavailable</html>', 503, ['Content-Type' => 'text/html']),
        ]);
        $candidate = new ProductImageCandidate(['source_url' => 'https://8.8.8.8/broken.jpg']);

        try {
            app(ImageDownloadService::class)->downloadAndProcess($candidate, 'public', 'product-images/test');
            $this->fail('A failed image response must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/broken.jpg')
            && str_contains(implode('', $request->header('User-Agent')), 'ProductImageFinder/1.0')
            && preg_match('/[^\x20-\x7E]/', implode('', $request->header('User-Agent'))) === 0);
    }

    public function test_robots_policy_blocks_disallowed_source_paths(): void
    {
        Cache::flush();
        config(['app.name' => 'جست‌وجوگر تصاویر محصول']);
        Http::fake([
            'https://images.example.test/robots.txt' => Http::response(
                "User-agent: ProductImageFinder\nDisallow: /private\nAllow: /private/public\n",
                200,
            ),
        ]);

        $policy = app(RobotsPolicy::class);
        $this->assertFalse($policy->allows('https://images.example.test/private/item.webp', '93.184.216.34'));
        $this->assertTrue($policy->allows('https://images.example.test/private/public/item.webp', '93.184.216.34'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains(implode('', $request->header('User-Agent')), 'ProductImageFinder/1.0')
            && preg_match('/[^\x20-\x7E]/', implode('', $request->header('User-Agent'))) === 0);
    }

    public function test_wikimedia_provider_preserves_license_and_source_metadata(): void
    {
        config(['app.name' => 'جست‌وجوگر تصاویر محصول']);
        Http::preventStrayRequests();
        Http::fake([
            'https://commons.wikimedia.org/w/api.php*' => Http::response([
                'query' => [
                    'pages' => [
                        [
                            'title' => 'File:Samsung Galaxy A55.webp',
                            'imageinfo' => [[
                                'url' => 'https://upload.wikimedia.org/image.webp',
                                'thumburl' => 'https://upload.wikimedia.org/thumb.webp',
                                'width' => 1200,
                                'height' => 1200,
                                'extmetadata' => [
                                    'LicenseShortName' => ['value' => 'CC BY 4.0'],
                                    'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by/4.0/'],
                                    'Artist' => ['value' => 'Example creator'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $results = app(WikimediaProvider::class)->search('Samsung Galaxy A55');

        $this->assertCount(1, $results);
        $this->assertSame('CC BY 4.0', $results[0]['license']);
        $this->assertSame('Example creator', $results[0]['attribution']);
        $this->assertSame(1200, $results[0]['width']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'commons.wikimedia.org/w/api.php')
            && str_contains(implode('', $request->header('User-Agent')), 'ProductImageFinder/1.0')
            && preg_match('/[^\x20-\x7E]/', implode('', $request->header('User-Agent'))) === 0);
    }

    public function test_wikimedia_provider_extracts_direct_image_urls_and_optional_metadata(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://commons.wikimedia.org/w/api.php*' => Http::response([
                'query' => [
                    'pages' => [
                        [
                            'ns' => 6,
                            'title' => 'File:Samsung Galaxy A55 5G.webp',
                            'imageinfo' => [[
                                'url' => 'https://upload.wikimedia.org/image.webp',
                                'thumburl' => 'https://upload.wikimedia.org/thumb.webp',
                                'width' => 1200,
                                'height' => 1200,
                                'size' => 512000,
                                'mime' => 'image/webp',
                            ]],
                        ],
                        [
                            'ns' => 6,
                            'title' => 'File:Malformed image.webp',
                            'imageinfo' => [[
                                'url' => 'https://commons.wikimedia.org/wiki/File:Malformed_image.webp',
                            ]],
                        ],
                    ],
                ],
            ]),
        ]);

        $results = app(WikimediaProvider::class)->search('Samsung Galaxy A55 5G');

        $this->assertSame(2, count($results));
        $this->assertSame('https://upload.wikimedia.org/thumb.webp', $results[0]['url']);
        $this->assertSame('https://upload.wikimedia.org/image.webp', $results[0]['original_url']);
        $this->assertSame('image/webp', $results[0]['mime']);
        $this->assertSame(512000, $results[0]['file_size']);
        $this->assertNull($results[0]['license']);
        $this->assertNull($results[0]['attribution']);
        $this->assertSame('https://commons.wikimedia.org/wiki/File%3ASamsung%20Galaxy%20A55%205G.webp', $results[0]['source_page_url']);
        $this->assertNull($results[1]['url']);
        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['formatversion'] ?? null) === '2'
                && ($query['prop'] ?? null) === 'imageinfo'
                && ($query['iiprop'] ?? null) === 'url|size|mime|extmetadata'
                && ($query['iiurlwidth'] ?? null) === '1200';
        });
    }

    public function test_wikimedia_provider_throws_for_malformed_api_responses(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://commons.wikimedia.org/w/api.php*' => Http::response(['unexpected' => []]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed response');

        app(WikimediaProvider::class)->search('Samsung Galaxy A55 5G');
    }

    public function test_wikimedia_provider_accepts_the_valid_empty_search_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://commons.wikimedia.org/w/api.php*' => Http::response([
                'batchcomplete' => true,
            ], 200),
        ]);

        $this->assertSame([], app(WikimediaProvider::class)->search('Samsung SM-A556'));
    }

    public function test_wikimedia_provider_reports_api_error_codes_from_successful_http_responses(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://commons.wikimedia.org/w/api.php*' => Http::response([
                'error' => [
                    'code' => 'cirrussearch-too-broad',
                    'info' => 'The search query was too broad.',
                ],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API error cirrussearch-too-broad with HTTP 200');

        app(WikimediaProvider::class)->search('Samsung Galaxy A55 5G');
    }

    public function test_wikimedia_provider_retries_rate_limited_searches(): void
    {
        config(['image_finder.max_retries' => 2, 'image_finder.retry_delay_ms' => 1]);
        Http::preventStrayRequests();
        Http::fake([
            'https://commons.wikimedia.org/w/api.php*' => Http::sequence()
                ->push(['error' => ['code' => 'ratelimited']], 429)
                ->push(['query' => ['pages' => []]], 200),
        ]);

        $results = app(WikimediaProvider::class)->search('Samsung Galaxy A55 5G');

        $this->assertSame([], $results);
        Http::assertSentCount(2);
    }

    public function test_provider_request_telemetry_records_http_status_duration_and_response_size(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://commons.wikimedia.org/w/api.php*' => Http::response([
                'batchcomplete' => true,
                'query' => ['pages' => []],
            ], 200),
        ]);
        $provider = app(WikimediaProvider::class);
        $provider->beginRequestTelemetry();

        $this->assertSame([], $provider->search('Samsung Galaxy A55 5G'));
        $telemetry = $provider->takeRequestTelemetry();

        $this->assertCount(1, $telemetry);
        $this->assertSame(200, $telemetry[0]['http_status']);
        $this->assertNull($telemetry[0]['transport_error']);
        $this->assertNull($telemetry[0]['failure_type']);
        $this->assertStringContainsString('commons.wikimedia.org/w/api.php', $telemetry[0]['request_url']);
        $this->assertGreaterThan(0, $telemetry[0]['response_bytes']);
        $this->assertGreaterThanOrEqual(0, $telemetry[0]['duration_ms']);
        $this->assertNotEmpty($telemetry[0]['request_started_at']);
    }

    public function test_provider_request_telemetry_redacts_api_keys_and_classifies_http_401(): void
    {
        config([
            'image_finder.max_retries' => 1,
            'image_finder.providers.serpapi.api_key' => 'secret-serp-api-key',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://serpapi.com/search.json*' => Http::response(['error' => 'invalid key'], 401),
        ]);
        $provider = app(SerpApiProvider::class);
        $provider->beginRequestTelemetry();

        try {
            $provider->search('Samsung Galaxy A55 5G');
            $this->fail('An authentication error must not be accepted.');
        } catch (RuntimeException $exception) {
            $provider->recordRequestFailure($exception);
        }

        $telemetry = $provider->takeRequestTelemetry();
        $this->assertSame(401, $telemetry[0]['http_status']);
        $this->assertSame('http_401_authentication_failed', $telemetry[0]['failure_type']);
        $this->assertStringNotContainsString('secret-serp-api-key', $telemetry[0]['request_url']);
        $this->assertStringContainsString('[REDACTED]', urldecode($telemetry[0]['request_url']));
    }

    public function test_provider_authentication_uses_openverse_form_client_credentials(): void
    {
        config([
            'image_finder.providers.openverse.client_id' => 'test-client-id',
            'image_finder.providers.openverse.client_secret' => 'test-client-secret',
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openverse.org/v1/auth_tokens/token/' => Http::response(['access_token' => 'test-access-token']),
            'https://api.openverse.org/v1/images/*' => Http::response([
                'results' => [[
                    'url' => 'https://images.example.test/samsung-galaxy-a55.jpg',
                    'thumbnail' => 'https://images.example.test/samsung-galaxy-a55-thumb.jpg',
                    'title' => 'Samsung Galaxy A55 5G',
                    'foreign_landing_url' => 'https://commons.example.test/samsung-galaxy-a55',
                    'license' => 'by-sa',
                    'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
                    'creator' => 'Example creator',
                    'width' => 1200,
                    'height' => 1600,
                    'mime_type' => 'image/jpeg',
                ]],
            ]),
        ]);

        $results = app(OpenverseProvider::class)->search('Samsung Galaxy A55 5G');

        $this->assertSame('https://images.example.test/samsung-galaxy-a55.jpg', $results[0]['url']);
        $this->assertSame('BY-SA', $results[0]['license']);
        $this->assertSame('image/jpeg', $results[0]['mime']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openverse.org/v1/auth_tokens/token/'
            && $request->method() === 'POST'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret');
        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/v1/images/')
                && ($query['q'] ?? null) === 'Samsung Galaxy A55 5G'
                && ! isset($query['aspect_ratio'])
                && $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }

    public function test_discovery_persists_valid_unknown_license_images_for_manual_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Cache::flush();
        config(['image_finder.max_search_depth' => 1]);
        $dataPath = storage_path('framework/testing/image-finder/'.Str::uuid());
        config(['image_finder.data_path' => $dataPath]);
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, [
            'name' => 'Samsung Galaxy A55 5G',
            'requested_count' => 1,
        ]);
        $provider = Mockery::mock(ImageSearchProvider::class);
        $provider->shouldReceive('name')->andReturn('Wikimedia Commons');
        $provider->shouldReceive('search')->once()->andReturn([[
            'url' => 'https://upload.wikimedia.org/samsung-galaxy-a55.webp',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Samsung_Galaxy_A55_5G.webp',
            'title' => 'File:Samsung Galaxy A55 5G.webp',
            'source_name' => 'Wikimedia Commons',
            'mime' => 'image/webp',
        ]]);
        $registry = Mockery::mock(ImageProviderRegistry::class);
        $registry->shouldReceive('configured')->once()->andReturn([$provider]);
        $registry->shouldReceive('statuses')->once()->andReturn([]);
        $this->app->instance(ImageProviderRegistry::class, $registry);
        $downloads = Mockery::mock(ImageDownloadService::class);
        $downloads->shouldReceive('downloadAndProcess')->once()->andReturn([
            'path' => 'product-images/'.$batch->id.'/'.$task->id.'/image.webp',
            'disk' => 'local',
            'width' => 900,
            'height' => 900,
            'hash' => str_repeat('c', 64),
        ]);
        $this->app->instance(ImageDownloadService::class, $downloads);

        try {
            app(ImageDiscoveryManager::class)->discover($task);

            $candidate = $store->candidates($task->id)[0];
            $this->assertSame('needs_review', $candidate->verification_status);
            $this->assertSame('unknown', $candidate->license_status);
            $this->assertSame('license_unknown', $candidate->review_reason);
            $this->assertSame('https://commons.wikimedia.org/wiki/File:Samsung_Galaxy_A55_5G.webp', $candidate->source_page_url);
            $this->assertSame('image/webp', $candidate->metadata['mime']);
            $activities = $store->activities($batch->id, PHP_INT_MAX);
            $validated = collect($activities)->firstWhere('event', 'candidate_validated');
            $this->assertSame('needs_review', $validated->metadata['verification_status']);
            $this->assertSame('unknown', $validated->metadata['license_status']);
        } finally {
            File::deleteDirectory($dataPath);
        }
    }
}

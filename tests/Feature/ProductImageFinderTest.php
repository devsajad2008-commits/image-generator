<?php

namespace Tests\Feature;

use App\Jobs\SearchProductImagesJob;
use App\Models\ImageSearchBatch;
use App\Models\ProductImageCandidate;
use App\Models\ProductImageTask;
use App\Services\ProductImages\Contracts\ImageSearchProvider;
use App\Services\ProductImages\FileImageFinderStore;
use App\Services\ProductImages\ImageDiscoveryManager;
use App\Services\ProductImages\ImageDownloadService;
use App\Services\ProductImages\ImageProviderRegistry;
use App\Services\ProductImages\ImageVerificationService;
use App\Services\ProductImages\SearchQueryGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ProductImageFinderTest extends TestCase
{
    private string $testDataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDataPath = storage_path('framework/testing/image-finder/'.Str::uuid());
        config(['image_finder.data_path' => $this->testDataPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testDataPath);
        parent::tearDown();
    }

    private function createOwnedBatch(): ImageSearchBatch
    {
        $ownerId = Str::random(40);
        $this->withCookie(config('session.cookie'), $ownerId);

        return app(FileImageFinderStore::class)->createBatch([], $ownerId);
    }

    public function test_dashboard_is_public_localized_and_has_no_login_page(): void
    {
        $this->get('/')
            ->assertRedirect('/image-finder');

        $this->get('/image-finder')
            ->assertOk()
            ->assertSee('جست‌وجو و گردآوری تصاویر محصول')
            ->assertSee('sajadfarghyari.ir')
            ->assertSee('dir="rtl"', false);

        $this->get('/login')->assertNotFound();
    }

    public function test_batch_history_page_renders_at_the_batches_url(): void
    {
        $this->get('/image-finder/batches')
            ->assertOk()
            ->assertSee('تاریخچهٔ جست‌وجوها')
            ->assertSee('تاریخچه خالی است');
    }

    public function test_batch_history_can_delete_a_batch_and_its_image_files(): void
    {
        Storage::fake('public');
        config(['image_finder.disk' => 'public']);
        $store = app(FileImageFinderStore::class);
        $batch = $this->createOwnedBatch();
        $store->updateBatch($batch->id, ['status' => 'completed']);
        $task = $store->createTask($batch->id, ['name' => 'Test product']);
        $path = 'product-images/'.$batch->id.'/'.$task->id.'/image.webp';
        Storage::disk('public')->put($path, 'webp');
        $store->createCandidate($task->id, [
            'local_path' => $path,
            'metadata' => ['storage_disk' => 'public'],
        ]);

        $this->delete(route('image-finder.batches.destroy', $batch->id))
            ->assertRedirect(route('image-finder.batches.index'))
            ->assertSessionHas('status', 'جست‌وجو و فایل‌های مرتبط با موفقیت حذف شدند.');

        $this->assertNull($store->batch($batch->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_batch_history_cannot_delete_a_batch_owned_by_another_session(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch([], (string) Str::uuid());

        $this->delete(route('image-finder.batches.destroy', $batch->id))
            ->assertNotFound();

        $this->assertNotNull($store->batch($batch->id));
    }

    public function test_batch_history_preserves_a_batch_while_it_is_active(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $this->createOwnedBatch();

        $this->from(route('image-finder.batches.index'))
            ->delete(route('image-finder.batches.destroy', $batch->id))
            ->assertRedirect(route('image-finder.batches.index'))
            ->assertSessionHasErrors(['batch' => 'برای حذف، ابتدا پردازش این دسته را متوقف کنید.']);

        $this->assertNotNull($store->batch($batch->id));
    }

    public function test_recent_searches_offer_cancel_delete_and_download_actions_as_applicable(): void
    {
        Storage::fake('public');
        config(['image_finder.disk' => 'public']);
        $store = app(FileImageFinderStore::class);
        $activeBatch = $this->createOwnedBatch();
        $store->createTask($activeBatch->id, ['name' => 'Active product', 'status' => 'queued']);
        $completedBatch = $store->createBatch([], $activeBatch->owner_id);
        $store->updateBatch($completedBatch->id, ['status' => 'completed']);
        $completedTask = $store->createTask($completedBatch->id, ['name' => 'Completed product']);
        $path = 'product-images/'.$completedBatch->id.'/'.$completedTask->id.'/image.webp';
        Storage::disk('public')->put($path, 'webp');
        $store->createCandidate($completedTask->id, [
            'verification_status' => 'needs_review',
            'local_path' => $path,
            'metadata' => ['storage_disk' => 'public'],
        ]);

        $homeResponse = $this->get(route('image-finder.index'));
        $this->assertSame(
            1,
            collect($homeResponse->viewData('batches'))->firstWhere('id', $completedBatch->id)->downloadable_count,
        );
        $homeResponse
            ->assertOk()
            ->assertSee('لغو فرایند')
            ->assertSee('دانلود عکس‌ها')
            ->assertSee('حذف');

        $this->get(route('image-finder.batches.index'))
            ->assertOk()
            ->assertSee('لغو فرایند')
            ->assertSee('دانلود عکس‌ها')
            ->assertSee('حذف');
    }

    public function test_active_batch_can_be_cancelled_from_the_search_list(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $this->createOwnedBatch();
        $task = $store->createTask($batch->id, ['name' => 'Queued product', 'status' => 'queued']);

        $this->post(route('image-finder.batches.cancel', $batch->id))
            ->assertRedirect();

        $this->assertSame('cancelled', $store->batch($batch->id)->status);
        $this->assertSame('needs_review', $store->task($task->id)->status);
    }

    public function test_finished_batch_cannot_be_cancelled(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $this->createOwnedBatch();
        $store->updateBatch($batch->id, ['status' => 'completed']);

        $this->post(route('image-finder.batches.cancel', $batch->id))
            ->assertRedirect()
            ->assertSessionHas('status', 'این جست‌وجو در حال اجرا نیست.');

        $this->assertSame('completed', $store->batch($batch->id)->status);
    }

    public function test_batch_history_can_clear_only_the_current_sessions_batches(): void
    {
        $store = app(FileImageFinderStore::class);
        $ownedBatch = $this->createOwnedBatch();
        $store->updateBatch($ownedBatch->id, ['status' => 'completed']);
        $otherBatch = $store->createBatch([], (string) Str::uuid());

        $this->from(route('image-finder.batches.index'))
            ->delete(route('image-finder.batches.destroy-all'))
            ->assertRedirect(route('image-finder.batches.index'))
            ->assertSessionHas('status', 'تاریخچهٔ جست‌وجوها پاک شد.');

        $this->assertNull($store->batch($ownedBatch->id));
        $this->assertNotNull($store->batch($otherBatch->id));
    }

    public function test_batch_history_preserves_active_batches_when_clearing_is_requested(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $this->createOwnedBatch();

        $this->from(route('image-finder.batches.index'))
            ->delete(route('image-finder.batches.destroy-all'))
            ->assertRedirect(route('image-finder.batches.index'))
            ->assertSessionHasErrors(['batch' => 'برای پاک‌کردن تاریخچه، ابتدا پردازش‌های در حال اجرا را متوقف کنید.']);

        $this->assertNotNull($store->batch($batch->id));
    }

    public function test_single_and_multiple_products_are_saved_without_a_database(): void
    {
        $response = $this->post(route('image-finder.batches.store'), [
            'product_name' => 'Samsung Galaxy A55',
            'brand' => 'Samsung',
            'model' => 'SM-A556E',
        ]);
        $response->assertRedirect();
        $batchId = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->assertSame(
            app(FileImageFinderStore::class)->batch($batchId)->owner_id,
            $this->app['session']->getId(),
        );

        $this->post(route('image-finder.batches.store'), [
            'product_names' => "Philips Air Fryer HD9200\nSony WH-1000XM5",
            'images_per_product' => 2,
        ])->assertRedirect();

        $store = app(FileImageFinderStore::class);
        $batches = $store->batches();
        $this->assertCount(2, $batches);
        $allTasks = array_merge(...array_map(fn ($batch) => $store->tasks($batch->id), $batches));
        $this->assertSame(
            ['Philips Air Fryer HD9200', 'Samsung Galaxy A55', 'Sony WH-1000XM5'],
            collect($allTasks)->pluck('name')->sort()->values()->all(),
        );
        $this->assertSame('SM-A556E', collect($allTasks)->firstWhere('name', 'Samsung Galaxy A55')->model);
        $this->assertSame(2, collect($allTasks)->firstWhere('name', 'Sony WH-1000XM5')->requested_count);
        $this->assertFileExists($this->testDataPath.'/'.$batches[0]->id.'.json');
    }

    public function test_json_import_accepts_product_objects_and_settings(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.json', json_encode([
            'products' => [
                '1001' => [
                    'id' => 1001,
                    'name' => 'Samsung Galaxy A55',
                    'brand' => 'Samsung',
                    'model' => 'SM-A556E',
                    'images' => 3,
                    'search_terms' => ['Samsung Galaxy A55 official product images', 'Galaxy A55 front back view'],
                ],
                '1002' => ['name' => 'Philips Air Fryer HD9200'],
            ],
            'settings' => [
                'images_per_product' => 2,
                'image_format' => 'webp',
                'min_width' => 900,
                'min_height' => 850,
                'language' => 'en',
                'search_mode' => 'comprehensive',
                'auto_approve_open_licensed' => true,
                'require_manual_review_for_unverified' => true,
                'allow_similar_products' => false,
                'download_images' => true,
                'generate_alternative_queries' => true,
                'deduplicate' => true,
            ],
        ], JSON_THROW_ON_ERROR));

        $this->post(route('image-finder.batches.store'), ['products_file' => $file])->assertRedirect();

        $store = app(FileImageFinderStore::class);
        $batch = $store->batches()[0];
        $tasks = $store->tasks($batch->id);
        $this->assertSame(900, $batch->settings['min_width']);
        $this->assertSame(850, $batch->settings['min_height']);
        $this->assertSame('webp', $batch->settings['image_format']);
        $this->assertSame('en', $batch->settings['language']);
        $this->assertSame('comprehensive', $batch->settings['search_mode']);
        $this->assertTrue($batch->settings['auto_approve_open_licensed']);
        $this->assertTrue($batch->settings['require_manual_review_for_unverified']);
        $this->assertFalse($batch->settings['allow_similar_products']);
        $this->assertTrue($batch->settings['download_images']);
        $this->assertTrue($batch->settings['generate_alternative_queries']);
        $this->assertTrue($batch->settings['deduplicate']);
        $this->assertSame('1001', $tasks[0]->external_product_id);
        $this->assertSame(3, $tasks[0]->requested_count);
        $this->assertSame(['Samsung Galaxy A55 official product images', 'Galaxy A55 front back view'], $tasks[0]->search_terms);
        $this->assertSame(2, $tasks[1]->requested_count);
    }

    public function test_json_import_uses_valid_default_settings_when_settings_are_omitted(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.json', json_encode([
            ['name' => 'Samsung Galaxy A55'],
        ], JSON_THROW_ON_ERROR));

        $this->post(route('image-finder.batches.store'), ['products_file' => $file])
            ->assertRedirect();

        $batch = app(FileImageFinderStore::class)->batches()[0];

        $this->assertSame(1, $batch->settings['min_height']);
        $this->assertSame(3, app(FileImageFinderStore::class)->tasks($batch->id)[0]->requested_count);
    }

    public function test_json_import_rejects_fractional_per_product_image_counts(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.json', json_encode([
            'products' => [
                ['name' => 'Samsung Galaxy A55', 'images' => 1.5],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->from('/image-finder')
            ->post(route('image-finder.batches.store'), ['products_file' => $file])
            ->assertRedirect('/image-finder')
            ->assertSessionHasErrors([
                'products_file' => 'تعداد تصاویر محصول شمارهٔ 1 باید عدد صحیح باشد.',
            ]);

        $this->assertSame([], app(FileImageFinderStore::class)->batches());
    }

    public function test_json_import_rejects_null_image_count_settings(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.json', json_encode([
            'products' => [
                ['name' => 'Samsung Galaxy A55'],
            ],
            'settings' => [
                'images_per_product' => null,
            ],
        ], JSON_THROW_ON_ERROR));

        $this->from('/image-finder')
            ->post(route('image-finder.batches.store'), ['products_file' => $file])
            ->assertRedirect('/image-finder')
            ->assertSessionHasErrors([
                'products_file' => 'تعداد تصاویر باید بین ۱ تا ۱۰ باشد.',
            ]);

        $this->assertSame([], app(FileImageFinderStore::class)->batches());
    }

    public function test_product_search_queries_remove_repeated_words_and_search_filler(): void
    {
        config(['image_finder.max_search_depth' => 4]);

        $queries = app(SearchQueryGenerator::class)->generate([
            'brand' => 'Samsung',
            'name' => 'Samsung Galaxy A55 5G',
            'model' => 'Galaxy A55 5G',
            'category' => 'Mobile Phones',
        ]);

        $this->assertSame([
            'Samsung Galaxy A55 5G',
            'Samsung Galaxy A55 5G official product image',
            'Samsung Galaxy A55 5G front back',
            'Samsung Galaxy A55 5G Mobile Phones',
        ], $queries);
    }

    public function test_samsung_galaxy_a55_generates_model_and_product_image_queries(): void
    {
        config(['image_finder.max_search_depth' => 6]);

        $queries = app(SearchQueryGenerator::class)->generate([
            'brand' => 'Samsung',
            'name' => 'Samsung Galaxy A55 5G',
            'category' => 'Smartphone',
        ]);

        $this->assertSame([
            'Samsung Galaxy A55 5G',
            'Samsung SM-A556',
            'Samsung Galaxy A55 5G official product image',
            'Samsung Galaxy A55 5G front back',
            'Samsung Galaxy A55 5G Smartphone',
        ], $queries);
    }

    public function test_product_search_queries_use_supplied_terms_before_optional_alternatives(): void
    {
        config(['image_finder.max_search_depth' => 4]);
        $task = [
            'name' => 'Samsung Galaxy A55 5G',
            'brand' => 'Samsung',
            'model' => 'Galaxy A55 5G',
            'category' => 'Mobile Phones',
            'search_terms' => [
                'Samsung Galaxy A55 5G official product images',
                'Galaxy A55 front back view',
                'Samsung Galaxy A55 5G official product images',
            ],
            'settings' => ['generate_alternative_queries' => false],
        ];

        $queries = app(SearchQueryGenerator::class)->generate($task);

        $this->assertSame([
            'Samsung Galaxy A55 5G official product images',
            'Galaxy A55 front back view',
        ], $queries);

        $task['settings']['generate_alternative_queries'] = true;
        $task['settings']['search_mode'] = 'comprehensive';
        $queriesWithAlternatives = app(SearchQueryGenerator::class)->generate($task);
        $this->assertSame(array_slice($queriesWithAlternatives, 0, 2), $queries);
        $this->assertGreaterThan(count($queries), count($queriesWithAlternatives));
    }

    public function test_product_matching_uses_category_only_when_similar_products_are_allowed(): void
    {
        $task = [
            'name' => 'Acme Camera',
            'category' => 'Kitchen Appliances',
        ];

        $verification = app(ImageVerificationService::class);
        $this->assertSame(0.25, $verification->confidence($task, 'Kitchen Appliances', null));
        $this->assertSame(0.575, $verification->confidence($task, 'Kitchen Appliances', null, true));
    }

    public function test_product_matching_supports_persian_names_and_titles(): void
    {
        $verification = app(ImageVerificationService::class);

        $this->assertSame(0.9, $verification->confidence(
            ['name' => 'کاسه سفالی دست‌ساز'],
            'کاسه سفالی دست ساز',
            null,
        ));
    }

    public function test_batches_are_private_to_the_browser_session_without_login(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch([], (string) Str::uuid());

        $this->get(route('image-finder.batches.show', $batch->id))->assertNotFound();
        $this->get(route('image-finder.index'))->assertOk();
    }

    public function test_bad_json_and_empty_search_are_rejected_in_persian(): void
    {
        $this->from('/image-finder')
            ->post(route('image-finder.batches.store'), [])
            ->assertSessionHasErrors(['product_name' => 'نام محصول، فهرست محصولات یا فایل JSON را وارد کنید.']);

        $file = UploadedFile::fake()->createWithContent('broken.json', '{invalid');
        $this->from('/image-finder')
            ->post(route('image-finder.batches.store'), ['products_file' => $file])
            ->assertSessionHasErrors('products_file');

        $invalidTerms = UploadedFile::fake()->createWithContent('invalid-search-terms.json', json_encode([
            'products' => [['name' => 'Product with invalid terms', 'search_terms' => 'this should be a list']],
        ], JSON_THROW_ON_ERROR));
        $this->from('/image-finder')
            ->post(route('image-finder.batches.store'), ['products_file' => $invalidTerms])
            ->assertSessionHasErrors('products_file');

        $this->assertSame([], app(FileImageFinderStore::class)->batches());
    }

    public function test_file_worker_processes_queued_products(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch([
            'min_width' => 900,
            'min_height' => 850,
            'image_format' => 'webp',
            'language' => 'fa',
        ]);
        $task = $store->createTask($batch->id, ['name' => 'Queued product', 'requested_count' => 1]);
        $manager = Mockery::mock(ImageDiscoveryManager::class);
        $manager->shouldReceive('discover')->once()->with(Mockery::type(ProductImageTask::class))
            ->andReturnUsing(function (ProductImageTask $claimedTask) use ($store): void {
                $this->assertSame(900, config('image_finder.min_width'));
                $this->assertSame(850, config('image_finder.min_height'));
                $this->assertSame('webp', config('image_finder.format'));
                $this->assertSame('fa', config('image_finder.language'));
                $store->updateTask($claimedTask->id, ['status' => 'verified']);
            });
        $this->app->instance(ImageDiscoveryManager::class, $manager);

        Artisan::call('image-finder:work', ['--once' => true]);

        $this->assertSame('verified', $store->task($task->id)->status);
        $this->assertSame(1, $store->task($task->id)->attempts);
    }

    public function test_worker_retries_transient_failures_with_a_bounded_delay(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'Retry product', 'requested_count' => 1]);
        $store->updateTask($task->id, ['attempts' => 1, 'status' => 'processing']);

        (new SearchProductImagesJob($task->id))->failed($store, new RuntimeException('temporary error'));

        $retriedTask = $store->task($task->id);
        $this->assertSame('queued', $retriedTask->status);
        $this->assertNotNull($retriedTask->available_at);
        $this->assertSame('queued', $store->batch($batch->id)->status);
    }

    public function test_provider_failure_falls_back_to_the_next_provider(): void
    {
        config(['image_finder.max_search_depth' => 1]);
        Cache::flush();
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, [
            'name' => 'Generic product',
            'requested_count' => 3,
            'status' => 'processing',
        ]);
        $first = Mockery::mock(ImageSearchProvider::class);
        $first->shouldReceive('name')->andReturn('Unavailable provider');
        $first->shouldReceive('search')->once()->andThrow(new RuntimeException('provider unavailable'));
        $fallback = Mockery::mock(ImageSearchProvider::class);
        $fallback->shouldReceive('name')->andReturn('Fallback provider');
        $fallback->shouldReceive('search')->once()->andReturn([]);
        $registry = Mockery::mock(ImageProviderRegistry::class);
        $registry->shouldReceive('configured')->once()->andReturn([$first, $fallback]);
        $registry->shouldReceive('statuses')->once()->andReturn([]);
        $this->app->instance(ImageProviderRegistry::class, $registry);

        app(ImageDiscoveryManager::class)->discover($task);

        $events = array_map(fn ($activity) => [$activity->provider, $activity->event], $store->activities($batch->id, 1000));
        $this->assertContains(['Unavailable provider', 'provider_failed'], $events);
        $this->assertContains(['Fallback provider', 'search_completed'], $events);
        $this->assertSame('needs_review', $store->task($task->id)->status);
    }

    public function test_provider_rate_limits_requeue_products_instead_of_marking_them_failed(): void
    {
        config(['image_finder.provider_rate_limit' => 1]);
        Cache::flush();
        RateLimiter::clear('image-finder:provider:'.sha1('Throttled provider'));
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, [
            'name' => 'Acme Widget',
            'brand' => 'Acme',
            'model' => 'W-1',
            'requested_count' => 1,
        ]);
        $provider = Mockery::mock(ImageSearchProvider::class);
        $provider->shouldReceive('name')->andReturn('Throttled provider');
        $provider->shouldReceive('search')->once()->andReturn([]);
        $registry = Mockery::mock(ImageProviderRegistry::class);
        $registry->shouldReceive('configured')->once()->andReturn([$provider]);
        $registry->shouldReceive('statuses')->once()->andReturn([]);
        $this->app->instance(ImageProviderRegistry::class, $registry);

        app(ImageDiscoveryManager::class)->discover($task);

        $retriedTask = $store->task($task->id);
        $events = array_map(fn ($activity) => $activity->event, $store->activities($batch->id, 1000));
        $this->assertSame('queued', $retriedTask->status);
        $this->assertNotNull($retriedTask->available_at);
        $this->assertContains('provider_throttled', $events);
        $this->assertNotContains('provider_failed', $events);
    }

    public function test_provider_rejections_are_saved_with_specific_diagnostic_reasons(): void
    {
        config([
            'image_finder.max_search_depth' => 1,
            'image_finder.min_candidate_confidence' => 0.1,
        ]);
        Cache::flush();
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, [
            'name' => 'Samsung Galaxy A55',
            'brand' => 'Samsung',
            'model' => 'SM-A556',
            'requested_count' => 1,
        ]);
        $provider = Mockery::mock(ImageSearchProvider::class);
        $provider->shouldReceive('name')->andReturn('Diagnostic provider');
        $provider->shouldReceive('search')->once()->andReturn([
            ['title' => 'Samsung Galaxy A55 5G image'],
            [
                'url' => 'https://images.example.test/galaxy-a34.jpg',
                'title' => 'Samsung Galaxy A34 5G',
            ],
        ]);
        $registry = Mockery::mock(ImageProviderRegistry::class);
        $registry->shouldReceive('configured')->once()->andReturn([$provider]);
        $registry->shouldReceive('statuses')->once()->andReturn([]);
        $this->app->instance(ImageProviderRegistry::class, $registry);

        app(ImageDiscoveryManager::class)->discover($task);

        $activities = $store->activities($batch->id, PHP_INT_MAX);
        $rejections = array_values(array_filter($activities, fn ($activity): bool => $activity->event === 'candidate_rejected'));
        $search = collect($activities)->firstWhere('event', 'search_completed');
        $this->assertSame(2, $search->metadata['raw_results']);
        $this->assertSame(1, $search->metadata['direct_image_urls']);
        $this->assertSame(['irrelevant_product', 'missing_direct_image_url'], collect($rejections)
            ->map(fn ($activity): string => $activity->metadata['reason'])
            ->sort()
            ->values()
            ->all());
        $this->assertSame([], $store->candidates($task->id));
    }

    public function test_provider_registry_reports_missing_credentials_instead_of_silently_skipping(): void
    {
        config([
            'image_finder.providers.wikimedia.enabled' => true,
            'image_finder.providers.openverse.enabled' => true,
            'image_finder.providers.openverse.client_id' => null,
            'image_finder.providers.openverse.client_secret' => null,
            'image_finder.providers.serpapi.enabled' => true,
            'image_finder.providers.serpapi.api_key' => null,
            'image_finder.providers.dataforseo.enabled' => true,
            'image_finder.providers.dataforseo.login' => null,
            'image_finder.providers.dataforseo.password' => null,
        ]);

        $statuses = app(ImageProviderRegistry::class)->statuses();

        $this->assertSame([
            'wikimedia' => 'public',
            'openverse' => 'anonymous',
            'serpapi' => 'missing_credentials',
            'dataforseo' => 'missing_credentials',
        ], collect($statuses)->mapWithKeys(fn (array $status): array => [$status['key'] => $status['status']])->all());
    }

    public function test_discovery_normalizes_legacy_wikimedia_thumbnail_urls_when_retrying_failed_downloads(): void
    {
        Storage::fake('public');
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'Acme Camera', 'requested_count' => 1]);
        $candidate = $store->createCandidate($task->id, [
            'source_name' => 'Wikimedia Commons',
            'source_url' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/a/a1/acme-camera.jpg/1200px-acme-camera.jpg?source=commons',
            'license' => 'CC BY 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
            'attribution' => 'Example creator',
            'confidence_score' => 0.9,
            'verification_status' => 'failed',
            'metadata' => [],
        ]);
        $downloads = Mockery::mock(ImageDownloadService::class);
        $downloadedUrl = null;
        $downloads->shouldReceive('downloadAndProcess')->once()->with(
            Mockery::on(function (ProductImageCandidate $downloadCandidate) use (&$downloadedUrl): bool {
                $downloadedUrl = $downloadCandidate->source_url;

                return true;
            }),
            'public',
            'product-images/'.$batch->id.'/'.$task->id,
        )->andReturn([
            'path' => 'product-images/'.$batch->id.'/'.$task->id.'/image.webp',
            'disk' => 'public',
            'width' => 1200,
            'height' => 1200,
            'hash' => str_repeat('a', 64),
        ]);
        $this->app->instance(ImageDownloadService::class, $downloads);
        $registry = Mockery::mock(ImageProviderRegistry::class);
        $registry->shouldReceive('configured')->once()->andReturn([]);
        $registry->shouldReceive('statuses')->once()->andReturn([]);
        $this->app->instance(ImageProviderRegistry::class, $registry);

        app(ImageDiscoveryManager::class)->discover($task);

        $retriedCandidate = $store->candidate($candidate->id);
        $this->assertSame('verified', $retriedCandidate->verification_status);
        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a1/acme-camera.jpg/1200px-acme-camera.jpg?source=commons',
            $downloadedUrl,
        );
        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a1/acme-camera.jpg/1200px-acme-camera.jpg?source=commons',
            $retriedCandidate->source_url,
        );
        $this->assertSame('product-images/'.$batch->id.'/'.$task->id.'/image.webp', $retriedCandidate->local_path);
        $this->assertSame('verified', $store->task($task->id)->status);
    }

    public function test_discovery_normalizes_wikimedia_thumbnail_urls_from_search_results(): void
    {
        Cache::flush();
        Storage::fake('public');
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'Acme Camera', 'requested_count' => 1]);
        $provider = Mockery::mock(ImageSearchProvider::class);
        $provider->shouldReceive('name')->andReturn('Wikimedia Commons');
        $provider->shouldReceive('search')->once()->andReturn([[
            'url' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/a/a1/acme-camera.jpg/1200px-acme-camera.jpg?source=commons',
            'title' => 'Acme Camera',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Acme_Camera.jpg',
            'license' => 'CC0',
            'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'source_name' => 'Wikimedia Commons',
        ]]);
        $registry = Mockery::mock(ImageProviderRegistry::class);
        $registry->shouldReceive('configured')->once()->andReturn([$provider]);
        $registry->shouldReceive('statuses')->once()->andReturn([]);
        $this->app->instance(ImageProviderRegistry::class, $registry);
        $downloads = Mockery::mock(ImageDownloadService::class);
        $downloads->shouldReceive('downloadAndProcess')->once()->with(
            Mockery::on(fn (ProductImageCandidate $candidate): bool => $candidate->source_url
                === 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a1/acme-camera.jpg/1200px-acme-camera.jpg?source=commons'),
            'public',
            'product-images/'.$batch->id.'/'.$task->id,
        )->andReturn([
            'path' => 'product-images/'.$batch->id.'/'.$task->id.'/image.webp',
            'disk' => 'public',
            'width' => 1200,
            'height' => 1200,
            'hash' => str_repeat('b', 64),
        ]);
        $this->app->instance(ImageDownloadService::class, $downloads);

        app(ImageDiscoveryManager::class)->discover($task);

        $candidate = $store->candidates($task->id)[0];
        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a1/acme-camera.jpg/1200px-acme-camera.jpg?source=commons',
            $candidate->source_url,
        );
        $this->assertSame('verified', $candidate->verification_status);
        $this->assertSame('verified', $store->task($task->id)->status);
    }

    public function test_batch_report_explains_empty_image_results_and_active_sources(): void
    {
        config(['image_finder.providers.wikimedia.enabled' => true]);
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'No image result', 'requested_count' => 1]);
        $store->updateTask($task->id, ['status' => 'needs_review']);
        $store->activity(
            $batch->id,
            $task->id,
            'Wikimedia Commons',
            'provider_failed',
            'Search failed.',
            [
                'http_status' => 200,
                'response_ms' => 25,
                'error' => 'Wikimedia Commons API error cirrussearch-too-broad with HTTP 200.',
            ],
        );

        $this->get(route('image-finder.batches.show', $batch->id))
            ->assertOk()
            ->assertSee('وضعیت منابع جست‌وجو')
            ->assertSee('1 درخواست منبع ناموفق یا به سقف مجاز برخورد کرده است.')
            ->assertSee('cirrussearch-too-broad')
            ->assertSee('Wikimedia Commons')
            ->assertSee('در این نوبت تصویری برای نمایش ثبت نشد.');
    }

    public function test_batch_report_displays_provider_request_telemetry_without_counting_skipped_providers(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'Samsung Galaxy A55 5G', 'requested_count' => 1]);
        $store->activity($batch->id, $task->id, 'Wikimedia Commons', 'provider_request', 'Provider request failed.', [
            'request_url' => 'https://commons.wikimedia.org/w/api.php?action=query',
            'request_started_at' => now()->subSecond()->toIso8601String(),
            'duration_ms' => 425,
            'http_status' => 403,
            'response_bytes' => 1024,
            'failure_type' => 'http_403_access_denied',
            'error' => 'HTTP 403 response.',
        ]);
        $store->activity($batch->id, $task->id, 'DataForSEO Google Images', 'provider_skipped', 'Missing credentials.', [
            'status' => 'missing_credentials',
            'credentials_present' => false,
        ]);

        $this->get(route('image-finder.batches.show', $batch->id))
            ->assertOk()
            ->assertSee('https://commons.wikimedia.org/w/api.php?action=query')
            ->assertSee('http_403_access_denied')
            ->assertSee('425 ms')
            ->assertSee('1,024 bytes')
            ->assertSee('DataForSEO Google Images');
    }

    public function test_successful_provider_telemetry_does_not_report_zero_handler_data_as_a_failure(): void
    {
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'Samsung Galaxy A55 5G', 'requested_count' => 1]);
        $store->activity($batch->id, $task->id, 'Wikimedia Commons', 'provider_request', 'Provider request failed: connection_failure.', [
            'request_url' => 'https://commons.wikimedia.org/w/api.php?action=query',
            'duration_ms' => 425,
            'http_status' => 200,
            'response_bytes' => 1024,
            'transport_error' => '0',
            'failure_type' => 'connection_failure',
            'error' => '0',
        ]);

        $this->get(route('image-finder.batches.show', $batch->id))
            ->assertOk()
            ->assertSee('درخواست HTTP 200 با موفقیت پاسخ داد.')
            ->assertDontSee('connection_failure: 0')
            ->assertDontSee('Provider request failed');
    }

    public function test_candidate_can_be_approved_without_an_account(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['images_per_product' => 1, 'min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'Needs review', 'requested_count' => 1, 'status' => 'needs_review']);
        $path = 'product-images/'.$batch->id.'/'.$task->id.'/candidate.webp';
        Storage::disk('local')->put($path, 'webp');
        $candidate = $store->createCandidate($task->id, [
            'source_name' => 'Search provider',
            'source_url' => 'https://images.example.test/candidate.webp',
            'title' => 'Product image',
            'confidence_score' => 0.75,
            'verification_status' => 'needs_review',
            'local_path' => $path,
            'metadata' => ['storage_disk' => 'local'],
        ]);

        $this->post(route('image-finder.candidates.approve', $candidate->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('verified', $store->candidate($candidate->id)->verification_status);
        $this->assertTrue($store->candidate($candidate->id)->metadata['manual_approval']);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertTrue(Storage::disk('public')->exists($store->candidate($candidate->id)->local_path));
    }

    public function test_batch_can_export_json_and_a_zip_archive(): void
    {
        Storage::fake('public');
        $store = app(FileImageFinderStore::class);
        $batch = $store->createBatch(['images_per_product' => 1, 'min_width' => 800]);
        $task = $store->createTask($batch->id, ['name' => 'Test product', 'requested_count' => 1, 'status' => 'verified']);
        $path = 'product-images/'.$batch->id.'/'.$task->id.'/image.webp';
        Storage::disk('public')->put($path, 'webp');
        $store->createCandidate($task->id, [
            'source_name' => 'Wikimedia Commons',
            'source_url' => 'https://commons.wikimedia.org/image.webp',
            'source_page_url' => 'javascript:alert(1)',
            'license' => 'CC0',
            'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'license_status' => 'verified_open',
            'title' => '<script>alert("x")</script>',
            'confidence_score' => 0.9,
            'verification_status' => 'verified',
            'local_path' => $path,
            'width' => 1200,
            'height' => 1200,
            'metadata' => ['storage_disk' => 'public'],
        ]);
        $reviewPath = 'product-images/'.$batch->id.'/'.$task->id.'/review-image.webp';
        Storage::disk('public')->put($reviewPath, 'webp');
        $store->createCandidate($task->id, [
            'source_name' => 'Wikimedia Commons',
            'source_url' => 'https://commons.wikimedia.org/review-image.webp',
            'title' => 'Review image',
            'confidence_score' => 0.6,
            'verification_status' => 'needs_review',
            'local_path' => $reviewPath,
            'metadata' => ['storage_disk' => 'public'],
        ]);

        $this->get(route('image-finder.batches.show', $batch->id))
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('href="javascript:', false)
            ->assertSee('دانلود عکس‌ها (2)');
        $this->get(route('image-finder.batches.export', $batch->id))
            ->assertOk()
            ->assertJsonPath('products.0.verified_images', 1)
            ->assertJsonPath('products.0.downloaded_images', 2)
            ->assertJsonPath('products.0.images.0.source_url', 'https://commons.wikimedia.org/image.webp')
            ->assertJsonPath('products.0.images.0.license_status', 'verified_open')
            ->assertJsonPath('products.0.images.0.confidence_score', 0.9);

        $response = $this->get(route('image-finder.batches.archive', $batch->id));
        $response->assertOk();
        $this->assertStringContainsString('application/zip', $response->headers->get('content-type'));
        $temporaryArchive = tempnam(sys_get_temp_dir(), 'archive-check-');
        file_put_contents($temporaryArchive, $response->streamedContent());
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($temporaryArchive) === true);
        $this->assertNotFalse($archive->locateName('products.json'));
        $this->assertNotFalse($archive->locateName($task->id.'/image-2.webp'));
        $archive->close();
        unlink($temporaryArchive);
    }

    public function test_sessions_cache_and_queue_default_to_file_or_sync_drivers(): void
    {
        $this->assertNotSame('database', config('session.driver'));
        $this->assertNotSame('database', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame([], glob(base_path('database/migrations/*.php')) ?: []);
    }
}

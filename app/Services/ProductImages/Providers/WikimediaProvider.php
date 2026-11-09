<?php

namespace App\Services\ProductImages\Providers;

use App\Services\ProductImages\Contracts\ImageSearchProvider;
use App\Services\ProductImages\ImageFinderHttpClient;
use App\Services\ProductImages\ReportsImageProviderRequestTelemetry;
use Illuminate\Support\Facades\Log;
use Throwable;

class WikimediaProvider implements ImageSearchProvider, ReportsImageProviderRequestTelemetry
{
    public function __construct(private readonly ImageFinderHttpClient $http) {}

    public function beginRequestTelemetry(): void
    {
        $this->http->beginRequestTelemetry();
    }

    public function takeRequestTelemetry(): array
    {
        return $this->http->takeRequestTelemetry();
    }

    public function recordRequestFailure(Throwable $exception): void
    {
        $this->http->recordRequestFailure($exception);
    }

    public function name(): string
    {
        return 'Wikimedia Commons';
    }

    public function search(string $query): array
    {
        $response = $this->http->request()
            ->acceptJson()
            ->withUserAgent('ProductImageFinder/1.0 ('.config('app.url').')')
            ->get('https://commons.wikimedia.org/w/api.php', [
                'action' => 'query',
                'format' => 'json',
                'formatversion' => 2,
                'generator' => 'search',
                'gsrsearch' => $query.' filetype:bitmap',
                'gsrnamespace' => 6,
                'gsrlimit' => 20,
                'prop' => 'imageinfo',
                'iiprop' => 'url|size|mime|extmetadata',
                'iiurlwidth' => 1200,
            ]);

        if (! $response->successful()) {
            Log::warning('Wikimedia Commons search returned an HTTP error.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new \RuntimeException('Wikimedia Commons search failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();

        if (is_array($payload) && isset($payload['error'])) {
            $apiErrorCode = is_string($payload['error']['code'] ?? null)
                ? substr(preg_replace('/[^a-z0-9_-]/i', '', $payload['error']['code']) ?? '', 0, 80)
                : 'unknown';
            Log::warning('Wikimedia Commons search returned an API error.', [
                'http_status' => $response->status(),
                'api_error' => $apiErrorCode,
                'query' => $query,
            ]);

            throw new \RuntimeException('Wikimedia Commons API error '.$apiErrorCode.' with HTTP '.$response->status().'.');
        }

        if (! is_array($payload)) {
            Log::warning('Wikimedia Commons search returned a malformed response.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new \RuntimeException('Wikimedia Commons search returned a malformed response with HTTP '.$response->status().'.');
        }

        if (! array_key_exists('query', $payload)) {
            if (($payload['batchcomplete'] ?? false) === true) {
                return [];
            }

            Log::warning('Wikimedia Commons search returned a malformed response.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new \RuntimeException('Wikimedia Commons search returned a malformed response with HTTP '.$response->status().'.');
        }

        if (! is_array($payload['query'])) {
            Log::warning('Wikimedia Commons search returned a malformed response.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new \RuntimeException('Wikimedia Commons search returned a malformed response with HTTP '.$response->status().'.');
        }
        $pages = $payload['query']['pages'] ?? [];

        if (! is_array($pages)) {
            Log::warning('Wikimedia Commons search returned malformed page results.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new \RuntimeException('Wikimedia Commons search returned malformed page results with HTTP '.$response->status().'.');
        }

        $results = [];

        foreach ($pages as $page) {
            $info = is_array($page) && is_array($page['imageinfo'] ?? null)
                ? ($page['imageinfo'][0] ?? [])
                : [];
            $info = is_array($info) ? $info : [];
            $metadata = is_array($info['extmetadata'] ?? null) ? $info['extmetadata'] : [];
            $license = $this->metadataValue($metadata, 'LicenseShortName');
            $licenseUrl = $this->metadataValue($metadata, 'LicenseUrl');
            $thumbnailUrl = $info['thumburl'] ?? null;
            $originalUrl = $info['url'] ?? null;
            $url = $this->isDirectImageUrl($thumbnailUrl) ? $thumbnailUrl : $originalUrl;

            $results[] = [
                'url' => $this->isDirectImageUrl($url) ? $url : null,
                'thumbnail_url' => $this->isDirectImageUrl($thumbnailUrl) ? $thumbnailUrl : null,
                'original_url' => $this->isDirectImageUrl($originalUrl) ? $originalUrl : null,
                'title' => (string) ($page['title'] ?? ''),
                'source_page_url' => isset($page['title'])
                    ? 'https://commons.wikimedia.org/wiki/'.rawurlencode((string) $page['title'])
                    : null,
                'license' => $license !== '' ? $license : null,
                'license_url' => $licenseUrl !== '' ? $licenseUrl : null,
                'attribution' => $this->metadataValue($metadata, 'Artist')
                    ?: ($this->metadataValue($metadata, 'Credit') ?: null),
                'width' => isset($info['width']) ? (int) $info['width'] : null,
                'height' => isset($info['height']) ? (int) $info['height'] : null,
                'mime' => is_string($info['mime'] ?? null) ? strtolower($info['mime']) : null,
                'file_size' => isset($info['size']) ? (int) $info['size'] : null,
                'is_image_file' => ! isset($page['ns']) || (int) $page['ns'] === 6,
                'source_name' => 'Wikimedia Commons',
            ];
        }

        return $results;
    }

    private function metadataValue(array $metadata, string $key): string
    {
        $value = $metadata[$key]['value'] ?? '';

        return is_string($value) ? trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5)) : '';
    }

    private function isDirectImageUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && strtolower((string) $parts['host']) !== 'commons.wikimedia.org';
    }
}

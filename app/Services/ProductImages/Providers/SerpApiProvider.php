<?php

namespace App\Services\ProductImages\Providers;

use App\Services\ProductImages\Contracts\ImageSearchProvider;
use App\Services\ProductImages\ImageFinderHttpClient;
use App\Services\ProductImages\ReportsImageProviderRequestTelemetry;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SerpApiProvider implements ImageSearchProvider, ReportsImageProviderRequestTelemetry
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
        return 'SerpApi Google Images';
    }

    public function search(string $query): array
    {
        $response = $this->http->request()
            ->acceptJson()
            ->get('https://serpapi.com/search.json', [
                'engine' => 'google_images',
                'q' => $query,
                'hl' => config('image_finder.language', 'en'),
                'api_key' => config('image_finder.providers.serpapi.api_key'),
                'ijn' => 0,
            ]);

        if (! $response->successful()) {
            Log::warning('SerpApi image search returned an HTTP error.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new RuntimeException('SerpApi image search failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload) || isset($payload['error']) || ! is_array($payload['images_results'] ?? null)) {
            Log::warning('SerpApi image search returned a malformed or failed response.', [
                'http_status' => $response->status(),
                'has_api_error' => isset($payload['error']),
                'query' => $query,
            ]);

            throw new RuntimeException('SerpApi image search returned a malformed or failed response with HTTP '.$response->status().'.');
        }

        $results = [];

        foreach ($payload['images_results'] as $item) {
            if (! is_array($item)) {
                $results[] = [
                    'url' => null,
                    'source_name' => 'SerpApi Google Images',
                    'rejection_reason' => 'missing_direct_image_url',
                ];

                continue;
            }

            $url = $item['original'] ?? $item['image'] ?? null;

            $results[] = [
                'url' => $url,
                'thumbnail_url' => $item['thumbnail'] ?? null,
                'title' => (string) ($item['title'] ?? ''),
                'source_page_url' => $item['link'] ?? null,
                'license' => null,
                'license_url' => null,
                'attribution' => $item['source'] ?? null,
                'width' => isset($item['original_width']) ? (int) $item['original_width'] : null,
                'height' => isset($item['original_height']) ? (int) $item['original_height'] : null,
                'mime' => is_string($item['mime'] ?? null) ? strtolower($item['mime']) : null,
                'source_name' => 'SerpApi Google Images',
            ];
        }

        return $results;
    }
}

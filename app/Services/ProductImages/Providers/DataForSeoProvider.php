<?php

namespace App\Services\ProductImages\Providers;

use App\Services\ProductImages\Contracts\ImageSearchProvider;
use App\Services\ProductImages\ImageFinderHttpClient;
use App\Services\ProductImages\ReportsImageProviderRequestTelemetry;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DataForSeoProvider implements ImageSearchProvider, ReportsImageProviderRequestTelemetry
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
        return 'DataForSEO Google Images';
    }

    public function search(string $query): array
    {
        $response = $this->http->request()
            ->acceptJson()
            ->withBasicAuth(
                config('image_finder.providers.dataforseo.login'),
                config('image_finder.providers.dataforseo.password'),
            )
            ->post('https://api.dataforseo.com/v3/serp/google/images/live/advanced', [[
                'keyword' => $query,
                'language_code' => config('image_finder.language', 'en'),
                'location_code' => 2840,
            ]]);

        if (! $response->successful()) {
            Log::warning('DataForSEO image search returned an HTTP error.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new RuntimeException('DataForSEO image search failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['tasks'] ?? null)) {
            Log::warning('DataForSEO image search returned a malformed response.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new RuntimeException('DataForSEO image search returned a malformed response with HTTP '.$response->status().'.');
        }

        $task = $payload['tasks'][0] ?? null;

        if (! is_array($task) || (int) ($task['status_code'] ?? 0) !== 20000) {
            Log::warning('DataForSEO image search returned an API error.', [
                'http_status' => $response->status(),
                'api_status_code' => $task['status_code'] ?? null,
                'query' => $query,
            ]);

            throw new RuntimeException('DataForSEO image search returned an API error with HTTP '.$response->status().'.');
        }

        $items = $task['result'][0]['items'] ?? [];

        if (! is_array($items)) {
            Log::warning('DataForSEO image search returned malformed result items.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new RuntimeException('DataForSEO image search returned malformed result items with HTTP '.$response->status().'.');
        }

        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                $results[] = [
                    'url' => null,
                    'source_name' => 'DataForSEO Google Images',
                    'rejection_reason' => 'missing_direct_image_url',
                ];

                continue;
            }

            $results[] = [
                'url' => $item['image_url'] ?? null,
                'thumbnail_url' => $item['thumbnail_url'] ?? null,
                'title' => (string) ($item['title'] ?? ''),
                'source_page_url' => $item['source_url'] ?? null,
                'license' => null,
                'license_url' => null,
                'attribution' => $item['domain'] ?? null,
                'width' => isset($item['image_width']) ? (int) $item['image_width'] : null,
                'height' => isset($item['image_height']) ? (int) $item['image_height'] : null,
                'mime' => is_string($item['mime_type'] ?? null) ? strtolower($item['mime_type']) : null,
                'source_name' => 'DataForSEO Google Images',
            ];
        }

        return $results;
    }
}

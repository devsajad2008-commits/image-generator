<?php

namespace App\Services\ProductImages\Providers;

use App\Services\ProductImages\Contracts\ImageSearchProvider;
use App\Services\ProductImages\ImageFinderHttpClient;
use App\Services\ProductImages\ReportsImageProviderRequestTelemetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenverseProvider implements ImageSearchProvider, ReportsImageProviderRequestTelemetry
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
        return 'Openverse';
    }

    public function search(string $query): array
    {
        $token = Cache::remember('image-finder:openverse-token', now()->addMinutes(45), function (): ?string {
            $clientId = config('image_finder.providers.openverse.client_id');
            $clientSecret = config('image_finder.providers.openverse.client_secret');

            if (! $clientId || ! $clientSecret) {
                return null;
            }

            $response = $this->http->request()
                ->asForm()
                ->post('https://api.openverse.org/v1/auth_tokens/token/', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (! $response->successful()) {
                Log::warning('Openverse authentication returned an HTTP error.', [
                    'http_status' => $response->status(),
                ]);

                throw new RuntimeException('Openverse authentication failed with HTTP '.$response->status().'.');
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                Log::warning('Openverse authentication returned a malformed response.', [
                    'http_status' => $response->status(),
                ]);

                throw new RuntimeException('Openverse authentication response did not contain an access token with HTTP '.$response->status().'.');
            }

            return $token;
        });

        $request = $this->http->request()->acceptJson();

        if ($token) {
            $request = $request->withToken($token);
        }

        $response = $request->get('https://api.openverse.org/v1/images/', [
            'q' => $query,
            'page_size' => 20,
        ]);

        if (! $response->successful()) {
            Log::warning('Openverse image search returned an HTTP error.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new RuntimeException('Openverse search failed with HTTP '.$response->status().'.');
        }

        $items = $response->json('results');

        if (! is_array($items)) {
            Log::warning('Openverse image search returned a malformed response.', [
                'http_status' => $response->status(),
                'query' => $query,
            ]);

            throw new RuntimeException('Openverse image search returned a malformed response with HTTP '.$response->status().'.');
        }

        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                $results[] = [
                    'url' => null,
                    'source_name' => 'Openverse',
                    'rejection_reason' => 'missing_direct_image_url',
                ];

                continue;
            }

            $results[] = [
                'url' => $item['url'] ?? null,
                'thumbnail_url' => $item['thumbnail'] ?? null,
                'title' => (string) ($item['title'] ?? ''),
                'source_page_url' => $item['foreign_landing_url'] ?? null,
                'license' => is_string($item['license'] ?? null) ? strtoupper($item['license']) : null,
                'license_url' => $item['license_url'] ?? null,
                'attribution' => $item['creator'] ?? null,
                'width' => isset($item['width']) ? (int) $item['width'] : null,
                'height' => isset($item['height']) ? (int) $item['height'] : null,
                'mime' => is_string($item['mime_type'] ?? null) ? strtolower($item['mime_type']) : null,
                'source_name' => 'Openverse',
            ];
        }

        return $results;
    }
}

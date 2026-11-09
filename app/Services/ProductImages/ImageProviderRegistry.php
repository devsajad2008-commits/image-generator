<?php

namespace App\Services\ProductImages;

use App\Services\ProductImages\Contracts\ImageSearchProvider;
use App\Services\ProductImages\Providers\DataForSeoProvider;
use App\Services\ProductImages\Providers\OpenverseProvider;
use App\Services\ProductImages\Providers\SerpApiProvider;
use App\Services\ProductImages\Providers\WikimediaProvider;

class ImageProviderRegistry
{
    /**
     * @return list<array{key: string, name: string, enabled: bool, credentials_present: bool, status: string}>
     */
    public function statuses(): array
    {
        $providers = [
            'wikimedia' => ['class' => WikimediaProvider::class, 'name' => 'Wikimedia Commons'],
            'openverse' => ['class' => OpenverseProvider::class, 'name' => 'Openverse'],
            'serpapi' => ['class' => SerpApiProvider::class, 'name' => 'SerpApi Google Images'],
            'dataforseo' => ['class' => DataForSeoProvider::class, 'name' => 'DataForSEO Google Images'],
        ];
        $statuses = [];

        foreach ($providers as $key => $definition) {
            $settings = config('image_finder.providers.'.$key, []);
            $enabled = (bool) ($settings['enabled'] ?? false);
            $credentialsPresent = $this->hasCredentials($key, $settings);
            $status = ! $enabled
                ? 'disabled'
                : ($credentialsPresent
                    ? 'ready'
                    : ($key === 'wikimedia'
                        ? 'public'
                        : ($key === 'openverse' ? 'anonymous' : 'missing_credentials')));

            $statuses[] = [
                'key' => $key,
                'name' => $definition['name'],
                'enabled' => $enabled,
                'credentials_present' => $credentialsPresent,
                'status' => $status,
            ];
        }

        return $statuses;
    }

    /**
     * @return list<ImageSearchProvider>
     */
    public function configured(): array
    {
        $configured = [];

        foreach ($this->statuses() as $status) {
            if (! in_array($status['status'], ['ready', 'public', 'anonymous'], true)) {
                continue;
            }

            $settings = config('image_finder.providers.'.$status['key'], []);

            $configured[] = [
                'priority' => $settings['priority'] ?? 100,
                'provider' => app(match ($status['key']) {
                    'wikimedia' => WikimediaProvider::class,
                    'openverse' => OpenverseProvider::class,
                    'serpapi' => SerpApiProvider::class,
                    'dataforseo' => DataForSeoProvider::class,
                }),
            ];
        }

        usort($configured, fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return array_column($configured, 'provider');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function hasCredentials(string $provider, array $settings): bool
    {
        return match ($provider) {
            'wikimedia' => false,
            'openverse' => filled($settings['client_id'] ?? null) && filled($settings['client_secret'] ?? null),
            'serpapi' => filled($settings['api_key'] ?? null),
            'dataforseo' => filled($settings['login'] ?? null) && filled($settings['password'] ?? null),
            default => false,
        };
    }
}

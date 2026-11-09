<?php

namespace App\Services\ProductImages;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RobotsPolicy
{
    public function __construct(private readonly ImageFinderHttpClient $http) {}

    public function allows(string $url, string $resolvedAddress): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $rules = Cache::remember('image-finder:robots:'.sha1($host), now()->addMinutes(30), function () use ($host, $resolvedAddress): array|false {
            $temporaryPath = tempnam(sys_get_temp_dir(), 'product-robots-');

            if ($temporaryPath === false) {
                return false;
            }

            try {
                $hostForUrl = str_contains($host, ':') ? '['.$host.']' : $host;
                $response = $this->http->request()
                    ->withUserAgent('ProductImageFinder/1.0 ('.config('app.url').')')
                    ->withOptions([
                        'allow_redirects' => false,
                        'sink' => $temporaryPath,
                        'progress' => function (int $downloadSize, int $downloaded): void {
                            if ($downloaded > 524288) {
                                throw new \RuntimeException('Robots file exceeds the supported size limit.');
                            }
                        },
                        'curl' => [CURLOPT_RESOLVE => [$hostForUrl.':443:'.(str_contains($resolvedAddress, ':') ? '['.$resolvedAddress.']' : $resolvedAddress)]],
                    ])
                    ->get('https://'.$hostForUrl.'/robots.txt');

                if (in_array($response->status(), [404, 410], true)) {
                    return [];
                }

                $size = filesize($temporaryPath);

                if (! $response->successful() || $size === false || $size > 524288) {
                    Log::warning('Robots policy endpoint returned an unusable response.', [
                        'host' => $host,
                        'http_status' => $response->status(),
                    ]);

                    return false;
                }

                $content = file_get_contents($temporaryPath);

                return is_string($content) ? $this->parse($content) : null;
            } catch (Throwable $exception) {
                Log::warning('Robots policy could not be checked; image download denied.', [
                    'host' => $host,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                return false;
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        });

        if ($rules === false) {
            return false;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $matches = array_values(array_filter($rules, function (array $rule) use ($path): bool {
            $anchored = str_ends_with($rule['path'], '$');
            $pattern = preg_quote($anchored ? substr($rule['path'], 0, -1) : $rule['path'], '#');
            $pattern = str_replace('\*', '.*', $pattern);

            return preg_match('#^'.$pattern.($anchored ? '$' : '').'#', $path) === 1;
        }));

        if ($matches === []) {
            return true;
        }

        usort($matches, fn (array $left, array $right): int => ($right['length'] <=> $left['length'])
            ?: ((int) $right['allow'] <=> (int) $left['allow']));

        return $matches[0]['allow'];
    }

    /**
     * @return list<array{path: string, allow: bool, length: int}>
     */
    private function parse(string $content): array
    {
        $groups = [];
        $agents = [];
        $rules = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if (! str_contains($line, ':')) {
                continue;
            }

            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = strtolower($directive);
            $value = trim($value);

            if ($directive === 'user-agent') {
                if ($rules !== []) {
                    $groups[] = ['agents' => $agents, 'rules' => $rules];
                    $agents = [];
                    $rules = [];
                }

                $agents[] = strtolower($value);

                continue;
            }

            if (in_array($directive, ['allow', 'disallow'], true) && $agents !== [] && $value !== '') {
                if (strlen($value) > 4096) {
                    continue;
                }

                $rules[] = ['path' => $value, 'allow' => $directive === 'allow'];
            }
        }

        if ($agents !== []) {
            $groups[] = ['agents' => $agents, 'rules' => $rules];
        }

        $agentName = 'productimagefinder';
        $specificity = -1;
        $selected = [];

        foreach ($groups as $group) {
            foreach ($group['agents'] as $agent) {
                $matchedSpecificity = $agent === '*' ? 0 : (str_contains($agentName, $agent) ? strlen($agent) : -1);

                if ($matchedSpecificity > $specificity) {
                    $specificity = $matchedSpecificity;
                    $selected = $group['rules'];
                } elseif ($matchedSpecificity === $specificity && $matchedSpecificity >= 0) {
                    $selected = array_merge($selected, $group['rules']);
                }
            }
        }

        return array_map(fn (array $rule): array => [
            ...$rule,
            'length' => strlen(str_replace('*', '', $rule['path'])),
        ], $selected);
    }
}

<?php

namespace App\Services\ProductImages;

use App\Models\ProductImageCandidate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImageDownloadService
{
    public function __construct(
        private readonly ImageProcessingService $processing,
        private readonly RobotsPolicy $robots,
        private readonly ImageFinderHttpClient $http,
    ) {}

    /**
     * Download and validate a candidate, then persist a WebP copy.
     *
     * @return array{path: string, disk: string, width: int, height: int, hash: string}
     */
    public function downloadAndProcess(ProductImageCandidate|array $candidate, string $disk, string $directory): array
    {
        $url = $candidate instanceof ProductImageCandidate ? $candidate->source_url : $candidate['source_url'];
        $target = $this->publicAddress($url);
        if (! $this->robots->allows($url, $target)) {
            throw new ImageDownloadException('Source robots policy does not permit image retrieval.', false);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'product-image-');

        if ($temporaryPath === false) {
            throw new ImageDownloadException('Unable to allocate a temporary image file.', false);
        }

        $downloaded = false;

        try {
            $parsed = parse_url($url);
            $host = $parsed['host'];
            $port = (int) ($parsed['port'] ?? 443);
            $resolveHost = str_contains($host, ':') ? '['.$host.']' : $host;
            $resolveAddress = str_contains($target, ':') ? '['.$target.']' : $target;

            $response = $this->http->request()
                ->withUserAgent('ProductImageFinder/1.0 ('.config('app.url').')')
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $temporaryPath,
                    'progress' => function (int $downloadSize, int $downloaded): void {
                        if ($downloaded > config('image_finder.max_file_size')) {
                            throw new RuntimeException('Image download exceeded the configured file-size limit.');
                        }
                    },
                    'curl' => [CURLOPT_RESOLVE => [$resolveHost.':'.$port.':'.$resolveAddress]],
                ])
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('Image download returned HTTP '.$response->status().'.');
            }

            $downloaded = true;
            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if (! in_array($contentType, [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'application/octet-stream',
            ], true)) {
                throw new RuntimeException('Image response has an unsupported MIME type.');
            }

            clearstatcache(true, $temporaryPath);
            $size = filesize($temporaryPath);

            if (! $size || $size > config('image_finder.max_file_size')) {
                throw new RuntimeException('Image is empty or exceeds the configured file-size limit.');
            }

            return $this->processing->process($temporaryPath, $disk, $directory);
        } catch (Throwable $exception) {
            if ($exception instanceof ImageDownloadException) {
                throw $exception;
            }

            if ($exception instanceof ConnectionException) {
                throw new ImageDownloadException(
                    'Image download failed because the image server could not be reached.',
                    $downloaded,
                    $exception,
                );
            }

            throw new ImageDownloadException(
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Image could not be safely decoded or processed.',
                $downloaded,
                $exception,
            );
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

        }
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    private function publicAddress(string $url): string
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Only public HTTPS image URLs are allowed.');
        }

        if ((int) ($parts['port'] ?? 443) !== 443) {
            throw new RuntimeException('Only standard HTTPS image endpoints are allowed.');
        }

        $host = strtolower($parts['host']);

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('Local network image URLs are not allowed.');
        }

        $records = filter_var($host, FILTER_VALIDATE_IP) ? [] : (dns_get_record($host, DNS_A | DNS_AAAA) ?: []);
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_unique(array_filter(array_merge(
                array_column($records, 'ip'),
                array_column($records, 'ipv6'),
            ))));

        if ($addresses === []) {
            throw new RuntimeException('Image hostname could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Image hostname resolves to a non-public network address.');
            }
        }

        return $addresses[0];
    }
}

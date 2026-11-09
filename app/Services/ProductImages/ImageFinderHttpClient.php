<?php

namespace App\Services\ProductImages;

use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ImageFinderHttpClient
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $requestTelemetry = [];

    public function beginRequestTelemetry(): void
    {
        $this->requestTelemetry = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function takeRequestTelemetry(): array
    {
        $telemetry = $this->requestTelemetry;
        $this->requestTelemetry = [];

        return $telemetry;
    }

    public function recordRequestFailure(Throwable $exception): void
    {
        $lastIndex = array_key_last($this->requestTelemetry);
        if ($lastIndex === null) {
            return;
        }

        $status = $this->httpStatusFromException($exception);
        if ($status !== null) {
            $this->requestTelemetry[$lastIndex]['http_status'] = $status;
        }
        $this->requestTelemetry[$lastIndex]['exception_class'] = $exception::class;
        $this->requestTelemetry[$lastIndex]['error'] = $this->sanitizeError($exception->getMessage());
        $this->requestTelemetry[$lastIndex]['failure_type'] = $this->failureType($exception, $status);
    }

    public function request(): PendingRequest
    {
        $timeout = max(1, (int) config('image_finder.timeout'));

        return Http::connectTimeout(min(10, $timeout))
            ->timeout($timeout)
            ->withOptions([
                'on_stats' => function (TransferStats $stats): void {
                    $this->captureTransfer($stats);
                },
            ])
            ->retry(
                max(1, (int) config('image_finder.max_retries')),
                fn (int $attempt, mixed $exception): int => $this->retryDelay($attempt, $exception),
                fn (Throwable $exception): bool => $this->shouldRetry($exception),
                false,
            );
    }

    private function captureTransfer(TransferStats $stats): void
    {
        $request = $stats->getRequest();
        $response = $stats->getResponse();
        $handlerStats = $stats->getHandlerStats();
        $duration = max(0, (int) round(((float) ($handlerStats['total_time'] ?? 0)) * 1000));
        $url = $request->getUri()->__toString();
        $status = $response?->getStatusCode() ?? (int) ($handlerStats['http_code'] ?? 0);
        $handlerError = $stats->getHandlerErrorData();
        $hasHandlerError = ! in_array($handlerError, [null, false, 0, '0', ''], true);
        $handlerErrorMessage = $hasHandlerError
            ? $this->sanitizeError(is_scalar($handlerError) ? (string) $handlerError : (json_encode($handlerError) ?: 'Transport error'))
            : null;

        $this->requestTelemetry[] = [
            'request_url' => $this->sanitizeUrl($url),
            'request_method' => $request->getMethod(),
            'request_started_at' => now()->subMilliseconds($duration)->toIso8601String(),
            'duration_ms' => $duration,
            'http_status' => $status > 0 ? $status : null,
            'response_bytes' => max(0, (int) ($handlerStats['size_download'] ?? $response?->getBody()->getSize() ?? 0)),
            'transport_error' => $handlerErrorMessage,
            'tls_verify_result' => isset($handlerStats['ssl_verify_result']) ? (int) $handlerStats['ssl_verify_result'] : null,
            'exception_class' => null,
            'error' => $status >= 400
                ? 'HTTP '.$status.' response.'
                : $handlerErrorMessage,
            'failure_type' => $this->responseFailureType($status, $hasHandlerError ? $handlerError : null),
            'attempt' => count($this->requestTelemetry) + 1,
        ];
    }

    private function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return Str::limit($url, 2048, '');
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/api[_-]?key|secret|token|password|authorization/i', (string) $key) === 1) {
                $query[$key] = '[REDACTED]';
            }
        }

        return Str::limit(
            ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
                .(isset($parts['port']) ? ':'.$parts['port'] : '')
                .($parts['path'] ?? '')
                .($query !== [] ? '?'.http_build_query($query) : ''),
            2048,
            '',
        );
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(https?:\/\/[^\s?]+)\?[^\s]+/i', '$1?[REDACTED]', $message) ?? $message;
        $message = preg_replace('/\b(api[_-]?key|client[_-]?secret|password|token)\b\s*[:=]\s*\S+/i', '$1=[REDACTED]', $message) ?? $message;

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 500, '');
    }

    private function responseFailureType(int $status, mixed $handlerError): ?string
    {
        if ($status === 429) {
            return 'http_429_rate_limited';
        }
        if ($status === 403) {
            return 'http_403_access_denied';
        }
        if ($status === 401) {
            return 'http_401_authentication_failed';
        }
        if ($status >= 500) {
            return 'http_5xx_server_error';
        }

        if ($status >= 400) {
            return 'http_error';
        }

        if ($handlerError !== null) {
            $message = strtolower(is_scalar($handlerError) ? (string) $handlerError : (json_encode($handlerError) ?: ''));
            if (str_contains($message, 'resolve') || str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service')) {
                return 'dns_failure';
            }
            if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) {
                return 'tls_failure';
            }
            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                return 'connection_timeout';
            }

            return 'connection_failure';
        }

        return null;
    }

    private function failureType(Throwable $exception, ?int $status): string
    {
        $responseFailureType = $this->responseFailureType($status ?? 0, null);
        if ($responseFailureType !== null) {
            return $responseFailureType;
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'resolve') || str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service')) {
            return 'dns_failure';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) {
            return 'tls_failure';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'connection_timeout';
        }
        if ($exception instanceof ConnectionException) {
            return 'connection_failure';
        }
        if (str_contains($message, 'json') || str_contains($message, 'malformed')) {
            return 'invalid_json';
        }

        return 'provider_api_error';
    }

    private function httpStatusFromException(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException) {
                return $current->response->status();
            }
            if (preg_match('/HTTP\s+(\d{3})/i', $current->getMessage(), $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->status() === 429 || $exception->response->serverError()));
    }

    private function retryDelay(int $attempt, mixed $exception): int
    {
        $baseDelay = max(1, (int) config('image_finder.retry_delay_ms'));
        $maximumDelay = max($baseDelay, (int) config('image_finder.retry_max_delay_ms'));
        $retryAfter = $exception instanceof RequestException
            ? $exception->response->header('Retry-After')
            : null;

        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            return min($maximumDelay, max(1, (int) $retryAfter * 1000));
        }

        return min($maximumDelay, $baseDelay * (2 ** max(0, $attempt - 1)));
    }
}

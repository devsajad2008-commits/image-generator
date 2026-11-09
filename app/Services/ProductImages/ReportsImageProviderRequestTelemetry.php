<?php

namespace App\Services\ProductImages;

use Throwable;

interface ReportsImageProviderRequestTelemetry
{
    public function beginRequestTelemetry(): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function takeRequestTelemetry(): array;

    public function recordRequestFailure(Throwable $exception): void;
}

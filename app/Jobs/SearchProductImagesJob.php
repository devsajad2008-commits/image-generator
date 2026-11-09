<?php

namespace App\Jobs;

use App\Services\ProductImages\FileImageFinderStore;
use App\Services\ProductImages\ImageDiscoveryManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class SearchProductImagesJob
{
    public function __construct(public readonly string $taskId) {}

    public function handle(FileImageFinderStore $store, ImageDiscoveryManager $discovery): void
    {
        $task = $store->task($this->taskId);
        $batch = $store->taskBatch($this->taskId);
        if (! $task || ! $batch || $batch->status === 'cancelled') {
            return;
        }
        config(['image_finder.min_width' => $batch->settings['min_width'] ?? config('image_finder.min_width')]);
        config(['image_finder.min_height' => $batch->settings['min_height'] ?? config('image_finder.min_height')]);
        config(['image_finder.format' => $batch->settings['image_format'] ?? config('image_finder.format')]);
        config(['image_finder.language' => $batch->settings['language'] ?? config('image_finder.language')]);
        $discovery->discover($task);
    }

    public function failed(FileImageFinderStore $store, Throwable $exception): void
    {
        $task = $store->task($this->taskId);
        $batch = $store->taskBatch($this->taskId);
        if (! $task || ! $batch) {
            return;
        }
        $attempts = (int) $task->attempts;
        $maximumAttempts = max(1, (int) config('image_finder.max_retries'));
        if ($attempts < $maximumAttempts) {
            $baseDelay = max(1, (int) config('image_finder.job_retry_delay_seconds'));
            $maximumDelay = max($baseDelay, (int) config('image_finder.job_retry_max_delay_seconds'));
            $delay = min($maximumDelay, $baseDelay * (2 ** max(0, $attempts - 1)));
            $store->updateTask($task->id, [
                'status' => 'queued',
                'available_at' => now()->addSeconds($delay)->toIso8601String(),
                'error' => 'در حال تلاش دوباره پس از خطای موقت.',
            ]);
            $store->activity($batch->id, $task->id, null, 'product_retry', 'تلاش دوباره تا '.now()->addSeconds($delay)->format('H:i:s').' برنامه‌ریزی شد.');

            return;
        }

        $message = 'جست‌وجوی محصول پس از تلاش‌های مجاز ناموفق ماند.';
        $store->updateTask($task->id, ['status' => 'failed', 'error' => $message]);
        $store->activity($batch->id, $task->id, null, 'product_failed', $message, ['exception' => $exception::class]);
        Log::error($message, ['task_id' => $task->id, 'exception' => $exception::class]);
        app(ImageDiscoveryManager::class)->refreshBatchStatus($batch);
    }
}

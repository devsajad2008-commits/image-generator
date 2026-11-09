<?php

namespace App\Console\Commands;

use App\Jobs\SearchProductImagesJob;
use App\Services\ProductImages\FileImageFinderStore;
use App\Services\ProductImages\ImageDiscoveryManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImageFinderWorkCommand extends Command
{
    protected $signature = 'image-finder:work {--once : Process available products and exit} {--sleep=3 : Seconds to wait when the queue is empty}';

    protected $description = 'Process queued product-image searches using file-based storage';

    public function handle(FileImageFinderStore $store, ImageDiscoveryManager $discovery): int
    {
        $applicationSignature = $this->applicationSignature();

        do {
            if ($applicationSignature !== $this->applicationSignature()) {
                Log::notice('Image finder worker detected changed application files and is exiting for a clean restart.');
                $this->warn('Application files changed; restart this worker to load the updated image-finder code.');

                return self::SUCCESS;
            }

            $claimed = $store->claimNextTask();
            if (! $claimed) {
                if ($this->option('once')) {
                    return self::SUCCESS;
                }
                sleep(max(1, (int) $this->option('sleep')));

                continue;
            }

            $task = $claimed['task'];
            $this->line('در حال جست‌وجو: '.$task->name);
            $job = new SearchProductImagesJob($task->id);
            try {
                $job->handle($store, $discovery);
            } catch (Throwable $exception) {
                $job->failed($store, $exception);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function applicationSignature(): string
    {
        $paths = array_map(
            fn (\SplFileInfo $file): string => $file->getPathname(),
            File::allFiles(app_path('Services/ProductImages')),
        );
        $paths[] = app_path('Jobs/SearchProductImagesJob.php');
        $paths[] = config_path('image_finder.php');
        sort($paths);

        $fileSignatures = array_map(
            fn (string $path): string => $path.':'.(is_file($path) ? (string) filemtime($path) : 'missing'),
            $paths,
        );

        return hash('sha256', implode('|', $fileSignatures));
    }
}

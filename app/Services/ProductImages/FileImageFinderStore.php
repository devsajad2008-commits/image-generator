<?php

namespace App\Services\ProductImages;

use App\Models\ImageSearchActivity;
use App\Models\ImageSearchBatch;
use App\Models\ProductImageCandidate;
use App\Models\ProductImageTask;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

class FileImageFinderStore
{
    private string $directory;

    public function __construct()
    {
        $this->directory = config('image_finder.data_path', storage_path('app/private/image-finder/batches'));
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the image-finder storage directory.');
        }
    }

    public function createBatch(array $settings, ?string $ownerId = null): ImageSearchBatch
    {
        $id = (string) Str::uuid();
        $now = now()->toIso8601String();
        $data = [
            'batch' => [
                'id' => $id,
                'status' => 'queued',
                'owner_id' => $ownerId,
                'settings' => $settings,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'tasks' => [],
            'candidates' => [],
            'activities' => [],
        ];
        $this->write($id, $data);

        return new ImageSearchBatch($data['batch']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTask(string $batchId, array $attributes): ProductImageTask
    {
        $id = (string) Str::uuid();
        $task = array_merge($attributes, [
            'id' => $id,
            'image_search_batch_id' => $batchId,
            'status' => 'queued',
            'attempts' => 0,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
        $this->mutate($batchId, function (array &$data) use ($id, $task): void {
            $data['tasks'][$id] = $task;
        });

        return new ProductImageTask($task);
    }

    public function batch(string $id): ?ImageSearchBatch
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $data = $this->read($id);

        return $data ? new ImageSearchBatch($data['batch']) : null;
    }

    public function deleteBatch(string $id): bool
    {
        $path = $this->path($id);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to lock image-finder batch storage.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock image-finder batch storage.');
            }
            if (! is_file($path)) {
                return false;
            }
            if (! unlink($path)) {
                throw new RuntimeException('Unable to delete image-finder batch storage.');
            }

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return list<ImageSearchBatch>
     */
    public function batches(int $limit = 20, ?string $ownerId = null): array
    {
        $files = glob($this->directory.'/*.json') ?: [];
        usort($files, fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        $batches = array_values(array_filter(array_map(function (string $file): ?ImageSearchBatch {
            $data = $this->decodeFile($file);

            return $data ? new ImageSearchBatch($data['batch']) : null;
        }, $files)));
        if ($ownerId !== null) {
            $batches = array_values(array_filter($batches, fn (ImageSearchBatch $batch): bool => $batch->owner_id === $ownerId));
        }

        return array_slice($batches, 0, $limit);
    }

    /**
     * @return list<ProductImageTask>
     */
    public function tasks(string $batchId): array
    {
        $data = $this->read($batchId);

        return array_map(fn (array $task): ProductImageTask => new ProductImageTask($task), array_values($data['tasks'] ?? []));
    }

    public function task(string $id): ?ProductImageTask
    {
        $found = $this->find($id, 'tasks');

        return $found ? new ProductImageTask($found[1]['tasks'][$id]) : null;
    }

    public function updateBatch(string $id, array $attributes): ?ImageSearchBatch
    {
        $data = $this->mutate($id, function (array &$data) use ($attributes): void {
            $data['batch'] = array_merge($data['batch'], $attributes, ['updated_at' => now()->toIso8601String()]);
        });

        return isset($data['data']) ? new ImageSearchBatch($data['data']['batch']) : null;
    }

    public function updateTask(string $id, array $attributes): ?ProductImageTask
    {
        $found = $this->find($id, 'tasks');
        if (! $found) {
            return null;
        }
        [$batchId] = $found;
        $data = $this->mutate($batchId, function (array &$data) use ($id, $attributes): void {
            $data['tasks'][$id] = array_merge($data['tasks'][$id], $attributes, ['updated_at' => now()->toIso8601String()]);
        });

        return isset($data['data']) ? new ProductImageTask($data['data']['tasks'][$id]) : null;
    }

    /**
     * Atomically claims the next queued product task.
     *
     * @return array{batch: ImageSearchBatch, task: ProductImageTask}|null
     */
    public function claimNextTask(): ?array
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            $batchId = basename($file, '.json');
            $claimed = $this->mutate($batchId, function (array &$data): ?string {
                if (($data['batch']['status'] ?? null) === 'cancelled') {
                    return null;
                }
                foreach ($data['tasks'] as $id => &$task) {
                    if ($task['status'] === 'queued'
                        && (! isset($task['available_at']) || strtotime($task['available_at']) <= now()->getTimestamp())) {
                        $task['status'] = 'processing';
                        $task['attempts'] = ($task['attempts'] ?? 0) + 1;
                        $task['updated_at'] = now()->toIso8601String();

                        return $id;
                    }
                }

                return null;
            });
            $taskId = $claimed['result'] ?? null;
            $data = $claimed['data'] ?? null;
            if ($taskId && $data) {
                return [
                    'batch' => new ImageSearchBatch($data['batch']),
                    'task' => new ProductImageTask($data['tasks'][$taskId]),
                ];
            }
        }

        return null;
    }

    /**
     * @return list<ProductImageCandidate>
     */
    public function candidates(string $taskId): array
    {
        $found = $this->find($taskId, 'tasks');
        if (! $found) {
            return [];
        }
        [, $data] = $found;

        return array_values(array_map(
            fn (array $candidate): ProductImageCandidate => new ProductImageCandidate($candidate),
            array_filter($data['candidates'] ?? [], fn (array $candidate): bool => $candidate['product_image_task_id'] === $taskId),
        ));
    }

    public function candidate(string $id): ?ProductImageCandidate
    {
        $found = $this->find($id, 'candidates');

        return $found ? new ProductImageCandidate($found[1]['candidates'][$id]) : null;
    }

    public function createCandidate(string $taskId, array $attributes): ProductImageCandidate
    {
        $found = $this->find($taskId, 'tasks');
        if (! $found) {
            throw new RuntimeException('Product image task was not found.');
        }
        [$batchId] = $found;
        $id = (string) Str::uuid();
        $candidate = array_merge($attributes, [
            'id' => $id,
            'product_image_task_id' => $taskId,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
        $this->mutate($batchId, function (array &$data) use ($id, $candidate): void {
            $data['candidates'][$id] = $candidate;
        });

        return new ProductImageCandidate($candidate);
    }

    public function updateCandidate(string $id, array $attributes): ?ProductImageCandidate
    {
        $found = $this->find($id, 'candidates');
        if (! $found) {
            return null;
        }
        [$batchId] = $found;
        $data = $this->mutate($batchId, function (array &$data) use ($id, $attributes): void {
            $data['candidates'][$id] = array_merge($data['candidates'][$id], $attributes, ['updated_at' => now()->toIso8601String()]);
        });

        return isset($data['data']) ? new ProductImageCandidate($data['data']['candidates'][$id]) : null;
    }

    /**
     * @return list<ImageSearchActivity>
     */
    public function activities(string $batchId, int $limit = 100): array
    {
        $data = $this->read($batchId);
        $activities = array_reverse($data['activities'] ?? []);

        return array_map(fn (array $activity): ImageSearchActivity => new ImageSearchActivity($activity), array_slice($activities, 0, $limit));
    }

    public function activity(string $batchId, ?string $taskId, ?string $provider, string $event, string $message, array $metadata = []): void
    {
        $this->mutate($batchId, function (array &$data) use ($taskId, $provider, $event, $message, $metadata): void {
            $data['activities'][] = [
                'id' => (string) Str::uuid(),
                'image_search_batch_id' => $data['batch']['id'],
                'product_image_task_id' => $taskId,
                'provider' => $provider,
                'event' => $event,
                'message' => $message,
                'metadata' => $metadata ?: null,
                'created_at' => now()->toIso8601String(),
            ];
        });
    }

    public function taskBatch(string $taskId): ?ImageSearchBatch
    {
        $found = $this->find($taskId, 'tasks');

        return $found ? new ImageSearchBatch($found[1]['batch']) : null;
    }

    public function batchIdForTask(string $taskId): ?string
    {
        $found = $this->find($taskId, 'tasks');

        return $found[0] ?? null;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function batchDataForTask(string $taskId): ?array
    {
        return $this->find($taskId, 'tasks');
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function batchDataForCandidate(string $candidateId): ?array
    {
        return $this->find($candidateId, 'candidates');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $id): ?array
    {
        return $this->decodeFile($this->path($id));
    }

    /**
     * @return array{data: array<string, mixed>|null, result: mixed}
     */
    private function mutate(string $id, Closure $callback): array
    {
        $path = $this->path($id);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to lock image-finder batch storage.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock image-finder batch storage.');
            }
            $data = $this->decodeFile($path);
            if (! $data) {
                return ['data' => null, 'result' => null];
            }
            $result = $callback($data);
            $this->writeUnlocked($path, $data);

            return ['data' => $data, 'result' => $result];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function write(string $id, array $data): void
    {
        $path = $this->path($id);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to lock image-finder batch storage.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock image-finder batch storage.');
            }
            $this->writeUnlocked($path, $data);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeUnlocked(string $path, array $data): void
    {
        $temporaryPath = tempnam($this->directory, 'batch-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate image-finder storage.');
        }
        try {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporaryPath, $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new RuntimeException('Unable to persist image-finder data.');
            }
            chmod($temporaryPath, 0640);
            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException('Unable to update image-finder storage.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function decodeFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Unable to read image-finder storage.');
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function find(string $recordId, string $collection): ?array
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            $data = $this->decodeFile($file);
            if (isset($data[$collection][$recordId])) {
                return [$data['batch']['id'], $data];
            }
        }

        return null;
    }

    private function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Invalid image-finder identifier.');
        }

        return $this->directory.DIRECTORY_SEPARATOR.$id.'.json';
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ImageSearchActivity;
use App\Models\ImageSearchBatch;
use App\Models\ProductImageCandidate;
use App\Models\ProductImageTask;
use App\Services\ProductImages\FileImageFinderStore;
use App\Services\ProductImages\ImageDiscoveryManager;
use App\Services\ProductImages\ImageProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class ProductImageController extends Controller
{
    public function __construct(
        private readonly FileImageFinderStore $store,
        private readonly ImageProviderRegistry $providers,
    ) {}

    public function index(): View
    {
        $batches = $this->store->batches(ownerId: request()->session()->getId());
        foreach ($batches as $batch) {
            $tasks = $this->store->tasks($batch->id);
            $batch->task_count = count($tasks);
            $batch->downloadable_count = $this->downloadableCount($tasks);
        }

        return view('product-images.index', compact('batches'));
    }

    public function batches(): View
    {
        $batches = $this->store->batches(PHP_INT_MAX, request()->session()->getId());
        $totalProducts = 0;
        $activeBatches = 0;
        $completedBatches = 0;

        foreach ($batches as $batch) {
            $tasks = $this->store->tasks($batch->id);
            $batch->task_count = count($tasks);
            $batch->downloadable_count = $this->downloadableCount($tasks);
            $totalProducts += $batch->task_count;
            $activeBatches += in_array($batch->status, ['queued', 'processing'], true) ? 1 : 0;
            $completedBatches += in_array($batch->status, ['completed', 'completed_with_warnings', 'verified', 'needs_review'], true) ? 1 : 0;
        }

        return view('product-images.batches', compact('batches', 'totalProducts', 'activeBatches', 'completedBatches'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('image_finder.enabled'), 404);
        $request->validate([
            'product_name' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_names' => ['nullable', 'string', 'max:200000'],
            'images_per_product' => ['nullable', 'integer', 'between:1,10'],
            'products_file' => ['nullable', 'file', 'mimes:json,txt', 'max:5120'],
        ]);
        $products = [];
        $settings = [
            'images_per_product' => (int) ($request->input('images_per_product') ?: config('image_finder.default_count')),
            'min_width' => (int) config('image_finder.min_width'),
            'min_height' => (int) config('image_finder.min_height'),
            'image_format' => 'webp',
            'preferred_format' => 'webp',
            'language' => config('image_finder.language'),
            'search_mode' => 'standard',
            'auto_approve_open_licensed' => true,
            'require_manual_review_for_unverified' => true,
            'allow_similar_products' => false,
            'download_images' => true,
            'deduplicate' => true,
            'generate_alternative_queries' => true,
            'download' => true,
            'verify_images' => true,
            'avoid_duplicates' => true,
        ];

        if ($request->hasFile('products_file')) {
            [$products, $settings] = $this->readJsonUpload($request, $settings);
        } elseif ($request->filled('product_names')) {
            foreach (preg_split('/\R/u', $request->string('product_names')->toString()) ?: [] as $name) {
                $name = trim($name);
                if ($name !== '') {
                    if (mb_strlen($name) > 255) {
                        throw ValidationException::withMessages(['product_names' => 'نام هر محصول باید حداکثر ۲۵۵ نویسه باشد.']);
                    }
                    $products[] = ['name' => $name, 'images' => $settings['images_per_product']];
                }
            }
        } elseif ($request->filled('product_name')) {
            $products[] = [
                'name' => trim($request->string('product_name')->toString()),
                'brand' => $request->input('brand'),
                'model' => $request->input('model'),
                'category' => $request->input('category'),
                'description' => $request->input('description'),
                'images' => $settings['images_per_product'],
            ];
        }

        if ($products === []) {
            throw ValidationException::withMessages(['product_name' => 'نام محصول، فهرست محصولات یا فایل JSON را وارد کنید.']);
        }
        if (count($products) > config('image_finder.max_products_per_batch')) {
            throw ValidationException::withMessages(['products_file' => 'هر دسته حداکثر می‌تواند شامل '.config('image_finder.max_products_per_batch').' محصول باشد.']);
        }

        $batch = $this->store->createBatch($settings, $request->session()->getId());
        foreach ($products as $product) {
            $this->store->createTask($batch->id, [
                'external_product_id' => isset($product['id']) ? (string) $product['id'] : null,
                'name' => $product['name'],
                'brand' => $product['brand'] ?? null,
                'model' => $product['model'] ?? null,
                'category' => $product['category'] ?? null,
                'description' => $product['description'] ?? null,
                'search_terms' => $product['search_terms'] ?? [],
                'requested_count' => min(10, max(1, (int) ($product['images'] ?? $settings['images_per_product']))),
            ]);
        }
        $this->store->activity($batch->id, null, null, 'batch_queued', 'دستهٔ جست‌وجو در صف ذخیره شد.');

        return redirect()->route('image-finder.batches.show', $batch->id)->with('status', 'جست‌وجو ثبت شد و پس از اجرای worker آغاز می‌شود.');
    }

    public function show(string $batchId): View
    {
        $batch = $this->findBatch($batchId);
        $allTasks = $this->store->tasks($batchId);
        $total = count($allTasks);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $tasks = new LengthAwarePaginator(
            array_slice($allTasks, ($page - 1) * 25, 25),
            $total,
            25,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()],
        );
        foreach ($tasks as $task) {
            $task->candidates = $this->store->candidates($task->id);
            usort($task->candidates, fn (ProductImageCandidate $left, ProductImageCandidate $right): int => $right->confidence_score <=> $left->confidence_score);
        }
        $allCandidates = [];
        foreach ($allTasks as $task) {
            $allCandidates = array_merge($allCandidates, $this->store->candidates($task->id));
        }
        $summary = [
            'verified' => count(array_filter($allCandidates, fn (ProductImageCandidate $candidate): bool => $candidate->verification_status === 'verified')),
            'needs_review' => count(array_filter($allCandidates, fn (ProductImageCandidate $candidate): bool => $candidate->verification_status === 'needs_review' && is_string($candidate->local_path))),
            'failed' => count(array_filter($allCandidates, fn (ProductImageCandidate $candidate): bool => $candidate->verification_status === 'failed')),
            'total' => $total,
        ];
        $allActivities = $this->store->activities($batchId, PHP_INT_MAX);
        $activityCounts = array_count_values(array_map(fn ($activity): string => $activity->event, $allActivities));
        $summary['provider_failed'] = $activityCounts['provider_failed'] ?? 0;
        $summary['provider_throttled'] = $activityCounts['provider_throttled'] ?? 0;
        $summary['candidate_rejected'] = $activityCounts['candidate_rejected'] ?? 0;
        $summary['downloadable_images'] = $this->downloadableCount($allTasks);
        $activities = array_slice($allActivities, 0, 100);
        foreach ($activities as $activity) {
            $activity->display_message = $this->activityDisplayMessage($activity);
        }
        $activeProviderNames = array_map(fn ($provider): string => $provider->name(), $this->providers->configured());
        $providerDiagnostics = $this->providerDiagnostics($allActivities);

        return view('product-images.show', compact('batch', 'tasks', 'summary', 'activities', 'activeProviderNames', 'providerDiagnostics'));
    }

    /**
     * @param  list<ImageSearchActivity>  $activities
     * @return list<array<string, mixed>>
     */
    private function providerDiagnostics(array $activities): array
    {
        $diagnostics = [];

        foreach ($this->providers->statuses() as $providerStatus) {
            $diagnostics[$providerStatus['name']] = [
                'name' => $providerStatus['name'],
                'status' => $providerStatus['status'],
                'credentials_present' => $providerStatus['credentials_present'],
                'requests' => 0,
                'http_statuses' => [],
                'request_urls' => [],
                'errors' => [],
                'response_ms' => 0,
                'response_bytes' => 0,
                'skip_reasons' => [],
                'raw_results' => 0,
                'direct_image_urls' => 0,
                'downloaded' => 0,
                'valid_images' => 0,
                'approved' => 0,
                'pending_review' => 0,
                'rejected' => 0,
                'rejection_reasons' => [],
                'review_reasons' => [],
            ];
        }

        foreach ($activities as $activity) {
            if ($activity->provider === null) {
                continue;
            }

            $provider = $activity->provider;
            $diagnostics[$provider] ??= [
                'name' => $provider,
                'status' => 'not_reported',
                'credentials_present' => null,
                'requests' => 0,
                'http_statuses' => [],
                'request_urls' => [],
                'errors' => [],
                'response_ms' => 0,
                'response_bytes' => 0,
                'skip_reasons' => [],
                'raw_results' => 0,
                'direct_image_urls' => 0,
                'downloaded' => 0,
                'valid_images' => 0,
                'approved' => 0,
                'pending_review' => 0,
                'rejected' => 0,
                'rejection_reasons' => [],
                'review_reasons' => [],
            ];
            $metadata = is_array($activity->metadata) ? $activity->metadata : [];

            if (in_array($activity->event, ['provider_configured', 'provider_auth_missing', 'provider_public_access', 'provider_skipped'], true)) {
                $diagnostics[$provider]['status'] = $metadata['status'] ?? $activity->event;
                $diagnostics[$provider]['credentials_present'] = $metadata['credentials_present'] ?? null;
            }

            if ($activity->event === 'provider_request') {
                $diagnostics[$provider]['requests']++;
                $diagnostics[$provider]['response_ms'] += max(0, (int) ($metadata['duration_ms'] ?? $metadata['response_ms'] ?? 0));
                $diagnostics[$provider]['response_bytes'] += max(0, (int) ($metadata['response_bytes'] ?? 0));

                if (isset($metadata['http_status'])) {
                    $diagnostics[$provider]['http_statuses'][] = (int) $metadata['http_status'];
                }

                if (is_string($metadata['request_url'] ?? null)) {
                    $diagnostics[$provider]['request_urls'][] = $metadata['request_url'];
                }

                $error = $this->requestTelemetryError($metadata);
                if ($error !== null) {
                    $diagnostics[$provider]['errors'][] = $error;
                }
            } elseif (($activity->event === 'provider_failed'
                    && ! ($metadata['request_telemetry'] ?? false)
                    && ($metadata['request_made'] ?? true))
                || ($activity->event === 'search_completed'
                    && ! ($metadata['request_telemetry'] ?? false)
                    && ! ($metadata['cache_hit'] ?? false))) {
                $diagnostics[$provider]['requests']++;
                $diagnostics[$provider]['response_ms'] += max(0, (int) ($metadata['response_ms'] ?? 0));

                if (isset($metadata['http_status'])) {
                    $diagnostics[$provider]['http_statuses'][] = (int) $metadata['http_status'];
                }
            }

            if ($activity->event === 'search_completed') {
                $diagnostics[$provider]['raw_results'] += max(0, (int) ($metadata['raw_results'] ?? 0));
                $diagnostics[$provider]['direct_image_urls'] += max(0, (int) ($metadata['direct_image_urls'] ?? 0));
            }

            if ($activity->event === 'provider_failed'
                && ! ($metadata['request_telemetry'] ?? false)
                && is_string($metadata['error'] ?? null)) {
                $error = trim($metadata['error']);
                if ($error !== '') {
                    $diagnostics[$provider]['errors'][] = $error;
                }
            }

            if ($activity->event === 'provider_not_called' && is_string($metadata['reason'] ?? null)) {
                $diagnostics[$provider]['skip_reasons'][] = $metadata['reason'];
            }

            if ($activity->event === 'candidate_validated') {
                $diagnostics[$provider]['downloaded']++;
                $diagnostics[$provider]['valid_images']++;
                $diagnostics[$provider][($metadata['verification_status'] ?? null) === 'verified' ? 'approved' : 'pending_review']++;

                if (is_string($metadata['review_reason'] ?? null)) {
                    $reason = $metadata['review_reason'];
                    $diagnostics[$provider]['review_reasons'][$reason] = ($diagnostics[$provider]['review_reasons'][$reason] ?? 0) + 1;
                }
            }

            if ($activity->event === 'candidate_rejected') {
                $diagnostics[$provider]['rejected']++;
                if ($metadata['downloaded'] ?? false) {
                    $diagnostics[$provider]['downloaded']++;
                }
                $reason = is_string($metadata['reason'] ?? null) ? $metadata['reason'] : 'unknown';
                $diagnostics[$provider]['rejection_reasons'][$reason] = ($diagnostics[$provider]['rejection_reasons'][$reason] ?? 0) + 1;

                if (isset($metadata['http_status'])) {
                    $diagnostics[$provider]['http_statuses'][] = (int) $metadata['http_status'];
                }
            }
        }

        foreach ($diagnostics as &$providerDiagnostics) {
            $providerDiagnostics['http_statuses'] = array_values(array_unique($providerDiagnostics['http_statuses']));
            $providerDiagnostics['request_urls'] = array_values(array_unique($providerDiagnostics['request_urls']));
            $providerDiagnostics['errors'] = array_values(array_unique($providerDiagnostics['errors']));
            $providerDiagnostics['skip_reasons'] = array_values(array_unique($providerDiagnostics['skip_reasons']));
            sort($providerDiagnostics['http_statuses']);
            $providerDiagnostics['average_response_ms'] = $providerDiagnostics['requests'] > 0
                ? (int) round($providerDiagnostics['response_ms'] / $providerDiagnostics['requests'])
                : null;
            ksort($providerDiagnostics['rejection_reasons']);
            ksort($providerDiagnostics['review_reasons']);
        }
        unset($providerDiagnostics);

        return array_values($diagnostics);
    }

    private function activityDisplayMessage(ImageSearchActivity $activity): string
    {
        if ($activity->event !== 'provider_request') {
            return $activity->message;
        }

        $metadata = is_array($activity->metadata) ? $activity->metadata : [];
        $status = (int) ($metadata['http_status'] ?? 0);
        $error = $this->requestTelemetryError($metadata);

        if ($status >= 200 && $status < 300 && $error === null) {
            return 'درخواست HTTP '.$status.' با موفقیت پاسخ داد.';
        }

        return $error ?? $activity->message;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function requestTelemetryError(array $metadata): ?string
    {
        $status = (int) ($metadata['http_status'] ?? 0);
        $transportError = $metadata['transport_error'] ?? null;
        $hasTransportError = ! in_array($transportError, [null, false, 0, '0', ''], true);
        $exceptionClass = $metadata['exception_class'] ?? null;
        $hasException = is_string($exceptionClass) && trim($exceptionClass) !== '';

        if ($status >= 200 && $status < 300 && ! $hasTransportError && ! $hasException) {
            return null;
        }

        $error = $metadata['error'] ?? null;
        if (! is_string($error) || trim($error) === '') {
            return null;
        }

        return isset($metadata['failure_type'])
            ? trim($metadata['failure_type'].': '.$error)
            : trim($error);
    }

    public function resume(string $batchId): RedirectResponse
    {
        $batch = $this->findBatch($batchId);
        $staleBefore = now()->subSeconds((int) config('image_finder.job_timeout') + 60)->getTimestamp();
        $taskIds = [];
        foreach ($this->store->tasks($batchId) as $task) {
            $stale = $task->status === 'processing' && strtotime((string) $task->updated_at) < $staleBefore;
            if (in_array($task->status, ['needs_review', 'failed'], true) || $stale) {
                $this->store->updateTask($task->id, ['status' => 'queued', 'error' => null]);
                $taskIds[] = $task->id;
            }
        }
        if ($taskIds === []) {
            return back()->with('status', 'محصول ناتمامی برای تلاش دوباره وجود ندارد.');
        }
        $this->store->updateBatch($batch->id, ['status' => 'queued']);
        $this->store->activity($batch->id, null, null, 'batch_resumed', count($taskIds).' محصول دوباره در صف قرار گرفت.');

        return back()->with('status', 'جست‌وجوی محصولات ناتمام دوباره در صف قرار گرفت.');
    }

    public function cancel(string $batchId): RedirectResponse
    {
        $batch = $this->findBatch($batchId);
        if (! in_array($batch->status, ['queued', 'processing'], true)) {
            return back()->with('status', 'این جست‌وجو در حال اجرا نیست.');
        }

        $this->store->updateBatch($batch->id, ['status' => 'cancelled']);
        foreach ($this->store->tasks($batch->id) as $task) {
            if (in_array($task->status, ['queued', 'processing'], true)) {
                $this->store->updateTask($task->id, ['status' => 'needs_review']);
            }
        }
        $this->store->activity($batch->id, null, null, 'batch_cancelled', 'پردازش دسته متوقف شد.');

        return back()->with('status', 'پردازش متوقف شد؛ نتایج قبلی حفظ شدند.');
    }

    public function destroyBatch(string $batchId): RedirectResponse
    {
        $batch = $this->findBatch($batchId);
        if (in_array($batch->status, ['queued', 'processing'], true)) {
            return back()->withErrors(['batch' => 'برای حذف، ابتدا پردازش این دسته را متوقف کنید.']);
        }
        $this->deleteBatchFiles($batch);
        abort_unless($this->store->deleteBatch($batch->id), 404);

        return redirect()->route('image-finder.batches.index')->with('status', 'جست‌وجو و فایل‌های مرتبط با موفقیت حذف شدند.');
    }

    public function clearBatches(): RedirectResponse
    {
        $batches = $this->store->batches(PHP_INT_MAX, request()->session()->getId());
        if (collect($batches)->contains(fn (ImageSearchBatch $batch): bool => in_array($batch->status, ['queued', 'processing'], true))) {
            return back()->withErrors(['batch' => 'برای پاک‌کردن تاریخچه، ابتدا پردازش‌های در حال اجرا را متوقف کنید.']);
        }
        foreach ($batches as $batch) {
            $this->deleteBatchFiles($batch);
            $this->store->deleteBatch($batch->id);
        }

        return redirect()->route('image-finder.batches.index')->with('status', 'تاریخچهٔ جست‌وجوها پاک شد.');
    }

    public function approve(string $candidateId): RedirectResponse
    {
        [$candidate, $task, $batch] = $this->candidateContext($candidateId);
        if ($candidate->verification_status !== 'needs_review' || ! $candidate->local_path) {
            return back()->withErrors(['candidate' => 'این گزینه برای تأیید دستی در دسترس نیست.']);
        }
        $sourceDisk = $candidate->metadata['storage_disk'] ?? 'local';
        $targetDisk = config('image_finder.disk');
        $newPath = 'product-images/'.$batch->id.'/'.$task->id.'/'.Str::uuid().'.webp';
        $stream = Storage::disk($sourceDisk)->readStream($candidate->local_path);
        if ($stream === false || ! Storage::disk($targetDisk)->put($newPath, $stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return back()->withErrors(['candidate' => 'انتقال تصویر به فضای ذخیره‌سازی نهایی انجام نشد.']);
        }
        fclose($stream);
        $metadata = array_merge($candidate->metadata ?? [], ['storage_disk' => $targetDisk, 'manual_approval' => true]);
        $this->store->updateCandidate($candidate->id, [
            'verification_status' => 'verified',
            'local_path' => $newPath,
            'review_reason' => null,
            'metadata' => $metadata,
        ]);
        $verifiedCount = count(array_filter(
            $this->store->candidates($task->id),
            fn (ProductImageCandidate $item): bool => $item->verification_status === 'verified',
        ));
        $this->store->updateTask($task->id, [
            'status' => $verifiedCount >= $task->requested_count ? 'verified' : 'needs_review',
            'error' => null,
        ]);
        $this->store->activity($batch->id, $task->id, null, 'candidate_approved', 'تصویر دستی تأیید شد؛ مجوز انتشار باید جداگانه بررسی شود.', ['candidate_id' => $candidate->id]);
        app(ImageDiscoveryManager::class)->refreshBatchStatus($batch);
        Storage::disk($sourceDisk)->delete($candidate->local_path);

        return back()->with('status', 'تصویر تأیید شد. پیش از انتشار، مجوز استفاده را بررسی کنید.');
    }

    public function reject(string $candidateId): RedirectResponse
    {
        [$candidate] = $this->candidateContext($candidateId);
        $disk = $candidate->metadata['storage_disk'] ?? 'local';
        $path = $candidate->local_path;
        $this->store->updateCandidate($candidate->id, [
            'verification_status' => 'rejected',
            'local_path' => null,
            'review_reason' => 'در بازبینی دستی رد شد.',
        ]);
        if ($path) {
            Storage::disk($disk)->delete($path);
        }

        return back()->with('status', 'تصویر رد شد.');
    }

    public function preview(string $candidateId): StreamedResponse
    {
        [$candidate] = $this->candidateContext($candidateId);
        abort_unless($candidate->local_path, 404);
        $stream = Storage::disk($candidate->metadata['storage_disk'] ?? config('image_finder.disk'))->readStream($candidate->local_path);
        abort_if($stream === false, 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function exportJson(string $batchId): JsonResponse
    {
        return response()->json($this->exportData($this->findBatch($batchId)));
    }

    public function archive(string $batchId): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 501, 'برای ساخت فایل ZIP، افزونهٔ zip در PHP لازم است.');
        $batch = $this->findBatch($batchId);
        $tasks = $this->store->tasks($batchId);
        $totalSize = 0;
        foreach ($tasks as $task) {
            foreach ($this->downloadableCandidates($task) as $candidate) {
                $disk = $candidate->metadata['storage_disk'] ?? config('image_finder.disk');
                $totalSize += Storage::disk($disk)->size($candidate->local_path);
                abort_if($totalSize > config('image_finder.max_archive_size'), 413, 'حجم فایل فشرده از سقف مجاز بیشتر است.');
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'product-images-zip-');
        abort_if($path === false, 500, 'ساخت فایل موقت ممکن نیست.');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            abort(500, 'ساخت فایل فشرده انجام نشد.');
        }
        $temporaryImages = [];
        try {
            if (! $zip->addFromString('products.json', json_encode($this->exportData($batch), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Unable to add product metadata to the archive.');
            }
            foreach ($tasks as $task) {
                foreach ($this->downloadableCandidates($task) as $index => $candidate) {
                    $disk = $candidate->metadata['storage_disk'] ?? config('image_finder.disk');
                    $stream = Storage::disk($disk)->readStream($candidate->local_path);
                    if ($stream === false) {
                        throw new \RuntimeException('A downloaded image is missing from storage.');
                    }
                    $temporaryImage = tempnam(sys_get_temp_dir(), 'product-image-zip-');
                    if ($temporaryImage === false) {
                        fclose($stream);
                        throw new \RuntimeException('Unable to allocate a temporary archive file.');
                    }
                    $target = fopen($temporaryImage, 'wb');
                    if ($target === false) {
                        fclose($stream);
                        @unlink($temporaryImage);
                        throw new \RuntimeException('Unable to write a temporary archive file.');
                    }
                    $copied = stream_copy_to_stream($stream, $target);
                    fclose($target);
                    fclose($stream);
                    if ($copied === false || ! $zip->addFile($temporaryImage, $task->id.'/image-'.($index + 1).'.webp')) {
                        @unlink($temporaryImage);
                        throw new \RuntimeException('Unable to add a verified image to the archive.');
                    }
                    $temporaryImages[] = $temporaryImage;
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finalize the image archive.');
            }
        } catch (Throwable $exception) {
            $zip->close();
            @unlink($path);
            foreach ($temporaryImages as $temporaryImage) {
                @unlink($temporaryImage);
            }
            throw $exception;
        }
        foreach ($temporaryImages as $temporaryImage) {
            @unlink($temporaryImage);
        }

        return response()->download($path, 'product-images-'.$batch->id.'.zip')->deleteFileAfterSend(true);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function readJsonUpload(Request $request, array $defaults): array
    {
        try {
            $data = json_decode($request->file('products_file')->get(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['products_file' => 'محتوای فایل JSON معتبر نیست.']);
        }
        if (! is_array($data)) {
            throw ValidationException::withMessages(['products_file' => 'ساختار JSON باید فهرست یا شیء محصولات باشد.']);
        }
        $inputSettings = [];
        if (array_is_list($data)) {
            $products = $data;
            $settings = $defaults;
        } else {
            $products = $data['products'] ?? $data['data'] ?? null;
            $inputSettings = $data['settings'] ?? [];
            if (! is_array($inputSettings)) {
                throw ValidationException::withMessages(['products_file' => 'تنظیمات فایل JSON معتبر نیست.']);
            }
            if ($products === null) {
                $products = array_diff_key($data, ['settings' => true]);
            }
            $settingKeys = [
                'images_per_product',
                'min_width',
                'min_height',
                'language',
                'search_mode',
                'auto_approve_open_licensed',
                'require_manual_review_for_unverified',
                'allow_similar_products',
                'download_images',
                'generate_alternative_queries',
                'deduplicate',
                'image_format',
            ];
            $inputSettings = array_intersect_key($inputSettings, array_flip($settingKeys));
            $settings = array_merge($defaults, $inputSettings);
        }
        if (! is_array($products) || $products === []) {
            throw ValidationException::withMessages(['products_file' => 'فایل JSON باید فهرست غیرخالی محصولات داشته باشد.']);
        }
        if (! array_is_list($products)) {
            $normalized = [];
            foreach ($products as $productId => $product) {
                if (is_string($product)) {
                    $product = ['name' => $product];
                }
                if (is_array($product) && ! isset($product['id']) && (is_string($productId) || is_int($productId))) {
                    $product['id'] = (string) $productId;
                }
                $normalized[] = $product;
            }
            $products = $normalized;
        }
        if (array_key_exists('images_per_product', $inputSettings) && (! is_numeric($settings['images_per_product']) || (int) $settings['images_per_product'] < 1 || (int) $settings['images_per_product'] > 10)) {
            throw ValidationException::withMessages(['products_file' => 'تعداد تصاویر باید بین ۱ تا ۱۰ باشد.']);
        }
        if (array_key_exists('images_per_product', $inputSettings) && (float) $settings['images_per_product'] !== (float) (int) $settings['images_per_product']) {
            throw ValidationException::withMessages(['products_file' => 'تعداد تصاویر باید عدد صحیح باشد.']);
        }
        if (array_key_exists('min_width', $inputSettings) && (! is_numeric($settings['min_width']) || (int) $settings['min_width'] < 100 || (int) $settings['min_width'] > 4000)) {
            throw ValidationException::withMessages(['products_file' => 'حداقل عرض تصویر باید بین ۱۰۰ تا ۴۰۰۰ پیکسل باشد.']);
        }
        if (array_key_exists('min_width', $inputSettings) && (float) $settings['min_width'] !== (float) (int) $settings['min_width']) {
            throw ValidationException::withMessages(['products_file' => 'حداقل عرض باید عدد صحیح باشد.']);
        }
        foreach (['min_width', 'min_height'] as $dimension) {
            if (array_key_exists($dimension, $inputSettings) && (! is_numeric($settings[$dimension]) || (int) $settings[$dimension] < 100 || (int) $settings[$dimension] > 4000)) {
                throw ValidationException::withMessages(['products_file' => 'حداقل اندازهٔ تصویر باید بین ۱۰۰ تا ۴۰۰۰ پیکسل باشد.']);
            }
            if (array_key_exists($dimension, $inputSettings) && (float) $settings[$dimension] !== (float) (int) $settings[$dimension]) {
                throw ValidationException::withMessages(['products_file' => 'حداقل اندازهٔ تصویر باید عدد صحیح باشد.']);
            }
        }
        foreach ([
            'auto_approve_open_licensed',
            'require_manual_review_for_unverified',
            'allow_similar_products',
            'download_images',
            'generate_alternative_queries',
            'deduplicate',
        ] as $booleanSetting) {
            if (array_key_exists($booleanSetting, $inputSettings) && ! is_bool($settings[$booleanSetting])) {
                throw ValidationException::withMessages(['products_file' => 'مقدار تنظیم '.$booleanSetting.' باید درست یا نادرست باشد.']);
            }
        }
        if (array_key_exists('language', $inputSettings) && (! is_string($settings['language']) || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $settings['language']) !== 1)) {
            throw ValidationException::withMessages(['products_file' => 'زبان جست‌وجو معتبر نیست.']);
        }
        if (array_key_exists('search_mode', $inputSettings) && ! in_array($settings['search_mode'], ['standard', 'comprehensive'], true)) {
            throw ValidationException::withMessages(['products_file' => 'حالت جست‌وجو معتبر نیست.']);
        }
        if (array_key_exists('image_format', $inputSettings) && $settings['image_format'] !== 'webp') {
            throw ValidationException::withMessages(['products_file' => 'قالب خروجی پشتیبانی‌شده فقط webp است.']);
        }
        if (isset($settings['image_format'])) {
            $settings['preferred_format'] = $settings['image_format'];
        }
        $settings['images_per_product'] = (int) $settings['images_per_product'];
        $settings['min_width'] = (int) $settings['min_width'];
        $settings['min_height'] = (int) $settings['min_height'];
        foreach ($products as $index => &$product) {
            if (is_string($product)) {
                $product = ['name' => $product];
            }
            if (! is_array($product) || ! isset($product['name']) || ! is_string($product['name']) || trim($product['name']) === '') {
                throw ValidationException::withMessages(['products_file' => 'نام محصول شمارهٔ '.($index + 1).' وارد نشده است.']);
            }
            foreach (['id', 'brand', 'model', 'category', 'description'] as $field) {
                if (isset($product[$field]) && ! is_scalar($product[$field])) {
                    throw ValidationException::withMessages(['products_file' => 'مقدار فیلد '.$field.' در محصول شمارهٔ '.($index + 1).' معتبر نیست.']);
                }
            }
            if (isset($product['search_terms']) && (! is_array($product['search_terms']) || ! array_is_list($product['search_terms']))) {
                throw ValidationException::withMessages(['products_file' => 'عبارت‌های جست‌وجوی محصول شمارهٔ '.($index + 1).' باید فهرستی از متن‌ها باشد.']);
            }
            if (isset($product['search_terms'])) {
                if (count($product['search_terms']) > 20) {
                    throw ValidationException::withMessages(['products_file' => 'حداکثر ۲۰ عبارت جست‌وجو برای هر محصول پذیرفته می‌شود.']);
                }
                foreach ($product['search_terms'] as $searchTerm) {
                    if (! is_string($searchTerm) || trim($searchTerm) === '' || mb_strlen($searchTerm) > 500) {
                        throw ValidationException::withMessages(['products_file' => 'هر عبارت جست‌وجو باید متن غیرخالی و حداکثر ۵۰۰ نویسه باشد.']);
                    }
                }
                $product['search_terms'] = array_values(array_unique(array_map('trim', $product['search_terms'])));
            } else {
                $product['search_terms'] = [];
            }
            if (isset($product['id']) && mb_strlen((string) $product['id']) > 255) {
                throw ValidationException::withMessages(['products_file' => 'شناسهٔ هر محصول باید حداکثر ۲۵۵ نویسه باشد.']);
            }
            foreach (['brand', 'model', 'category', 'description'] as $field) {
                if (isset($product[$field])) {
                    $product[$field] = Str::limit((string) $product[$field], $field === 'description' ? 2000 : 255, '');
                }
            }
            $product['name'] = Str::limit(trim($product['name']), 255, '');
            $imageCount = $product['images'] ?? $settings['images_per_product'];
            if (! is_numeric($imageCount) || (int) $imageCount < 1 || (int) $imageCount > 10) {
                throw ValidationException::withMessages(['products_file' => 'تعداد تصاویر محصول شمارهٔ '.($index + 1).' باید بین ۱ تا ۱۰ باشد.']);
            }
            if ((float) $imageCount !== (float) (int) $imageCount) {
                throw ValidationException::withMessages(['products_file' => 'تعداد تصاویر محصول شمارهٔ '.($index + 1).' باید عدد صحیح باشد.']);
            }
            $product['images'] = (int) $imageCount;
        }
        unset($product);

        return [$products, $settings];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportData(ImageSearchBatch $batch): array
    {
        $products = [];
        foreach ($this->store->tasks($batch->id) as $task) {
            $images = [];
            foreach ($this->downloadableCandidates($task) as $index => $candidate) {
                $images[] = [
                    'filename' => 'image-'.($index + 1).'.webp',
                    'local_path' => $candidate->local_path,
                    'source_url' => $candidate->source_url,
                    'source_name' => $candidate->source_name,
                    'license' => $candidate->license ?? (($candidate->metadata['manual_approval'] ?? false) ? 'License not verified; manually approved' : null),
                    'license_url' => $candidate->license_url,
                    'attribution' => $candidate->attribution,
                    'width' => $candidate->width,
                    'height' => $candidate->height,
                    'verification_status' => $candidate->verification_status,
                    'license_status' => $candidate->license_status ?? 'unknown',
                    'confidence_score' => $candidate->confidence_score,
                    'manual_approval' => (bool) ($candidate->metadata['manual_approval'] ?? false),
                ];
            }
            $products[] = [
                'product_id' => $task->external_product_id ?? $task->id,
                'product_name' => $task->name,
                'status' => $task->status,
                'requested_images' => $task->requested_count,
                'verified_images' => count(array_filter(
                    $images,
                    fn (array $image): bool => $image['verification_status'] === 'verified',
                )),
                'downloaded_images' => count($images),
                'images' => $images,
            ];
        }

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'products' => $products,
            'summary' => [
                'total_products' => count($products),
                'completed_products' => count(array_filter($products, fn (array $product): bool => $product['status'] === 'verified')),
                'incomplete_products' => count(array_filter($products, fn (array $product): bool => $product['status'] === 'needs_review')),
                'failed_products' => count(array_filter($products, fn (array $product): bool => $product['status'] === 'failed')),
            ],
        ];
    }

    private function findBatch(string $batchId): ImageSearchBatch
    {
        $batch = $this->store->batch($batchId);
        abort_unless($batch, 404);
        $this->authorizeBatch($batch);

        return $batch;
    }

    private function deleteBatchFiles(ImageSearchBatch $batch): void
    {
        $pathsByDisk = [];
        $batchDirectory = 'product-images/'.$batch->id.'/';

        foreach ($this->store->tasks($batch->id) as $task) {
            foreach ($this->store->candidates($task->id) as $candidate) {
                if (! is_string($candidate->local_path) || ! str_starts_with($candidate->local_path, $batchDirectory)) {
                    continue;
                }

                $disk = $candidate->metadata['storage_disk'] ?? config('image_finder.disk');
                $pathsByDisk[$disk][] = $candidate->local_path;
            }
        }

        foreach ($pathsByDisk as $disk => $paths) {
            if (! Storage::disk($disk)->delete(array_values(array_unique($paths)))) {
                throw new \RuntimeException('Unable to delete image-finder batch files.');
            }
        }
    }

    private function authorizeBatch(ImageSearchBatch $batch): void
    {
        if ($batch->owner_id !== null) {
            abort_unless(hash_equals((string) $batch->owner_id, request()->session()->getId()), 404);
        }
    }

    /**
     * @return array{0: ProductImageCandidate, 1: ProductImageTask, 2: ImageSearchBatch}
     */
    private function candidateContext(string $candidateId): array
    {
        $candidate = $this->store->candidate($candidateId);
        abort_unless($candidate, 404);
        $task = $this->store->task($candidate->product_image_task_id);
        $batch = $this->store->taskBatch($candidate->product_image_task_id);
        abort_unless($task && $batch, 404);
        $this->authorizeBatch($batch);
        $candidate->task = $task;

        return [$candidate, $task, $batch];
    }

    /**
     * @param  list<ProductImageTask>  $tasks
     */
    private function downloadableCount(array $tasks): int
    {
        return array_sum(array_map(
            fn (ProductImageTask $task): int => count($this->downloadableCandidates($task)),
            $tasks,
        ));
    }

    /**
     * @return list<ProductImageCandidate>
     */
    private function downloadableCandidates(ProductImageTask $task): array
    {
        return array_values(array_filter(
            $this->store->candidates($task->id),
            fn (ProductImageCandidate $candidate): bool => in_array(
                $candidate->verification_status,
                ['verified', 'needs_review'],
                true,
            ) && is_string($candidate->local_path) && $candidate->local_path !== '',
        ));
    }
}

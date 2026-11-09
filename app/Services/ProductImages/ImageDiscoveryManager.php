<?php

namespace App\Services\ProductImages;

use App\Models\ImageSearchBatch;
use App\Models\ProductImageCandidate;
use App\Models\ProductImageTask;
use App\Services\ProductImages\Contracts\ImageSearchProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class ImageDiscoveryManager
{
    /**
     * @var array<string, int>
     */
    private array $queryRejectionCounts = [];

    public function __construct(
        private readonly FileImageFinderStore $store,
        private readonly ImageProviderRegistry $providers,
        private readonly SearchQueryGenerator $queries,
        private readonly ImageVerificationService $verification,
        private readonly ImageLicensePolicy $licenses,
        private readonly ImageDownloadService $downloads,
    ) {}

    public function discover(ProductImageTask $task): void
    {
        $this->store->updateTask($task->id, ['status' => 'processing', 'error' => null]);
        $batch = $this->store->taskBatch($task->id);
        if (! $batch) {
            return;
        }
        $task->batch = $batch;
        $this->store->updateBatch($batch->id, ['status' => 'processing']);
        $this->activity($batch, $task, null, 'product_started', 'جست‌وجوی تصاویر محصول آغاز شد.');
        $candidates = $this->store->candidates($task->id);
        $acceptedCount = count(array_filter($candidates, fn (ProductImageCandidate $candidate): bool => $candidate->verification_status === 'verified'));
        $candidateCount = count(array_filter($candidates, fn (ProductImageCandidate $candidate): bool => in_array(
            $candidate->verification_status,
            ['verified', 'needs_review'],
            true,
        ) && is_string($candidate->local_path)));
        $existingUrls = array_values(array_filter(array_map(
            fn (ProductImageCandidate $candidate): ?string => is_string($candidate->source_url)
                ? $this->normalizeSourceUrl($candidate->source_url)
                : null,
            $candidates,
        )));
        $existingHashes = array_values(array_filter(array_map(fn (ProductImageCandidate $candidate): ?string => $candidate->perceptual_hash, $candidates)));
        $this->retryFailedCandidates($candidates, $task, $batch, $existingHashes, $candidateCount, $acceptedCount);
        $downloadImages = $batch->settings['download_images'] ?? true;
        $searchProviders = $this->providers->configured();
        $providerStatuses = $this->providers->statuses();

        foreach ($providerStatuses as $providerStatus) {
            $event = match ($providerStatus['status']) {
                'disabled', 'missing_credentials' => 'provider_skipped',
                'anonymous' => 'provider_auth_missing',
                'public' => 'provider_public_access',
                default => 'provider_configured',
            };
            $this->activity(
                $batch,
                $task,
                $providerStatus['name'],
                $event,
                match ($providerStatus['status']) {
                    'disabled' => 'این منبع در تنظیمات غیرفعال است.',
                    'missing_credentials' => 'این منبع به‌دلیل نبود اطلاعات دسترسی اجرا نشد.',
                    'anonymous' => 'این منبع بدون اطلاعات دسترسی و با دسترسی عمومی اجرا می‌شود.',
                    'public' => 'این منبع به اطلاعات دسترسی نیاز ندارد و آمادهٔ جست‌وجو است.',
                    default => 'این منبع آمادهٔ جست‌وجو است.',
                },
                [
                    'status' => $providerStatus['status'],
                    'enabled' => $providerStatus['enabled'],
                    'credentials_present' => $providerStatus['credentials_present'],
                ],
            );
        }

        if ($searchProviders === []) {
            $this->activity($batch, $task, null, 'warning', 'هیچ منبع جست‌وجوی فعالی پیکربندی نشده است.');
        }

        foreach ($this->queries->generate($task) as $query) {
            if ($acceptedCount >= $task->requested_count
                || $candidateCount >= config('image_finder.max_candidates_per_product')
                || $this->currentBatchStatus($batch->id) === 'cancelled') {
                break;
            }

            foreach ($searchProviders as $provider) {
                if ($acceptedCount >= $task->requested_count
                    || $candidateCount >= config('image_finder.max_candidates_per_product')
                    || $this->currentBatchStatus($batch->id) === 'cancelled') {
                    break;
                }

                if (! $this->searchProvider($provider, $query, $task, $batch, $existingUrls, $existingHashes, $candidateCount, $acceptedCount, $downloadImages)) {
                    return;
                }
            }
        }

        if ($this->currentBatchStatus($batch->id) === 'cancelled') {
            return;
        }

        $this->recordUncalledProviders($batch, $task, $searchProviders, $acceptedCount, $candidateCount);

        $verifiedCount = count(array_filter(
            $this->store->candidates($task->id),
            fn (ProductImageCandidate $candidate): bool => $candidate->verification_status === 'verified',
        ));
        $taskStatus = $verifiedCount >= $task->requested_count ? 'verified' : 'needs_review';
        $this->store->updateTask($task->id, ['status' => $taskStatus]);
        $this->activity(
            $batch,
            $task,
            null,
            $taskStatus,
            $verifiedCount.' از '.$task->requested_count.' تصویر در بررسی خودکار تأیید شد.',
        );
        $this->refreshBatchStatus($batch);
    }

    public function refreshBatchStatus(ImageSearchBatch $batch): void
    {
        if ($this->currentBatchStatus($batch->id) === 'cancelled') {
            return;
        }

        $tasks = $this->store->tasks($batch->id);
        $total = count($tasks);
        $counts = array_count_values(array_map(fn (ProductImageTask $task): string => $task->status, $tasks));
        $finished = ($counts['verified'] ?? 0) + ($counts['needs_review'] ?? 0) + ($counts['failed'] ?? 0);

        if ($total === 0 || $finished < $total) {
            $this->store->updateBatch($batch->id, ['status' => 'processing']);

            return;
        }

        $status = ($counts['failed'] ?? 0) > 0 && ($counts['verified'] ?? 0) === 0
            ? 'failed'
            : (($counts['needs_review'] ?? 0) > 0 || ($counts['failed'] ?? 0) > 0 ? 'completed_with_warnings' : 'completed');
        $this->store->updateBatch($batch->id, ['status' => $status]);
    }

    /**
     * @param  list<ImageSearchProvider>  $providers
     */
    private function recordUncalledProviders(
        ImageSearchBatch $batch,
        ProductImageTask $task,
        array $providers,
        int $acceptedCount,
        int $candidateCount,
    ): void {
        $activities = $this->store->activities($batch->id, PHP_INT_MAX);

        foreach ($providers as $provider) {
            $providerActivities = array_filter(
                $activities,
                fn ($activity): bool => $activity->product_image_task_id === $task->id && $activity->provider === $provider->name(),
            );
            $hasSearchActivity = count(array_filter(
                $providerActivities,
                fn ($activity): bool => in_array($activity->event, ['provider_request', 'search_completed'], true)
                    || ($activity->event === 'provider_failed' && ($activity->metadata['request_made'] ?? true)),
            )) > 0;
            $wasDeferred = count(array_filter(
                $providerActivities,
                fn ($activity): bool => $activity->event === 'provider_throttled',
            )) > 0;

            if ($hasSearchActivity || $wasDeferred) {
                continue;
            }

            $reason = $acceptedCount >= $task->requested_count
                ? 'requested_images_found'
                : ($candidateCount >= (int) config('image_finder.max_candidates_per_product')
                    ? 'candidate_limit_reached'
                    : 'provider_not_reached');
            $this->activity(
                $batch,
                $task,
                $provider->name(),
                'provider_not_called',
                'این منبع درخواست شبکه دریافت نکرد: '.$reason.'.',
                ['reason' => $reason],
            );
        }
    }

    private function searchProvider(
        ImageSearchProvider $provider,
        string $query,
        ProductImageTask $task,
        ImageSearchBatch $batch,
        array &$existingUrls,
        array &$existingHashes,
        int &$candidateCount,
        int &$acceptedCount,
        bool $downloadImages,
    ): bool {
        $this->queryRejectionCounts = [];
        $activities = $this->store->activities($batch->id, PHP_INT_MAX);
        $requestCount = count(array_filter($activities, fn ($activity): bool => $activity->provider !== null
            && ($activity->event === 'provider_request'
                || (in_array($activity->event, ['provider_failed', 'search_completed'], true)
                    && ! ($activity->metadata['request_telemetry'] ?? false)
                    && ($activity->metadata['request_made'] ?? true)
                    && ! ($activity->metadata['cache_hit'] ?? false)))));

        if ($requestCount >= max(1, (int) config('image_finder.max_provider_requests_per_batch'))) {
            $this->activity($batch, $task, $provider->name(), 'budget_exhausted', 'سقف درخواست‌های این دسته به پایان رسیده است.');

            return true;
        }

        $responseMilliseconds = 0;
        $cacheHit = false;
        $providerTelemetry = [];

        try {
            $cacheKey = 'image-finder:search:'.sha1($provider->name().':'.$query);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $cacheHit = true;
                $results = isset($cached['results']) && is_array($cached['results'])
                    ? $cached['results']
                    : $cached;
            } else {
                $rateLimitKey = 'image-finder:provider:'.sha1($provider->name());
                $requestMade = false;
                $results = RateLimiter::attempt(
                    $rateLimitKey,
                    max(1, (int) config('image_finder.provider_rate_limit')),
                    function () use ($provider, $query, &$requestMade, &$responseMilliseconds): array {
                        $requestMade = true;
                        $startedAt = hrtime(true);
                        if ($provider instanceof ReportsImageProviderRequestTelemetry) {
                            $provider->beginRequestTelemetry();
                        }
                        try {
                            return $provider->search($query);
                        } finally {
                            $responseMilliseconds = (int) ((hrtime(true) - $startedAt) / 1_000_000);
                        }

                    },
                    60,
                );

                if (! $requestMade) {
                    $retryAfter = max(1, RateLimiter::availableIn($rateLimitKey));
                    $this->store->updateTask($task->id, [
                        'status' => 'queued',
                        'available_at' => now()->addSeconds($retryAfter)->toIso8601String(),
                    ]);
                    $this->activity(
                        $batch,
                        $task,
                        $provider->name(),
                        'provider_throttled',
                        'سقف درخواست منبع پر شده است؛ جست‌وجو برای '.$retryAfter.' ثانیهٔ دیگر در صف می‌ماند.',
                        ['retry_after' => $retryAfter],
                    );

                    return false;
                }

                if ($provider instanceof ReportsImageProviderRequestTelemetry) {
                    $providerTelemetry = $provider->takeRequestTelemetry();
                }

                if (! is_array($results)) {
                    throw new \RuntimeException('Image search provider returned an invalid response.');
                }

                Cache::put($cacheKey, [
                    'results' => $results,
                    'response_ms' => $responseMilliseconds,
                ], now()->addMinutes(15));
            }

            $directUrlCount = count(array_filter(
                $results,
                fn (mixed $result): bool => is_array($result) && is_string($result['url'] ?? null) && $result['url'] !== '',
            ));
            $this->activity(
                $batch,
                $task,
                $provider->name(),
                'search_completed',
                'تعداد '.count($results).' گزینه دریافت شد.',
                [
                    'query' => $query,
                    'http_status' => $cacheHit ? null : 200,
                    'response_ms' => $responseMilliseconds,
                    'raw_results' => count($results),
                    'direct_image_urls' => $directUrlCount,
                    'cache_hit' => $cacheHit,
                    'request_telemetry' => $providerTelemetry !== [],
                ],
            );
        } catch (Throwable $exception) {
            if ($provider instanceof ReportsImageProviderRequestTelemetry) {
                $provider->recordRequestFailure($exception);
                $providerTelemetry = $provider->takeRequestTelemetry();
            }
            $httpStatus = $this->httpStatusFromException($exception);
            Log::warning('Product image provider search failed.', [
                'batch_id' => $batch->id,
                'task_id' => $task->id,
                'provider' => $provider->name(),
                'exception' => $exception::class,
                'http_status' => $httpStatus,
                'response_ms' => $responseMilliseconds,
                'query' => $query,
                'error' => $this->sanitizeProviderError($exception->getMessage()),
            ]);
            $this->recordProviderRequestTelemetry($batch, $task, $provider, $providerTelemetry, 0, 0, 0);
            $this->activity(
                $batch,
                $task,
                $provider->name(),
                'provider_failed',
                'جست‌وجو در این منبع ناموفق بود؛ منابع جایگزین بررسی می‌شوند.',
                [
                    'query' => $query,
                    'http_status' => $httpStatus,
                    'response_ms' => $responseMilliseconds,
                    'exception' => $exception::class,
                    'error' => $this->sanitizeProviderError($exception->getMessage()),
                    'request_made' => $providerTelemetry !== [],
                    'request_telemetry' => $providerTelemetry !== [],
                ],
            );

            return true;
        }

        $candidateCountBefore = $candidateCount;

        foreach ($results as $result) {
            if ($this->currentBatchStatus($batch->id) === 'cancelled') {
                $this->recordProviderRequestTelemetry(
                    $batch,
                    $task,
                    $provider,
                    $providerTelemetry,
                    count($results),
                    $directUrlCount,
                    max(0, $candidateCount - $candidateCountBefore),
                );

                return true;
            }
            if ($acceptedCount >= $task->requested_count || $candidateCount >= config('image_finder.max_candidates_per_product')) {
                $this->recordProviderRequestTelemetry(
                    $batch,
                    $task,
                    $provider,
                    $providerTelemetry,
                    count($results),
                    $directUrlCount,
                    max(0, $candidateCount - $candidateCountBefore),
                );

                return true;
            }

            if (! is_array($result)) {
                $this->recordRejection($batch, $task, $provider, 'missing_direct_image_url', null, null);

                continue;
            }

            $title = is_string($result['title'] ?? null) ? Str::limit($result['title'], 255, '') : null;
            $sourceUrl = $result['url'] ?? null;
            $sourcePageUrl = is_string($result['source_page_url'] ?? null) ? $result['source_page_url'] : null;
            $license = is_string($result['license'] ?? null) ? $result['license'] : null;
            $licenseUrl = is_string($result['license_url'] ?? null) ? $result['license_url'] : null;
            $attribution = is_string($result['attribution'] ?? null) ? $result['attribution'] : null;
            $licenseStatus = $this->licenses->classify(
                $license,
                $licenseUrl,
            );
            $providerRejectionReason = is_string($result['rejection_reason'] ?? null) ? $result['rejection_reason'] : null;

            if ($providerRejectionReason === 'missing_direct_image_url' || ! is_string($sourceUrl) || $sourceUrl === '') {
                $this->recordRejection($batch, $task, $provider, 'missing_direct_image_url', $title, null, null, $licenseStatus);

                continue;
            }

            $url = $this->normalizeSourceUrl($sourceUrl);

            if (! $this->isValidImageUrl($url)) {
                $this->recordRejection($batch, $task, $provider, 'invalid_image_url', $title, $url, null, $licenseStatus);

                continue;
            }

            if ($providerRejectionReason !== null || ($result['is_image_file'] ?? true) === false) {
                $this->recordRejection($batch, $task, $provider, 'invalid_image_content', $title, $url, null, $licenseStatus);

                continue;
            }

            $mime = is_string($result['mime'] ?? null)
                ? strtolower(trim(explode(';', $result['mime'])[0]))
                : null;

            if ($mime !== null && ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                $this->recordRejection($batch, $task, $provider, 'unsupported_mime_type', $title, $url, null, $licenseStatus);

                continue;
            }

            if (in_array($url, $existingUrls, true)) {
                $this->recordRejection($batch, $task, $provider, 'duplicate_image', $title, $url, null, $licenseStatus);

                continue;
            }

            $existingUrls[] = $url;
            $score = $this->verification->confidence(
                $task,
                (string) ($title ?? ''),
                $sourcePageUrl,
                $batch->settings['allow_similar_products'] ?? false,
            );
            $minimumConfidence = max(0, min(1, (float) config('image_finder.min_candidate_confidence')));
            $automaticConfidence = max(0, min(1, (float) config('image_finder.auto_approval_confidence')));
            if (! $this->verification->isRelevant(
                $task,
                (string) ($title ?? ''),
                $sourcePageUrl,
                $minimumConfidence,
                $batch->settings['allow_similar_products'] ?? false,
            )) {
                $this->recordRejection($batch, $task, $provider, 'irrelevant_product', $title, $url, $score, $licenseStatus);

                continue;
            }

            $automaticallyAllowed = $score >= max($minimumConfidence, $automaticConfidence)
                && ($batch->settings['auto_approve_open_licensed'] ?? true)
                && $licenseStatus === 'verified_open'
                && $this->licenses->allowsAutomaticUse(
                    $license,
                    $licenseUrl,
                    $attribution,
                );
            $candidate = $this->store->createCandidate($task->id, [
                'source_name' => is_string($result['source_name'] ?? null)
                    ? Str::limit($result['source_name'], 255, '')
                    : $provider->name(),
                'source_url' => $url,
                'source_page_url' => $sourcePageUrl !== null ? Str::limit($sourcePageUrl, 4096, '') : null,
                'license' => $license !== null ? Str::limit($license, 1000, '') : null,
                'license_url' => $licenseUrl !== null ? Str::limit($licenseUrl, 4096, '') : null,
                'attribution' => $attribution !== null ? Str::limit($attribution, 2000, '') : null,
                'title' => $title,
                'width' => isset($result['width']) && (int) $result['width'] > 0 ? (int) $result['width'] : null,
                'height' => isset($result['height']) && (int) $result['height'] > 0 ? (int) $result['height'] : null,
                'confidence_score' => $score,
                'license_status' => $licenseStatus,
                'verification_status' => 'discovered',
                'review_reason' => $automaticallyAllowed ? null : $this->licenseReviewReason($licenseStatus, $score, $automaticConfidence),
                'metadata' => [
                    'mime' => $mime,
                    'file_size' => isset($result['file_size']) ? (int) $result['file_size'] : null,
                    'original_url' => is_string($result['original_url'] ?? null) ? Str::limit($result['original_url'], 4096, '') : null,
                    'license_url' => $licenseUrl !== null ? Str::limit($licenseUrl, 4096, '') : null,
                ],
            ]);
            if (! $downloadImages) {
                $this->store->updateCandidate($candidate->id, [
                    'verification_status' => 'needs_review',
                    'review_reason' => 'دریافت تصویر در تنظیمات این دسته غیرفعال است.',
                ]);

                continue;
            }
            $disk = $automaticallyAllowed ? config('image_finder.disk') : 'local';
            $directory = 'product-images/'.$task->image_search_batch_id.'/'.$task->id;

            try {
                $processed = $this->downloads->downloadAndProcess($candidate, $disk, $directory);
                if (($batch->settings['deduplicate'] ?? true) && $this->verification->isDuplicate($processed['hash'], $existingHashes)) {
                    $this->downloads->delete($processed['disk'], $processed['path']);
                    $this->store->updateCandidate($candidate->id, [
                        'verification_status' => 'rejected',
                        'review_reason' => 'تصویر تکراری است.',
                        'rejection_reason' => 'duplicate_image',
                    ]);
                    $this->recordRejection($batch, $task, $provider, 'duplicate_image', $title, $url, $score, $licenseStatus, $candidate->id);

                    continue;
                }
                $existingHashes[] = $processed['hash'];
                $reviewReason = $this->licenseReviewReason($licenseStatus, $score, $automaticConfidence);
                $this->store->updateCandidate($candidate->id, [
                    'width' => $processed['width'],
                    'height' => $processed['height'],
                    'perceptual_hash' => $processed['hash'],
                    'local_path' => $processed['path'],
                    'metadata' => array_merge($candidate->metadata ?? [], ['storage_disk' => $processed['disk']]),
                    'verification_status' => $automaticallyAllowed ? 'verified' : 'needs_review',
                    'review_reason' => $automaticallyAllowed ? null : $reviewReason,
                ]);
                $candidateCount++;
                $this->activity(
                    $batch,
                    $task,
                    $provider->name(),
                    'candidate_validated',
                    'تصویر دریافت و اعتبارسنجی شد.',
                    [
                        'candidate_id' => $candidate->id,
                        'downloaded' => true,
                        'valid' => true,
                        'license_status' => $licenseStatus,
                        'confidence_score' => $score,
                        'verification_status' => $automaticallyAllowed ? 'verified' : 'needs_review',
                        'review_reason' => $automaticallyAllowed ? null : $reviewReason,
                    ],
                );
                if ($automaticallyAllowed) {
                    $acceptedCount++;
                }
            } catch (Throwable $exception) {
                $reason = $this->rejectionReasonFor($exception);
                $httpStatus = $this->httpStatusFromException($exception);
                Log::warning('Product image candidate could not be downloaded or verified.', [
                    'candidate_id' => $candidate->id,
                    'provider' => $provider->name(),
                    'reason' => $reason,
                    'http_status' => $httpStatus,
                    'exception' => $exception::class,
                ]);
                $this->store->updateCandidate($candidate->id, [
                    'verification_status' => 'failed',
                    'review_reason' => $reason,
                    'rejection_reason' => $reason,
                ]);
                $this->recordRejection($batch, $task, $provider, $reason, $title, $url, $score, $licenseStatus, $candidate->id, [
                    'http_status' => $httpStatus,
                    'exception' => $exception::class,
                    'downloaded' => $exception instanceof ImageDownloadException && $exception->downloaded,
                ]);

                if ($httpStatus === 429) {
                    $this->recordProviderRequestTelemetry(
                        $batch,
                        $task,
                        $provider,
                        $providerTelemetry,
                        count($results),
                        $directUrlCount,
                        max(0, $candidateCount - $candidateCountBefore),
                    );

                    return true;
                }
            }
        }

        $this->recordProviderRequestTelemetry(
            $batch,
            $task,
            $provider,
            $providerTelemetry,
            count($results),
            $directUrlCount,
            max(0, $candidateCount - $candidateCountBefore),
        );

        return true;
    }

    /**
     * @param  list<ProductImageCandidate>  $candidates
     * @param  list<string>  $existingHashes
     */
    private function retryFailedCandidates(
        array $candidates,
        ProductImageTask $task,
        ImageSearchBatch $batch,
        array &$existingHashes,
        int &$candidateCount,
        int &$acceptedCount,
    ): void {
        if (! ($batch->settings['download_images'] ?? true)) {
            return;
        }

        $minimumConfidence = max(0, min(1, (float) config('image_finder.min_candidate_confidence')));
        $automaticConfidence = max(0, min(1, (float) config('image_finder.auto_approval_confidence')));
        $directory = 'product-images/'.$task->image_search_batch_id.'/'.$task->id;

        foreach ($candidates as $candidate) {
            if (! in_array($candidate->verification_status, ['discovered', 'failed'], true)
                || ! is_string($candidate->source_url)
                || $acceptedCount >= $task->requested_count) {
                continue;
            }

            $sourceUrl = $this->normalizeSourceUrl($candidate->source_url);
            if ($sourceUrl !== $candidate->source_url) {
                $candidate->source_url = $sourceUrl;
                $this->store->updateCandidate($candidate->id, ['source_url' => $sourceUrl]);
            }

            $licenseStatus = $candidate->license_status ?? $this->licenses->classify(
                is_string($candidate->license) ? $candidate->license : null,
                is_string($candidate->license_url) ? $candidate->license_url : null,
            );
            $automaticallyAllowed = $candidate->confidence_score >= max($minimumConfidence, $automaticConfidence)
                && ($batch->settings['auto_approve_open_licensed'] ?? true)
                && $licenseStatus === 'verified_open'
                && $this->licenses->allowsAutomaticUse(
                    $candidate->license,
                    $candidate->license_url,
                    $candidate->attribution,
                );
            $disk = $automaticallyAllowed ? config('image_finder.disk') : 'local';

            try {
                $processed = $this->downloads->downloadAndProcess($candidate, $disk, $directory);

                if (($batch->settings['deduplicate'] ?? true) && $this->verification->isDuplicate($processed['hash'], $existingHashes)) {
                    $this->downloads->delete($processed['disk'], $processed['path']);
                    $this->store->updateCandidate($candidate->id, [
                        'verification_status' => 'rejected',
                        'review_reason' => 'تصویر تکراری است.',
                        'rejection_reason' => 'duplicate_image',
                    ]);
                    $this->recordRejection(
                        $batch,
                        $task,
                        $candidate->source_name,
                        'duplicate_image',
                        is_string($candidate->title) ? $candidate->title : null,
                        $candidate->source_url,
                        (float) $candidate->confidence_score,
                        $licenseStatus,
                        $candidate->id,
                    );

                    continue;
                }

                $existingHashes[] = $processed['hash'];
                $reviewReason = $this->licenseReviewReason($licenseStatus, (float) $candidate->confidence_score, $automaticConfidence);
                $this->store->updateCandidate($candidate->id, [
                    'width' => $processed['width'],
                    'height' => $processed['height'],
                    'perceptual_hash' => $processed['hash'],
                    'local_path' => $processed['path'],
                    'metadata' => array_merge($candidate->metadata ?? [], ['storage_disk' => $processed['disk']]),
                    'verification_status' => $automaticallyAllowed ? 'verified' : 'needs_review',
                    'review_reason' => $automaticallyAllowed ? null : $reviewReason,
                ]);
                $candidateCount++;
                $this->activity(
                    $batch,
                    $task,
                    $candidate->source_name,
                    'candidate_validated',
                    'تصویر دریافت و اعتبارسنجی شد.',
                    [
                        'candidate_id' => $candidate->id,
                        'downloaded' => true,
                        'valid' => true,
                        'license_status' => $licenseStatus,
                        'confidence_score' => $candidate->confidence_score,
                        'verification_status' => $automaticallyAllowed ? 'verified' : 'needs_review',
                        'review_reason' => $automaticallyAllowed ? null : $reviewReason,
                    ],
                );

                if ($automaticallyAllowed) {
                    $acceptedCount++;
                }
            } catch (Throwable $exception) {
                $reason = $this->rejectionReasonFor($exception);
                $httpStatus = $this->httpStatusFromException($exception);
                Log::warning('Product image candidate retry failed.', [
                    'candidate_id' => $candidate->id,
                    'provider' => $candidate->source_name,
                    'reason' => $reason,
                    'http_status' => $httpStatus,
                    'exception' => $exception::class,
                ]);
                $this->store->updateCandidate($candidate->id, [
                    'verification_status' => 'failed',
                    'rejection_reason' => $reason,
                    'review_reason' => $reason,
                ]);
                $this->recordRejection(
                    $batch,
                    $task,
                    $candidate->source_name,
                    $reason,
                    is_string($candidate->title) ? $candidate->title : null,
                    $candidate->source_url,
                    (float) $candidate->confidence_score,
                    $licenseStatus,
                    $candidate->id,
                    [
                        'http_status' => $httpStatus,
                        'exception' => $exception::class,
                        'downloaded' => $exception instanceof ImageDownloadException && $exception->downloaded,
                    ],
                );

                if ($httpStatus === 429) {
                    return;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $additionalMetadata
     */
    private function recordRejection(
        ImageSearchBatch $batch,
        ProductImageTask $task,
        ImageSearchProvider|string $provider,
        string $reason,
        ?string $title,
        ?string $sourceUrl,
        ?float $confidenceScore = null,
        ?string $licenseStatus = null,
        ?string $candidateId = null,
        array $additionalMetadata = [],
    ): void {
        $providerName = is_string($provider) ? $provider : $provider->name();
        $this->queryRejectionCounts[$reason] = ($this->queryRejectionCounts[$reason] ?? 0) + 1;
        $metadata = array_merge([
            'candidate_id' => $candidateId,
            'reason' => $reason,
            'title' => $title !== null ? Str::limit($title, 255, '') : null,
            'source_url' => $sourceUrl !== null ? Str::limit($sourceUrl, 4096, '') : null,
            'confidence_score' => $confidenceScore,
            'license_status' => $licenseStatus,
        ], $additionalMetadata);

        $this->activity($batch, $task, $providerName, 'candidate_rejected', 'تصویر با دلیل '.$reason.' از پردازش خارج شد.', $metadata);
    }

    /**
     * @param  list<array<string, mixed>>  $telemetry
     * @param  array<string, int>|null  $rejectionCounts
     */
    private function recordProviderRequestTelemetry(
        ImageSearchBatch $batch,
        ProductImageTask $task,
        ImageSearchProvider $provider,
        array $telemetry,
        int $rawResults,
        int $directImageUrls,
        int $validCandidates,
        ?array $rejectionCounts = null,
    ): void {
        if ($telemetry === []) {
            return;
        }

        $lastRequest = array_key_last($telemetry);
        foreach ($telemetry as $index => $request) {
            $isSearchRequest = $index === $lastRequest;
            $metadata = array_merge($request, [
                'raw_results' => $isSearchRequest ? $rawResults : 0,
                'direct_image_urls' => $isSearchRequest ? $directImageUrls : 0,
                'valid_image_candidates' => $isSearchRequest ? $validCandidates : 0,
                'rejected' => $isSearchRequest ? array_sum($rejectionCounts ?? $this->queryRejectionCounts) : 0,
                'rejection_reasons' => $isSearchRequest ? ($rejectionCounts ?? $this->queryRejectionCounts) : [],
            ]);
            $message = isset($request['failure_type'])
                ? 'Provider request failed: '.$request['failure_type']
                : 'Provider request completed with HTTP '.($request['http_status'] ?? 'unknown').'.';

            $this->activity(
                $batch,
                $task,
                $provider->name(),
                'provider_request',
                $message,
                $metadata,
            );
        }
    }

    private function sanitizeProviderError(string $message): string
    {
        $message = preg_replace('/(https?:\/\/[^\s?]+)\?[^\s]+/i', '$1?[REDACTED]', $message) ?? $message;
        $message = preg_replace('/\b(api[_-]?key|client[_-]?secret|password|token)\b\s*[:=]\s*\S+/i', '$1=[REDACTED]', $message) ?? $message;

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 500, '');
    }

    private function isValidImageUrl(string $url): bool
    {
        if (strlen($url) > 4096 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['fragment'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }

    private function licenseReviewReason(string $licenseStatus, float $score, float $automaticConfidence): string
    {
        if ($licenseStatus === 'unknown') {
            return 'license_unknown';
        }

        if ($licenseStatus === 'restricted') {
            return 'license_restricted';
        }

        if ($score < $automaticConfidence) {
            return 'relevance_below_auto_approval_threshold';
        }

        return 'manual_review_required';
    }

    private function rejectionReasonFor(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        if ($this->httpStatusFromException($exception) !== null) {
            return 'http_error';
        }

        if (str_contains($message, 'unsupported mime')) {
            return 'unsupported_mime_type';
        }

        if (str_contains($message, 'dimensions do not meet') || str_contains($message, 'dimensions')) {
            return 'image_too_small';
        }

        $isImageDecodeError = str_contains($message, 'signature') || str_contains($message, 'safely decoded');

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $isImageDecodeError = $isImageDecodeError || $current instanceof \ImagickException;
        }

        if ($isImageDecodeError) {
            return 'invalid_image_content';
        }

        if (str_contains($message, 'processed') || str_contains($message, 'write the processed')) {
            return 'image_processing_failed';
        }

        return 'download_failed';
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

    private function normalizeSourceUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'thumb.wikimedia.org'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['fragment'])) {
            return $url;
        }

        // The thumbnail host redirects robots.txt, while the equivalent upload URL serves it directly.
        return 'https://upload.wikimedia.org'.($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function currentBatchStatus(string $batchId): ?string
    {
        return $this->store->batch($batchId)?->status;
    }

    private function activity(
        ImageSearchBatch $batch,
        ProductImageTask $task,
        ?string $provider,
        string $event,
        string $message,
        array $metadata = [],
    ): void {
        $this->store->activity($batch->id, $task->id, $provider, $event, $message, $metadata);
    }
}

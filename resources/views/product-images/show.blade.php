@extends('layouts.product-images')

@section('title', 'گزارش جست‌وجو · جست‌وجوگر تصاویر محصول')

@section('content')
@php
    $statusLabels = [
        'queued' => 'در صف',
        'processing' => 'در حال پردازش',
        'verified' => 'تأییدشده',
        'needs_review' => 'نیازمند بازبینی',
        'completed' => 'کامل',
        'completed_with_warnings' => 'کامل با هشدار',
        'failed' => 'ناموفق',
        'cancelled' => 'لغوشده',
    ];
    $providerStatusLabels = [
        'ready' => 'آمادهٔ جست‌وجو',
        'public' => 'دسترسی عمومی؛ نیازی به اعتبارنامه نیست',
        'anonymous' => 'دسترسی عمومی؛ بدون اعتبارنامه',
        'missing_credentials' => 'اعتبارنامهٔ لازم موجود نیست',
        'disabled' => 'غیرفعال',
        'not_reported' => 'وضعیت ثبت نشده',
    ];
    $licenseStatusLabels = [
        'verified_open' => 'مجوز باز تأییدشده',
        'unknown' => 'مجوز نامشخص',
        'restricted' => 'مجوز محدود',
    ];
    $reviewReasonLabels = [
        'license_unknown' => 'مجوز نامشخص است؛ بازبینی دستی لازم است.',
        'license_restricted' => 'مجوز محدود است؛ تأیید خودکار انجام نشد.',
        'relevance_below_auto_approval_threshold' => 'تطبیق محصول برای تأیید خودکار کافی نیست.',
        'manual_review_required' => 'بازبینی دستی لازم است.',
    ];
    $eventLabels = [
        'batch_queued' => 'ثبت دسته',
        'batch_resumed' => 'ادامهٔ پردازش',
        'batch_cancelled' => 'توقف دسته',
        'product_started' => 'شروع محصول',
        'product_retry' => 'تلاش دوباره',
        'product_failed' => 'خطای محصول',
        'provider_configured' => 'منبع آماده',
        'provider_auth_missing' => 'منبع بدون اعتبارنامه',
        'provider_public_access' => 'منبع عمومی',
        'provider_skipped' => 'منبع اجرا نشد',
        'candidate_approved' => 'تأیید تصویر',
        'candidate_rejected' => 'رد تصویر',
        'candidate_validated' => 'تصویر اعتبارسنجی شد',
        'search_completed' => 'پایان جست‌وجو',
        'provider_failed' => 'خطای منبع',
        'provider_throttled' => 'محدودیت منبع',
        'budget_exhausted' => 'پایان سقف جست‌وجو',
        'warning' => 'هشدار',
        'verified' => 'تأیید خودکار',
        'needs_review' => 'نیازمند بازبینی',
    ];
@endphp
<div class="title">
    <div>
        <span class="section-kicker">گزارش دستهٔ جست‌وجو</span>
        <h1>گزارش جست‌وجو</h1>
        <div class="statusline"><span class="badge {{ $batch->status }}">{{ $statusLabels[$batch->status] ?? $batch->status }}</span><span class="batch-id">{{ $batch->id }}</span></div>
    </div>
    <div class="actions">
        <a class="button secondary" href="{{ route('image-finder.batches.export', $batch->id) }}"><span aria-hidden="true">↓</span> دریافت خروجی JSON</a>
        @if($summary['downloadable_images'] > 0)
            <a class="button secondary" href="{{ route('image-finder.batches.archive', $batch->id) }}"><span aria-hidden="true">▧</span> دانلود عکس‌ها ({{ $summary['downloadable_images'] }})</a>
        @endif
        @if(in_array($batch->status, ['queued', 'processing'], true))
            <form method="post" action="{{ route('image-finder.batches.cancel', $batch->id) }}" data-confirm="پردازش این جست‌وجو متوقف شود؟ نتیجه‌های فعلی حفظ می‌شوند.">@csrf<button class="button danger" type="submit">توقف پردازش</button></form>
        @endif
        @unless(in_array($batch->status, ['queued', 'processing'], true))
            <form method="post" action="{{ route('image-finder.batches.destroy', $batch->id) }}" data-confirm="این جست‌وجو و فایل‌های مرتبط حذف شوند؟ این کار قابل بازگشت نیست.">
                @csrf
                @method('DELETE')
                <button class="button danger" type="submit">حذف جست‌وجو</button>
            </form>
        @endunless
        @if(in_array($batch->status, ['completed_with_warnings', 'failed', 'cancelled', 'processing'], true))
            <form method="post" action="{{ route('image-finder.batches.resume', $batch->id) }}">@csrf<button class="button" type="submit">تلاش دوباره برای موارد ناتمام</button></form>
        @endif
    </div>
</div>
<section class="report-stats" aria-label="خلاصهٔ نتایج">
    <div class="report-stat"><span class="report-stat-icon stat-verified" aria-hidden="true">✓</span><span class="report-stat-label">تصویر تأییدشده</span><strong>{{ $summary['verified'] }}</strong></div>
    <div class="report-stat"><span class="report-stat-icon stat-review" aria-hidden="true">◷</span><span class="report-stat-label">تصویر در انتظار بازبینی</span><strong>{{ $summary['needs_review'] }}</strong></div>
    <div class="report-stat"><span class="report-stat-icon stat-failed" aria-hidden="true">!</span><span class="report-stat-label">تصویر ناموفق</span><strong>{{ $summary['failed'] }}</strong></div>
    <div class="report-stat"><span class="report-stat-icon stat-total" aria-hidden="true">▦</span><span class="report-stat-label">کل محصولات</span><strong>{{ $summary['total'] }}</strong></div>
</section>
<section class="card">
    <div class="card-heading"><div><span class="section-kicker">اندازه‌گیری واقعی هر منبع</span><h2>گزارش منابع و علت‌های رد</h2></div></div>
    <div class="table-wrap">
        <table class="history-table">
            <thead>
                <tr>
                    <th>منبع</th>
                    <th>وضعیت</th>
                    <th>درخواست‌ها</th>
                    <th>نشانی API</th>
                    <th>HTTP</th>
                    <th>حجم پاسخ</th>
                    <th>خطاهای منبع</th>
                    <th>علت عدم فراخوانی</th>
                    <th>میانگین زمان پاسخ</th>
                    <th>نتیجهٔ خام / نشانی مستقیم</th>
                    <th>دریافت / معتبر</th>
                    <th>تأیید / بازبینی</th>
                    <th>ردشده و علت بازبینی دستی</th>
                </tr>
            </thead>
            <tbody>
                @forelse($providerDiagnostics as $provider)
                    <tr>
                        <td>{{ $provider['name'] }}</td>
                        <td>{{ $providerStatusLabels[$provider['status']] ?? $provider['status'] }}</td>
                        <td>{{ $provider['requests'] }}</td>
                        <td>
                            @forelse(array_slice($provider['request_urls'], 0, 3) as $requestUrl)
                                <div class="muted small">{{ $requestUrl }}</div>
                            @empty
                                —
                            @endforelse
                        </td>
                        <td>{{ $provider['http_statuses'] !== [] ? implode(', ', $provider['http_statuses']) : '—' }}</td>
                        <td>{{ number_format($provider['response_bytes']) }} bytes</td>
                        <td>
                            @forelse($provider['errors'] as $error)
                                <div class="muted small">{{ $error }}</div>
                            @empty
                                —
                            @endforelse
                        </td>
                        <td>{{ $provider['skip_reasons'] !== [] ? implode(', ', $provider['skip_reasons']) : '—' }}</td>
                        <td>{{ $provider['average_response_ms'] !== null ? number_format($provider['average_response_ms']).' ms' : '—' }}</td>
                        <td>{{ $provider['raw_results'] }} / {{ $provider['direct_image_urls'] }}</td>
                        <td>{{ $provider['downloaded'] }} / {{ $provider['valid_images'] }}</td>
                        <td>{{ $provider['approved'] }} / {{ $provider['pending_review'] }}</td>
                        <td>
                            <strong>{{ $provider['rejected'] }} ردشده</strong>
                            @if($provider['rejection_reasons'] !== [])
                                <div class="muted small">
                                    @foreach($provider['rejection_reasons'] as $reason => $count)
                                        <div>{{ $reason }}: {{ $count }}</div>
                                    @endforeach
                                </div>
                            @endif
                            @if($provider['review_reasons'] !== [])
                                <div class="muted small">
                                    <strong>بازبینی دستی:</strong>
                                    @foreach($provider['review_reasons'] as $reason => $count)
                                        <div>{{ $reason }}: {{ $count }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="muted">هنوز گزارشی از منابع ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@if($summary['provider_failed'] > 0 || $summary['provider_throttled'] > 0 || $summary['candidate_rejected'] > 0 || $activeProviderNames === [])
    <section class="diagnostic" role="status">
        <div class="diagnostic-icon" aria-hidden="true">i</div>
        <div class="diagnostic-content">
            <strong>وضعیت منابع جست‌وجو</strong>
            <p>
                @if($summary['provider_failed'] > 0)
                    {{ $summary['provider_failed'] }} درخواست منبع ناموفق یا به سقف مجاز برخورد کرده است.
                @endif
                @if($summary['provider_throttled'] > 0)
                    {{ $summary['provider_throttled'] }} درخواست به‌دلیل محدودیت موقت، در صف تلاش دوباره قرار گرفته است.
                @endif
                @if($summary['candidate_rejected'] > 0)
                    دریافت یا بررسی فنی {{ $summary['candidate_rejected'] }} تصویر ناموفق بوده است.
                @endif
                @if($activeProviderNames === [])
                    در حال حاضر هیچ منبع جست‌وجویی پیکربندی نشده است.
                @else
                    منابع فعال: {{ implode('، ', $activeProviderNames) }}.
                @endif
            </p>
            @if(count($activeProviderNames) < 2)
                <p class="diagnostic-hint">برای پوشش گسترده‌تر محصولات، منابع دیگر را همراه با کلید دسترسی‌شان در تنظیمات سرور فعال کنید.</p>
            @endif
        </div>
    </section>
@endif
<section class="card">
    <div class="card-heading"><div><span class="section-kicker">نتیجهٔ تک‌تک کالاها</span><h2>محصول‌ها</h2></div><span class="muted small">{{ $tasks->total() }} محصول</span></div>
    @forelse($tasks as $task)
        <article class="product">
            <div class="product-heading">
                <div><strong>{{ $task->name }}</strong><div class="muted small">{{ collect([$task->brand, $task->model, $task->category])->filter()->join(' · ') }}</div></div>
                <div class="right"><span class="badge {{ $task->status }}">{{ $statusLabels[$task->status] ?? $task->status }}</span><span class="image-count">{{ collect($task->candidates)->where('verification_status', 'verified')->count() }} از {{ $task->requested_count }} تصویر تأییدشده</span></div>
            </div>
            @if($task->error)<p class="error">{{ $task->error }}</p>@endif
            @php($images = collect($task->candidates)->whereIn('verification_status', ['verified', 'needs_review'])->whereNotNull('local_path'))
            @php($failedImages = collect($task->candidates)->where('verification_status', 'failed'))
            @php($downloadDisabled = collect($task->candidates)->contains(fn ($candidate) => $candidate->review_reason === 'دریافت تصویر در تنظیمات این دسته غیرفعال است.'))
            @if($images->isNotEmpty())
                <div class="images">
                    @foreach($images as $candidate)
                        <div class="image">
                            <a href="{{ route('image-finder.candidates.preview', $candidate->id) }}" target="_blank" rel="noopener"><img src="{{ route('image-finder.candidates.preview', $candidate->id) }}" alt="{{ $candidate->title ?? $task->name }}"></a>
                            <div class="image-title">{{ Str::limit($candidate->title ?? $candidate->source_name, 65) }}</div>
                            <div class="muted small">{{ $candidate->source_name }} · امتیاز ارتباط {{ number_format($candidate->confidence_score, 2) }}</div>
                            <div class="muted small">{{ $licenseStatusLabels[$candidate->license_status] ?? $licenseStatusLabels['unknown'] }}</div>
                            <div class="muted small">{{ $candidate->license ?: 'مجوز استفاده تأیید نشده است' }}</div>
                            @if($candidate->attribution)<div class="muted small">ذکر منبع: {{ $candidate->attribution }}</div>@endif
                            @if($candidate->license_url && \Illuminate\Support\Str::startsWith(strtolower($candidate->license_url), 'https://'))<a class="small" href="{{ $candidate->license_url }}" target="_blank" rel="noopener noreferrer">مشاهدهٔ شرایط مجوز</a>@endif
                            @if($candidate->review_reason)<p class="help">{{ $reviewReasonLabels[$candidate->review_reason] ?? $candidate->review_reason }}</p>@endif
                            @if($candidate->verification_status === 'needs_review')
                                <div class="actions image-actions">
                                    <form method="post" action="{{ route('image-finder.candidates.approve', $candidate->id) }}">@csrf<button class="button" type="submit">تأیید تصویر</button></form>
                                    <form method="post" action="{{ route('image-finder.candidates.reject', $candidate->id) }}">@csrf<button class="button secondary" type="submit">رد تصویر</button></form>
                                </div>
                                <p class="help">تأیید دستی، مجوز انتشار را تضمین نمی‌کند.</p>
                            @endif
                            @if($candidate->source_page_url && \Illuminate\Support\Str::startsWith(strtolower($candidate->source_page_url), 'https://'))<a class="small" href="{{ $candidate->source_page_url }}" target="_blank" rel="noopener noreferrer">صفحهٔ منبع</a>@endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="product-empty">
                    <span class="product-empty-icon" aria-hidden="true">{{ $failedImages->isNotEmpty() ? '!' : '⌕' }}</span>
                    <div>
                        <strong>{{ $failedImages->isNotEmpty() ? 'تصویر پیدا شد، اما دریافت یا بررسی آن ناموفق بود.' : ($downloadDisabled ? 'نتیجه پیدا شد؛ دریافت تصویر در تنظیمات خاموش است.' : 'در این نوبت تصویری برای نمایش ثبت نشد.') }}</strong>
                        <p>{{ $failedImages->isNotEmpty() ? 'با تلاش دوباره، دریافت تصویرها از سر گرفته می‌شود.' : ($downloadDisabled ? 'برای نمایش و بررسی تصویر، download_images را در فایل JSON فعال کن.' : 'منابع فعال یا محدودیت درخواست ممکن است روی نتیجه اثر گذاشته باشند.') }}</p>
                        @foreach($failedImages as $failedImage)
                            <p class="help">{{ $failedImage->title ?? $failedImage->source_name }}: {{ $failedImage->rejection_reason ?? 'download_failed' }}</p>
                        @endforeach
                    </div>
                </div>
            @endif
        </article>
    @empty
        <p class="muted">محصولی در این دسته ثبت نشده است.</p>
    @endforelse
    @if($tasks->hasPages())
        <nav class="pagination" aria-label="صفحه‌بندی">{{ $tasks->links() }}</nav>
    @endif
</section>
<section class="card">
    <h2>گزارش فعالیت منابع</h2>
    @forelse($activities as $activity)
        <div class="activity">
            <span class="muted">{{ \Illuminate\Support\Carbon::parse($activity->created_at)->locale('fa')->diffForHumans() }}</span>
            <strong>{{ $activity->provider ?? ($eventLabels[$activity->event] ?? $activity->event) }}</strong>
            <span>{{ $activity->display_message }}</span>
        </div>
    @empty
        <p class="muted">هنوز فعالیتی ثبت نشده است.</p>
    @endforelse
</section>
@endsection

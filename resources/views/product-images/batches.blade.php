@extends('layouts.product-images')

@section('title', 'تاریخچهٔ جست‌وجوها · جست‌وجوگر تصاویر محصول')

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
@endphp
<section class="history-hero">
    <div>
        <span class="section-kicker">مدیریت جست‌وجوها</span>
        <h1>تاریخچهٔ جست‌وجوها</h1>
        <p>وضعیت و نتیجهٔ جست‌وجوهای قبلی را ببین یا مواردی را که دیگر لازم نداری پاک کن.</p>
    </div>
    <div class="history-actions">
        <a class="button secondary" href="{{ route('image-finder.index') }}">بازگشت به صفحهٔ اصلی</a>
        @if($batches !== [] && $activeBatches === 0)
            <form method="post" action="{{ route('image-finder.batches.destroy-all') }}" data-confirm="همهٔ جست‌وجوهای تاریخچه و فایل‌های مرتبط حذف شوند؟ این کار قابل بازگشت نیست.">
                @csrf
                @method('DELETE')
                <button class="button danger" type="submit">پاک‌کردن تاریخچه</button>
            </form>
        @endif
    </div>
</section>

@if($activeBatches > 0)
    <div class="notice notice-soft" role="status">برای پاک‌کردن تاریخچه، ابتدا {{ number_format($activeBatches) }} پردازش فعال را از صفحهٔ جزئیات متوقف کن.</div>
@endif

<section class="history-stats" aria-label="خلاصهٔ تاریخچه">
    <article class="history-stat"><span class="history-stat-icon">⌕</span><span>کل جست‌وجوها</span><strong>{{ number_format(count($batches)) }}</strong></article>
    <article class="history-stat"><span class="history-stat-icon">▦</span><span>محصول‌های بررسی‌شده</span><strong>{{ number_format($totalProducts) }}</strong></article>
    <article class="history-stat"><span class="history-stat-icon">✓</span><span>جست‌وجوهای پایان‌یافته</span><strong>{{ number_format($completedBatches) }}</strong></article>
</section>

<section class="card history-card">
    <div class="card-heading">
        <div><span class="section-kicker">فهرست کامل</span><h2>همهٔ جست‌وجوها</h2></div>
        <span class="muted small">{{ number_format(count($batches)) }} مورد</span>
    </div>
    @if($batches === [])
        <div class="empty-state">
            <span class="empty-icon">⌕</span>
            <strong>تاریخچه خالی است</strong>
            <p>بعد از ثبت اولین جست‌وجو، وضعیت آن را همین‌جا می‌بینی.</p>
            <a class="button" href="{{ route('image-finder.index') }}">شروع جست‌وجوی جدید</a>
        </div>
    @else
        <div class="table-wrap">
            <table class="history-table">
                <thead><tr><th>جست‌وجو</th><th>وضعیت</th><th>محصول‌ها</th><th>زمان ثبت</th><th>عملیات</th></tr></thead>
                <tbody>
                @foreach($batches as $batch)
                    <tr>
                        <td>
                            <a class="batch-link" href="{{ route('image-finder.batches.show', $batch->id) }}">
                                <span class="batch-icon" aria-hidden="true">✳</span>
                                <span><strong>جست‌وجوی محصول</strong><small class="history-id">{{ Str::limit($batch->id, 14) }}</small></span>
                            </a>
                        </td>
                        <td><span class="badge {{ $batch->status }}">{{ $statusLabels[$batch->status] ?? $batch->status }}</span></td>
                        <td>{{ number_format($batch->task_count) }} محصول</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($batch->created_at)->locale('fa')->diffForHumans() }}</td>
                        <td>
                            <div class="history-row-actions">
                                <a class="table-action" href="{{ route('image-finder.batches.show', $batch->id) }}">مشاهدهٔ نتیجه</a>
                                @if($batch->downloadable_count > 0)
                                    <a class="table-action" href="{{ route('image-finder.batches.archive', $batch->id) }}">دانلود عکس‌ها</a>
                                @endif
                                @if(in_array($batch->status, ['queued', 'processing'], true))
                                    <form method="post" action="{{ route('image-finder.batches.cancel', $batch->id) }}" data-confirm="پردازش این جست‌وجو متوقف شود؟ نتیجه‌های فعلی حفظ می‌شوند.">
                                        @csrf
                                        <button class="history-delete" type="submit">لغو فرایند</button>
                                    </form>
                                @else
                                    <form method="post" action="{{ route('image-finder.batches.destroy', $batch->id) }}" data-confirm="این جست‌وجو و فایل‌های مرتبط حذف شوند؟ این کار قابل بازگشت نیست.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="history-delete" type="submit" aria-label="حذف این جست‌وجو">حذف</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection

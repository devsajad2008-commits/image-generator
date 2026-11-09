@extends('layouts.product-images')

@section('title', 'جست‌وجوگر تصاویر محصول')

@section('content')
<section class="hero">
    <div class="hero-copy">
        <span class="eyebrow"><span class="eyebrow-dot"></span> استودیوی جست‌وجوی تصویر</span>
        <h1>تصویر درستِ محصولت<br><span>فقط چند قدم فاصله دارد.</span></h1>
        <p>محصول‌ها را معرفی کن، تعداد تصویرها را انتخاب کن و نتیجه‌ها را یک‌جا تحویل بگیر.</p>
        <div class="hero-points"><span>جست‌وجو در چند منبع</span><span>بررسی تطبیق تصویر</span><span>خروجی آمادهٔ استفاده</span></div>
    </div>
    <div class="hero-art" aria-hidden="true">
        <div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div>
        <div class="art-card art-card-back"><span>✦</span></div>
        <div class="art-card art-card-front"><span class="art-sun"></span><span class="art-mountain mountain-one"></span><span class="art-mountain mountain-two"></span><span class="art-glass">⌕</span></div>
        <span class="art-spark spark-one">✦</span><span class="art-spark spark-two">✧</span>
    </div>
</section>

<section class="wizard-shell">
    <div class="wizard-heading">
        <div><span class="section-kicker">شروع یک جست‌وجوی تازه</span><h2>اطلاعات را وارد کن</h2></div>
        <span class="secure-note"><span aria-hidden="true">●</span> ساده، سریع و دقیق</span>
    </div>

    <form method="post" action="{{ route('image-finder.batches.store') }}" enctype="multipart/form-data" class="wizard" data-wizard>
        @csrf
        <nav class="stepper" aria-label="مراحل ثبت جست‌وجو">
            <div class="stepper-item is-active" data-step-indicator="0"><span class="step-number">۱</span><span class="step-label">معرفی محصول</span></div>
            <span class="stepper-line"></span>
            <div class="stepper-item" data-step-indicator="1"><span class="step-number">۲</span><span class="step-label">تنظیم تصویرها</span></div>
            <span class="stepper-line"></span>
            <div class="stepper-item" data-step-indicator="2"><span class="step-number">۳</span><span class="step-label">بازبینی و ثبت</span></div>
        </nav>

        <section class="wizard-step" data-wizard-step="0" aria-labelledby="step-title-0">
            <div class="step-intro"><span class="step-caption">قدم اول از سه</span><h3 id="step-title-0">از کجا شروع کنیم؟</h3><p>یک محصول، فهرستی از محصولات یا فایل JSON وارد کن.</p></div>
            <div class="mode-options" role="radiogroup" aria-label="روش افزودن محصول">
                <label class="mode-option is-selected"><input type="radio" name="input_mode" value="single" checked><span class="mode-icon">✳</span><span class="mode-copy"><strong>یک محصول</strong><small>مشخصات یک کالا را وارد کن</small></span><span class="mode-check">✓</span></label>
                <label class="mode-option"><input type="radio" name="input_mode" value="bulk"><span class="mode-icon">☷</span><span class="mode-copy"><strong>فهرست محصولات</strong><small>چند نام، هرکدام در یک خط</small></span><span class="mode-check">✓</span></label>
                <label class="mode-option"><input type="radio" name="input_mode" value="file"><span class="mode-icon">↥</span><span class="mode-copy"><strong>فایل JSON</strong><small>ورود گروهی با فایل</small></span><span class="mode-check">✓</span></label>
            </div>

            <aside class="writing-guide" aria-labelledby="writing-guide-title">
                <div class="writing-guide-heading">
                    <span class="writing-guide-icon" aria-hidden="true">✦</span>
                    <div><strong id="writing-guide-title">چطور بنویسم تا نتیجهٔ دقیق‌تری بگیرم؟</strong><p>عبارت‌های کوتاه و مشخص، جست‌وجوی تصویر محصول را بهتر راهنمایی می‌کنند.</p></div>
                </div>
                <ul class="writing-guide-tips">
                    <li><strong>نام، برند و مدل دقیق</strong> را وارد کن؛ مثل <bdi dir="ltr">Sony WH-1000XM5</bdi>.</li>
                    <li>دسته‌بندی را کوتاه بنویس و فقط ویژگی متمایزکننده‌ای مثل رنگ یا ظرفیت را به توضیحات اضافه کن.</li>
                    <li>از عبارت‌های مبهم یا تبلیغاتی مثل «بهترین هدفون» پرهیز کن؛ در فهرست، هر محصول را در یک خط جدا بنویس.</li>
                </ul>
                <p class="writing-guide-example"><span>دقیق‌تر:</span> <bdi dir="ltr">Samsung Galaxy A55 5G</bdi><span>مبهم:</span> گوشی سامسونگ خوب</p>
            </aside>

            <div class="mode-panel" data-mode-panel="single">
                <div class="field"><label for="product_name">نام محصول <span class="required-mark">*</span></label><input id="product_name" name="product_name" value="{{ old('product_name') }}" placeholder="مثلاً: هدفون Sony WH-1000XM5" autocomplete="off"><p class="help">نام دقیق‌تر، نتیجه‌های مرتبط‌تری می‌سازد.</p></div>
                <div class="grid">
                    <div class="field"><label for="brand">برند <span class="optional">(اختیاری)</span></label><input id="brand" name="brand" value="{{ old('brand') }}" placeholder="مثلاً: Sony"></div>
                    <div class="field"><label for="model">شمارهٔ مدل <span class="optional">(اختیاری)</span></label><input id="model" name="model" value="{{ old('model') }}" placeholder="مثلاً: WH-1000XM5"></div>
                    <div class="field"><label for="category">دسته‌بندی <span class="optional">(اختیاری)</span></label><input id="category" name="category" value="{{ old('category') }}" placeholder="مثلاً: هدفون"></div>
                    <div class="field"><label for="description">توضیحات کوتاه <span class="optional">(اختیاری)</span></label><input id="description" name="description" value="{{ old('description') }}" placeholder="رنگ، ظرفیت یا ویژگی خاص"></div>
                </div>
            </div>
            <div class="mode-panel" data-mode-panel="bulk">
                <div class="field"><label for="product_names">نام محصولات <span class="required-mark">*</span></label><textarea id="product_names" name="product_names" placeholder="Philips Air Fryer HD9200&#10;Sony WH-1000XM5&#10;Samsung Galaxy A55">{{ old('product_names') }}</textarea><p class="help">هر محصول را در یک خط بنویس. حداکثر {{ number_format(config('image_finder.max_products_per_batch')) }} محصول در هر جست‌وجو.</p></div>
            </div>
            <div class="mode-panel" data-mode-panel="file">
                <div class="field"><label for="products_file">فایل فهرست محصولات <span class="required-mark">*</span></label><label class="upload-zone" for="products_file"><span class="upload-icon">↥</span><strong>برای انتخاب فایل کلیک کن</strong><span>JSON · حداکثر ۵ مگابایت</span><span class="file-name" data-file-name>هنوز فایلی انتخاب نشده</span></label><input class="file-input" id="products_file" name="products_file" type="file" accept=".json,application/json"><p class="help">فهرست ساده یا شیء دارای products و settings پذیرفته می‌شود. برای هر محصول می‌توانی عبارت‌های دقیق جست‌وجو را در search_terms بگذاری.</p></div>
            </div>
            <p class="wizard-error" data-wizard-error role="alert" hidden></p>
        </section>

        <section class="wizard-step" data-wizard-step="1" aria-labelledby="step-title-1">
            <div class="step-intro"><span class="step-caption">قدم دوم از سه</span><h3 id="step-title-1">چند تصویر لازم داری؟</h3><p>تعداد تصویرهای درخواستی برای هر محصول را انتخاب کن.</p></div>
            <div class="count-picker">
                <label for="images_per_product">تصویر برای هر محصول</label>
                <div class="count-control"><span class="count-icon" aria-hidden="true">▧</span><input id="images_per_product" name="images_per_product" type="number" min="1" max="10" value="{{ old('images_per_product', config('image_finder.default_count')) }}"><span class="count-unit">تصویر</span></div>
                <p class="help">می‌توانی بین ۱ تا ۱۰ تصویر انتخاب کنی. تصویرهای تأییدشده برای دریافت آماده می‌شوند.</p>
                <div class="quality-note"><span class="quality-icon">✦</span><span><strong>کیفیت، اولویت ماست</strong><small>تطبیق محصول و وضعیت مجوز بررسی می‌شود؛ موارد نامطمئن برای بازبینی می‌مانند.</small></span></div>
            </div>
            <p class="wizard-error" data-wizard-error role="alert" hidden></p>
        </section>

        <section class="wizard-step" data-wizard-step="2" aria-labelledby="step-title-2">
            <div class="step-intro"><span class="step-caption">قدم سوم از سه</span><h3 id="step-title-2">همه‌چیز آماده است؟</h3><p>خلاصهٔ جست‌وجویت را بررسی و ثبت کن.</p></div>
            <div class="review-card">
                <div class="review-icon" aria-hidden="true">✦</div>
                <div><span class="review-label">روش ورود اطلاعات</span><strong data-review-mode>یک محصول</strong></div>
                <div><span class="review-label">محصول / محصولات</span><strong data-review-products>—</strong></div>
                <div><span class="review-label">تصویر برای هر محصول</span><strong><span data-review-count>{{ old('images_per_product', config('image_finder.default_count')) }}</span> تصویر</strong></div>
            </div>
            <div class="notice notice-soft"><span class="notice-icon" aria-hidden="true">i</span><span>فقط تصویرهای دارای تطبیق و مجوز روشن خودکار تأیید می‌شوند؛ سایر موارد برای بازبینی نمایش داده خواهند شد.</span></div>
            <p class="wizard-error" data-wizard-error role="alert" hidden></p>
        </section>

        <div class="wizard-footer">
            <button type="button" class="button secondary" data-wizard-back hidden>بازگشت</button>
            <span class="wizard-hint" data-wizard-hint>اطلاعات محصولت را وارد کن تا ادامه بدهیم.</span>
            <button type="button" class="button" data-wizard-next>ادامه <span aria-hidden="true">←</span></button>
            <button type="submit" class="button submit-button" data-wizard-submit hidden>ثبت و شروع جست‌وجو <span aria-hidden="true">✦</span></button>
        </div>
    </form>
</section>

<section class="recent-section">
    <div class="section-heading"><div><span class="section-kicker">همه‌چیز در یک نگاه</span><h2>جست‌وجوهای اخیر</h2></div><div class="recent-tools"><span class="recent-count">{{ count($batches) }} جست‌وجو</span><a class="table-action" href="{{ route('image-finder.batches.index') }}">مشاهدهٔ تاریخچهٔ کامل ←</a></div></div>
    <div class="card recent-card">
        @if($batches === [])
            <div class="empty-state"><span class="empty-icon">⌕</span><strong>هنوز جست‌وجویی ثبت نشده</strong><p>اولین جست‌وجو را بساز تا نتیجه‌هایت اینجا نمایش داده شوند.</p></div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>شناسهٔ جست‌وجو</th><th>وضعیت</th><th>محصول‌ها</th><th>زمان ثبت</th><th></th></tr></thead>
                <tbody>
                @foreach($batches as $batch)
                    <tr>
                        <td><a class="batch-link" href="{{ route('image-finder.batches.show', $batch->id) }}"><span class="batch-icon">✳</span>{{ Str::limit($batch->id, 14) }}</a></td>
                        <td><span class="badge {{ $batch->status }}">{{ ['queued' => 'در صف', 'processing' => 'در حال پردازش', 'verified' => 'تأییدشده', 'needs_review' => 'نیازمند بازبینی', 'completed' => 'کامل', 'completed_with_warnings' => 'کامل با هشدار', 'failed' => 'ناموفق', 'cancelled' => 'لغوشده'][$batch->status] ?? $batch->status }}</span></td>
                        <td>{{ $batch->task_count }} محصول</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($batch->created_at)->locale('fa')->diffForHumans() }}</td>
                        <td>
                            <div class="history-row-actions">
                                <a class="table-action" href="{{ route('image-finder.batches.show', $batch->id) }}">مشاهدهٔ جزئیات <span aria-hidden="true">←</span></a>
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
                                        <button class="history-delete" type="submit">حذف</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</section>

<section class="worker-tip"><span class="worker-tip-icon">⌘</span><p><strong>پردازش پس‌زمینه</strong><span>برای شروع پردازش جست‌وجوها، دستور <code>php artisan image-finder:work</code> را در یک ترمینال جداگانه اجرا کن.</span></p></section>
@endsection

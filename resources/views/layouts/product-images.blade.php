<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#15192d">
    <title>@yield('title', 'جست‌وجوگر تصاویر محصول')</title>
    @if(isset($batch) && in_array($batch->status, ['queued', 'processing'], true))
        <meta http-equiv="refresh" content="12">
    @endif
    {{ Vite::fonts('vazirmatn') }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<header>
    <a class="brand" href="{{ route('image-finder.index') }}"><span class="brand-mark" aria-hidden="true">ع</span><span>استودیو تصویر<span class="brand-subtitle">جست‌وجوی هوشمند محصول</span></span></a>
    <span class="header-caption"><span class="live-dot"></span> جست‌وجو و گردآوری تصاویر محصول</span>
    <nav class="site-nav" aria-label="ناوبری اصلی">
        <a href="{{ route('image-finder.index') }}" @if(request()->routeIs('image-finder.index')) aria-current="page" @endif>جست‌وجوی جدید</a>
        <a href="{{ route('image-finder.batches.index') }}" @if(request()->routeIs('image-finder.batches.*')) aria-current="page" @endif>تاریخچه</a>
    </nav>
</header>
<main>
    @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    @yield('content')
</main>
<footer><span>ساخته‌شده برای جست‌وجوی دقیق‌تر تصاویر محصول</span><a href="https://sajadfarghyari.ir" target="_blank" rel="noopener noreferrer">sajadfarghyari.ir</a></footer>
</body>
</html>

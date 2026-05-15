@php
    // pickup هرگز @vite نزند: با APP_URL=http خروجی http://… می‌دهد → روی صفحهٔ https مرورگر asset را بلوک می‌کند (mixed content).
    $viteHot = app()->isLocal()
        && is_file(public_path('hot'))
        && ! config('app.share_pickup_only');
    $fromManifest = \App\Support\ServiceShareViteManifest::entryUrls();
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl" @if ($viteHot) data-vpnmarket-share-assets="vite-dev" @elseif ($fromManifest) data-vpnmarket-share-assets="manifest" @else data-vpnmarket-share-assets="cdn" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>دریافت اشتراک — {{ \App\Services\ServiceShareService::publicDisplayTypingPath() }}</title>
    {{-- تشخیص دیپلوی در View Source: باید manifest باشد نه cdn --}}
    @if ($viteHot)
        <!-- vpnmarket-share-assets: vite-dev -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @elseif ($fromManifest)
        <!-- vpnmarket-share-assets: manifest {{ $fromManifest['css'] }} -->
        {{-- هم‌مبدأ با صفحه — مستقل از APP_URL اشتباه در .env --}}
        <link rel="preload" href="{{ $fromManifest['css'] }}" as="style">
        <link rel="stylesheet" href="{{ $fromManifest['css'] }}">
    @else
        <!-- vpnmarket-share-assets: cdn-fallback -->
        {{-- فقط بدون بیلد (بدون Docker / بدون npm run build) --}}
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: {
                            sans: ['Vazirmatn', 'Tahoma', 'Segoe UI', 'system-ui', 'sans-serif'],
                        },
                    },
                },
            };
        </script>
    @endif
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=vazirmatn:300,400,500,600,700&display=swap" rel="stylesheet">
    {{-- فونت فارسی؛ با !important روی CSS اپ اصلی هم غلبه می‌کند --}}
    <style>
        html, body {
            font-family: 'Vazirmatn', Tahoma, 'Segoe UI', system-ui, sans-serif !important;
        }
    </style>
</head>
<body class="font-sans antialiased text-slate-100 min-h-screen selection:bg-indigo-500/30">
    {{ $slot }}
    @if ($fromManifest && ! $viteHot)
        <script type="module" src="{{ $fromManifest['js'] }}"></script>
    @endif
    @stack('scripts')
</body>
</html>

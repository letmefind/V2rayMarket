@php
    // مثل سرور معمولی: nginx فایل‌های public/build را مستقیم می‌دهد — لینک نسبی /build/...
    // بدون @vite تا با ASSET_URL/پروکسی، URL اشتباه نشود. با public/hot همان HMR @vite.
    $viteHot = is_file(public_path('hot'));
    $fromManifest = \App\Support\ServiceShareViteManifest::entryUrls();
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>دریافت اشتراک — {{ \App\Services\ServiceShareService::publicDisplayTypingPath() }}</title>
    @if ($viteHot)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @elseif ($fromManifest)
        {{-- URL مطلق با scheme دامنه (APP_URL + TrustProxies) — جلو mixed content / Not Secure --}}
        <link rel="stylesheet" href="{{ url($fromManifest['css']) }}">
        <script type="module" src="{{ url($fromManifest['js']) }}"></script>
    @else
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
    @stack('scripts')
</body>
</html>

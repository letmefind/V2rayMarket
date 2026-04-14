@php
    $viteBuilt = is_file(public_path('hot'))
        || is_file(public_path('build/manifest.json'))
        || is_file(public_path('build/.vite/manifest.json'));
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>دریافت اشتراک — {{ \App\Services\ServiceShareService::publicDisplayTypingPath() }}</title>
    @if ($viteBuilt)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        {{-- بدون بیلد Vite صفحه ۵۰۰ ندهد؛ استایل از CDN تا بعد از npm ci && npm run build --}}
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: { sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    },
                },
            };
        </script>
    @endif
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
</head>
<body class="font-sans antialiased text-slate-100 min-h-screen selection:bg-indigo-500/30">
    {{ $slot }}
    @stack('scripts')
</body>
</html>

@php
    // هرگز @vite اینجا نباشد — روی بعضی سرورها http:// تولید می‌کند و روی https مرورگر CSS را بلوک می‌کند (mixed content).
    $fromManifest = \App\Support\ServiceShareViteManifest::entryUrls();
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-vpnmarket-share-assets="{{ $fromManifest ? 'manifest' : 'cdn' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>دریافت اشتراک — {{ \App\Services\ServiceShareService::publicDisplayTypingPath() }}</title>
    @if ($fromManifest)
        <!-- vpnmarket-share-assets: manifest {{ $fromManifest['css'] }} -->
        <link rel="preload" href="{{ $fromManifest['css'] }}" as="style">
        <link rel="stylesheet" href="{{ $fromManifest['css'] }}">
    @else
        <!-- vpnmarket-share-assets: cdn-fallback -->
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
    <style>
        html, body {
            font-family: 'Vazirmatn', Tahoma, 'Segoe UI', system-ui, sans-serif !important;
        }
    </style>
</head>
<body class="font-sans antialiased text-slate-100 min-h-screen selection:bg-indigo-500/30">
    {{ $slot }}
    @if ($fromManifest)
        <script type="module" src="{{ $fromManifest['js'] }}"></script>
    @endif
    @stack('scripts')
</body>
</html>

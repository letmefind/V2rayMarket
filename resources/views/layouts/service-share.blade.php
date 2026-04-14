<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>دریافت اشتراک — {{ \App\Services\ServiceShareService::publicDisplayTypingPath() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
</head>
<body class="font-sans antialiased text-slate-100 min-h-screen selection:bg-indigo-500/30">
    {{ $slot }}
    @stack('scripts')
</body>
</html>

<?php

namespace App\Support;

/**
 * لود CSS/JS صفحهٔ دریافت کانفیگ بدون وابستگی به @vite — همان چیزی که روی سرور معمولی
 * با nginx روی public/ اتفاق می‌افتد: لینک مستقیم به /build/assets/...
 *
 * در Blade با url() ترکیب می‌شود تا آدرس مطلق با APP_URL (https) ساخته شود.
 */
final class ServiceShareViteManifest
{
    private const CSS_ENTRY = 'resources/css/app.css';

    private const JS_ENTRY = 'resources/js/app.js';

    /** @return array{css: string, js: string}|null */
    public static function entryUrls(): ?array
    {
        $manifest = self::readManifest();
        if ($manifest === null) {
            return null;
        }
        $css = $manifest[self::CSS_ENTRY]['file'] ?? null;
        $js = $manifest[self::JS_ENTRY]['file'] ?? null;
        if (! is_string($css) || ! is_string($js) || $css === '' || $js === '') {
            return null;
        }

        $paths = [
            'css' => '/build/'.$css,
            'js' => '/build/'.$js,
        ];
        foreach ($paths as $rel) {
            $abs = public_path(ltrim($rel, '/'));
            if (! is_file($abs)) {
                return null;
            }
        }

        return $paths;
    }

    /** @return array<string, mixed>|null */
    private static function readManifest(): ?array
    {
        foreach ([
            public_path('build/manifest.json'),
            public_path('build/.vite/manifest.json'),
        ] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $json = file_get_contents($path);
            if ($json === false) {
                continue;
            }
            $data = json_decode($json, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}

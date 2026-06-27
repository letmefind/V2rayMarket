<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TelegramHttpClient
{
    public static function apiBase(): string
    {
        $base = config('services.telegram.api_base', 'https://api.telegram.org');

        return rtrim(is_string($base) && $base !== '' ? $base : 'https://api.telegram.org', '/');
    }

    /**
     * @return array{ok: bool, file_path: ?string, error: ?string}
     */
    public static function getFilePath(string $botToken, string $fileId, int $timeoutSeconds = 20, int $retries = 2): array
    {
        $url = self::apiBase()."/bot{$botToken}/getFile";

        try {
            $response = Http::connectTimeout(10)
                ->timeout($timeoutSeconds)
                ->retry($retries, 1500, throw: false)
                ->get($url, ['file_id' => $fileId]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'file_path' => null,
                    'error' => 'getFile HTTP '.$response->status().': '.Str::limit($response->body(), 200),
                ];
            }

            $path = $response->json('result.file_path');
            if (! is_string($path) || $path === '') {
                return ['ok' => false, 'file_path' => null, 'error' => 'getFile: file_path missing'];
            }

            return ['ok' => true, 'file_path' => $path, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('TelegramHttpClient getFile: '.$e->getMessage(), ['file_id' => $fileId]);

            return ['ok' => false, 'file_path' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, body: ?string, error: ?string}
     */
    public static function downloadFile(string $botToken, string $filePath, int $timeoutSeconds = 45, int $retries = 2): array
    {
        $url = self::apiBase()."/file/bot{$botToken}/{$filePath}";

        try {
            $response = Http::connectTimeout(10)
                ->timeout($timeoutSeconds)
                ->retry($retries, 2000, throw: false)
                ->get($url);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'body' => null,
                    'error' => 'download HTTP '.$response->status(),
                ];
            }

            return ['ok' => true, 'body' => $response->body(), 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('TelegramHttpClient download: '.$e->getMessage(), ['file_path' => $filePath]);

            return ['ok' => false, 'body' => null, 'error' => $e->getMessage()];
        }
    }
}

<?php

namespace App\Services;

use App\Support\TelegramHttpClient;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Objects\Update;

final class TelegramPhotoStorageService
{
    /**
     * برای رسید، بزرگ‌ترین سایز ۲K لازم نیست — سایز ~۸۰۰px برای ادمین کافی است و دانلود سریع‌تر است.
     */
    public static function pickFileIdFromUpdate(Update $update): ?string
    {
        $photos = collect($update->getMessage()?->getPhoto() ?? []);
        if ($photos->isEmpty()) {
            return null;
        }

        $preferred = $photos
            ->sortByDesc(fn ($p) => (int) ($p->getFileSize() ?? 0))
            ->first(function ($p) {
                $size = (int) ($p->getFileSize() ?? 0);
                $width = (int) ($p->getWidth() ?? 0);

                return $size > 0 && $size <= 150000 && $width >= 320;
            });

        $photo = $preferred ?? $photos->last();

        return $photo?->getFileId();
    }

    public static function saveByFileId(string $botToken, string $fileId, string $directory, int $getFileTimeout = 20, int $downloadTimeout = 45): ?string
    {
        if ($botToken === '') {
            Log::warning('TelegramPhotoStorageService: bot token empty');

            return null;
        }

        $resolved = TelegramHttpClient::getFilePath($botToken, $fileId, $getFileTimeout, 2);
        if (! $resolved['ok'] || ! $resolved['file_path']) {
            Log::error('TelegramPhotoStorageService getFile failed', [
                'file_id' => $fileId,
                'error' => $resolved['error'],
            ]);

            return null;
        }

        $downloaded = TelegramHttpClient::downloadFile($botToken, $resolved['file_path'], $downloadTimeout, 2);
        if (! $downloaded['ok'] || $downloaded['body'] === null) {
            Log::error('TelegramPhotoStorageService download failed', [
                'file_id' => $fileId,
                'file_path' => $resolved['file_path'],
                'error' => $downloaded['error'],
            ]);

            return null;
        }

        Storage::disk('public')->makeDirectory($directory);
        $extension = pathinfo($resolved['file_path'], PATHINFO_EXTENSION) ?: 'jpg';
        $fileName = $directory.'/'.Str::random(40).'.'.$extension;
        if (! Storage::disk('public')->put($fileName, $downloaded['body'])) {
            Log::error('TelegramPhotoStorageService: failed to write storage', ['path' => $fileName]);

            return null;
        }

        return $fileName;
    }

    public static function saveFromUpdate(Update $update, string $botToken, string $directory, bool $quick = false): ?string
    {
        $fileId = self::pickFileIdFromUpdate($update);
        if (! $fileId) {
            return null;
        }

        $getTimeout = $quick ? 12 : 20;
        $dlTimeout = $quick ? 25 : 45;

        return self::saveByFileId($botToken, $fileId, $directory, $getTimeout, $dlTimeout);
    }
}

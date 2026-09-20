<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where applicant documents live — government IDs, licence cards, the live
 * selfie. The counterpart of Uploads for things that must NOT be public.
 *
 * Nothing here ever returns a permanent URL. Admins get a signed link that
 * expires in TTL_MINUTES; the AI assessor reads bytes through the disk. If a
 * link leaks (screenshot, browser history) it is dead within the hour.
 */
final class Documents
{
    public const TTL_MINUTES = 15;

    public static function disk(): Filesystem
    {
        return Storage::disk(self::name());
    }

    public static function name(): string
    {
        return (string) config('filesystems.documents', 'local');
    }

    /** Store an uploaded file under $dir; returns the stored path. */
    public static function put(string $dir, \Illuminate\Http\UploadedFile $file): string
    {
        return (string) self::disk()->put($dir, $file);
    }

    /** Short-lived signed URL, or null when there is no file. */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            return self::disk()->temporaryUrl($path, now()->addMinutes(self::TTL_MINUTES));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && self::disk()->exists($path)) {
            self::disk()->delete($path);
        }
    }
}

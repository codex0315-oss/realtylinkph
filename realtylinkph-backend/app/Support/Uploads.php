<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * One place that knows where user uploads live.
 *
 * Every write, delete and URL used to hardcode the `public` (local) disk and
 * build URLs with `asset('storage/'.$path)`. That only works when the files sit
 * on the same box as the app — on an ephemeral host like Render the files are
 * wiped on each deploy, and on S3/R2 the URL is a different origin entirely.
 *
 * Switching `UPLOAD_DISK` to `s3` now moves the whole app.
 */
final class Uploads
{
    public static function disk(): Filesystem
    {
        return Storage::disk(self::name());
    }

    public static function name(): string
    {
        return (string) config('filesystems.uploads', 'public');
    }

    /** True when uploads are on the local box (so a filesystem path exists). */
    public static function isLocal(): bool
    {
        return config("filesystems.disks." . self::name() . ".driver") === 'local';
    }

    /**
     * Public URL for a stored path. Handles three cases:
     *  - already an absolute URL (e.g. a Google avatar) → returned untouched
     *  - S3/R2 → the disk's own URL
     *  - local → /storage/... on this app's domain
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return self::isLocal()
            ? rtrim((string) config('app.url'), '/') . '/storage/' . ltrim($path, '/')
            : self::disk()->url($path);
    }

    /**
     * Absolute filesystem path, for the few places that need to read bytes
     * (the AI document reader). Null on a remote disk — callers fall back to
     * reading the file's contents through the disk instead.
     */
    public static function localPath(?string $path): ?string
    {
        return $path !== null && self::isLocal() ? self::disk()->path($path) : null;
    }
}

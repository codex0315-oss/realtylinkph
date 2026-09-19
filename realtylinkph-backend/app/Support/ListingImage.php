<?php

declare(strict_types=1);

namespace App\Support;

use Intervention\Image\ImageManager;

/**
 * Resizes a listing photo into the two sizes the app actually displays.
 *
 * Phone cameras produce 3–10 MB files at 4000+ px, but the largest place a
 * listing photo is ever shown is the detail-page gallery (~1200 px wide on a
 * desktop, less on a phone) and the Browse cards are ~400 px. Serving the
 * originals meant a 15-card page could weigh 50–100 MB. The originals are
 * not kept: nothing in the app needs them, and Supabase's free storage is
 * 1 GB.
 *
 *  - main  : long edge capped at MAIN_EDGE px, JPEG q82 → typically 200–400 KB
 *  - thumb : long edge capped at THUMB_EDGE px, JPEG q75 → typically 40–80 KB
 *
 * 360° panoramas keep their original bytes as the main image — the sphere
 * viewer maps the whole equirectangular image onto a sphere and downscaling
 * it makes the tour visibly blurry — but still get a thumbnail for the strip.
 *
 * Auto-orientation is on by default in Intervention v3, so a portrait phone
 * photo comes out upright instead of relying on the EXIF flag the browser
 * may or may not honour.
 *
 * Memory: GD holds 4 bytes per pixel, so a 12 MP photo is ~48 MB decoded and
 * a 48 MP one ~190 MB. The image is therefore scaled *in place* — main first,
 * then the thumbnail from the already-small main — so only one full-size
 * buffer ever exists. Anything that still wouldn't fit under memory_limit is
 * skipped (stored as uploaded) instead of taking the request down with a
 * fatal error, which can't be caught.
 */
final class ListingImage
{
    public const MAIN_EDGE  = 1600;
    public const THUMB_EDGE = 640;

    public const MAIN_QUALITY  = 82;
    public const THUMB_QUALITY = 75;

    /** GD bytes per pixel, plus headroom for the encoder's working copy. */
    private const BYTES_PER_PIXEL = 5;

    /**
     * @return array{main: string, thumb: string, width: int, height: int}|null
     *         encoded JPEG bytes, or null when the file can't be decoded
     *         (corrupt, unsupported, or too large for memory) — the caller
     *         then stores the upload untouched, exactly as before.
     */
    public static function process(string $path, bool $is360 = false): ?array
    {
        $info = @getimagesize($path);
        if ($info === false || ! self::fitsInMemory($info[0], $info[1])) {
            return null;
        }
        [$width, $height] = $info;

        try {
            $image = ImageManager::gd()->read($path);

            // EXIF orientation may have swapped the axes on read.
            $width  = $image->width();
            $height = $image->height();

            if ($is360) {
                $main = (string) file_get_contents($path);
            } else {
                self::capLongEdge($image, self::MAIN_EDGE);
                $main = (string) $image->toJpeg(self::MAIN_QUALITY);
            }

            self::capLongEdge($image, self::THUMB_EDGE);
            $thumb = (string) $image->toJpeg(self::THUMB_QUALITY);
        } catch (\Throwable) {
            return null;
        }

        return ['main' => $main, 'thumb' => $thumb, 'width' => $width, 'height' => $height];
    }

    /** Scale in place so the longer edge is at most $edge. Never upscales. */
    private static function capLongEdge(\Intervention\Image\Interfaces\ImageInterface $image, int $edge): void
    {
        if ($image->width() >= $image->height()) {
            $image->scaleDown(width: $edge);
        } else {
            $image->scaleDown(height: $edge);
        }
    }

    private static function fitsInMemory(int $width, int $height): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true; // unlimited
        }

        $need = $width * $height * self::BYTES_PER_PIXEL + 16 * 1024 * 1024;

        return memory_get_usage() + $need < $limit;
    }

    private static function bytes(string $ini): int
    {
        $ini = trim($ini);
        if ($ini === '' || $ini === '-1') {
            return -1;
        }
        $unit  = strtolower(substr($ini, -1));
        $value = (int) $ini;

        return match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => (int) $ini,
        };
    }

    /** True when a stored file is worth re-processing (main image, not a pano). */
    public static function isOversized(int $bytes): bool
    {
        return $bytes > 600 * 1024;
    }
}

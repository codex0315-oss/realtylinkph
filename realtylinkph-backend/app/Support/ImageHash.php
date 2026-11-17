<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A picture fingerprint (difference hash, "dHash"): shrink to 9×8 grey pixels and
 * record, for each row, whether each pixel is brighter than the next. Two copies
 * of the same photo — even resized, re-compressed or slightly brightened — end up
 * a few bits apart; different photos are ~32 bits apart.
 */
final class ImageHash
{
    /** Bits that may differ for two pictures to count as the same photo. */
    public const SAME_PHOTO_BITS = 6;

    /** 16 hex characters (64 bits), or null if the image can't be read. */
    public static function fromBytes(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
        imagedestroy($src);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            $prev = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb  = imagecolorat($small, $x, $y);
                $grey = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
                if ($prev !== null) {
                    $bits .= $prev > $grey ? '1' : '0';
                }
                $prev = $grey;
            }
        }
        imagedestroy($small);

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    public static function fromFile(string $path): ?string
    {
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : self::fromBytes($bytes);
    }

    /** How many of the 64 bits differ. */
    public static function distance(string $a, string $b): int
    {
        $d = 0;
        foreach ([0, 8] as $off) {
            $d += substr_count(decbin(hexdec(substr($a, $off, 8)) ^ hexdec(substr($b, $off, 8))), '1');
        }

        return $d;
    }
}

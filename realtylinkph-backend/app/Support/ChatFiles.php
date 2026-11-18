<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MessageAttachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Photos, videos and files sent in buyer–agent chat.
 *
 * Where they live: the private documents bucket once it is set up
 * (DOCUMENT_DISK=s3-private), so links expire. Until then, the normal uploads
 * bucket under a long random name (chat/{conversation}/{40 random chars}),
 * which nobody can guess, and the link is only ever sent to the two people in
 * the conversation.
 *
 * The file type is decided from the file's content, not its name, so a
 * renamed program can't be sent as a "photo".
 */
final class ChatFiles
{
    public const MAX_FILES = 5;
    public const MAX_TOTAL_BYTES = 50 * 1024 * 1024;
    public const MAX_BYTES = [
        MessageAttachment::IMAGE => 10 * 1024 * 1024,
        MessageAttachment::VIDEO => 25 * 1024 * 1024,
        MessageAttachment::FILE  => 10 * 1024 * 1024,
    ];
    public const LINK_MINUTES = 60;

    /** Allowed content types → kind. */
    private const TYPES = [
        'image/jpeg' => MessageAttachment::IMAGE,
        'image/png'  => MessageAttachment::IMAGE,
        'image/webp' => MessageAttachment::IMAGE,
        'image/gif'  => MessageAttachment::IMAGE,
        'video/mp4'       => MessageAttachment::VIDEO,
        'video/quicktime' => MessageAttachment::VIDEO,
        'video/webm'      => MessageAttachment::VIDEO,
        'application/pdf' => MessageAttachment::FILE,
        'application/msword' => MessageAttachment::FILE,
        'application/vnd.ms-excel' => MessageAttachment::FILE,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => MessageAttachment::FILE,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => MessageAttachment::FILE,
    ];

    /** Office files that file-type sniffing sometimes reports generically (zip / OLE container). */
    private const OFFICE_EXT = ['doc', 'docx', 'xls', 'xlsx'];
    private const OFFICE_GENERIC = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'application/cdfv2', 'application/x-ole-storage', 'application/encrypted'];

    public static function disk(): Filesystem
    {
        return self::isPrivate() ? Documents::disk() : Uploads::disk();
    }

    private static function isPrivate(): bool
    {
        return Documents::name() === 's3-private';
    }

    /**
     * What kind of attachment this is, or an error message the sender can read.
     *
     * @return array{kind: string, mime: string}|string
     */
    public static function check(UploadedFile $file): array|string
    {
        if (! $file->isValid()) {
            return "“{$file->getClientOriginalName()}” didn’t upload properly. Please try again.";
        }
        $mime = strtolower((string) $file->getMimeType());   // from the content
        $ext  = strtolower($file->getClientOriginalExtension());
        $kind = self::TYPES[$mime] ?? null;
        if ($kind === null && in_array($ext, self::OFFICE_EXT, true) && in_array($mime, self::OFFICE_GENERIC, true)) {
            $kind = MessageAttachment::FILE;
            $mime = match ($ext) {
                'doc'  => 'application/msword',
                'xls'  => 'application/vnd.ms-excel',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            };
        }
        if ($kind === null) {
            return "“{$file->getClientOriginalName()}” can’t be sent. You can send photos (JPG, PNG, WEBP), videos (MP4, MOV, WEBM) and PDF, Word or Excel files.";
        }
        if ($file->getSize() > self::MAX_BYTES[$kind]) {
            $mb = self::MAX_BYTES[$kind] / 1024 / 1024;
            $what = $kind === MessageAttachment::VIDEO ? 'Videos' : ($kind === MessageAttachment::IMAGE ? 'Photos' : 'Files');

            return "“{$file->getClientOriginalName()}” is too big. {$what} can be up to {$mb} MB."
                . ($kind === MessageAttachment::VIDEO ? ' For a longer tour, share a YouTube or Google Drive link.' : '');
        }

        return ['kind' => $kind, 'mime' => $mime];
    }

    /**
     * Save one checked file; returns the attachment's columns (without message_id).
     * Photos are resized like listing photos and get a small preview.
     */
    public static function store(int $conversationId, UploadedFile $file, string $kind, string $mime): array
    {
        $dir  = "chat/{$conversationId}";
        $base = Str::random(40);
        $row  = ['kind' => $kind, 'name' => self::cleanName($file->getClientOriginalName()), 'mime' => $mime, 'size' => (int) $file->getSize(), 'thumb_path' => null, 'width' => null, 'height' => null];

        if ($kind === MessageAttachment::IMAGE && $mime !== 'image/gif' && ($img = ListingImage::process($file->getRealPath()))) {
            self::write("{$dir}/{$base}.jpg", $img['main']);
            self::write("{$dir}/{$base}-thumb.jpg", $img['thumb']);

            // Size of the resized photo, so the chat can reserve the right space.
            [$w, $h] = @getimagesizefromstring($img['main']) ?: [$img['width'], $img['height']];

            return [...$row, 'path' => "{$dir}/{$base}.jpg", 'thumb_path' => "{$dir}/{$base}-thumb.jpg", 'mime' => 'image/jpeg', 'size' => strlen($img['main']), 'width' => $w, 'height' => $h];
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'bin';
        $path = "{$dir}/{$base}.{$ext}";
        $stream = fopen($file->getRealPath(), 'rb');
        $ok = self::disk()->writeStream($path, $stream, ['ContentType' => $mime]);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (! $ok) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(503, 'Your file couldn’t be uploaded right now. Please try again in a moment.');
        }
        if ($kind === MessageAttachment::IMAGE && ($size = @getimagesize($file->getRealPath()))) {
            [$row['width'], $row['height']] = [$size[0], $size[1]];
        }

        return [...$row, 'path' => $path];
    }

    private static function write(string $path, string $bytes): void
    {
        if (! self::disk()->put($path, $bytes, ['ContentType' => 'image/jpeg'])) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(503, 'Your photo couldn’t be uploaded right now. Please try again in a moment.');
        }
    }

    /** Link for the two people in the chat: expiring when private, else the unguessable public URL. */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (self::isPrivate()) {
            try {
                return self::disk()->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES));
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        }

        return Uploads::url($path);
    }

    /** Remove an attachment's files (unsend, failed send). Never throws. */
    public static function delete(?string ...$paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths === []) {
            return;
        }
        try {
            self::disk()->delete($paths);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** "../../IMG 2024 (1).jpg" → "IMG 2024 (1).jpg": no paths, no control characters, at most 120 characters. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name) ?? '';
        $name = trim($name, " .");

        return mb_substr($name !== '' ? $name : 'file', 0, 120);
    }
}

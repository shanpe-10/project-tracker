<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private media stays behind the authenticated tracker endpoints on every host. */
class TrackerMediaStore
{
    public static function cloud(): bool
    {
        return (string) config('tracker.media_disk', '') !== '';
    }

    public static function disk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        return Storage::disk(config('tracker.media_disk'));
    }

    private static function key(string $area, string $name): string
    {
        if (! in_array($area, ['attachments', 'profile_photos', 'backups'], true)
            || ! preg_match('~^[A-Za-z0-9._/-]+$~D', $name)
            || array_intersect(explode('/', $name), ['', '.', '..'])) {
            throw new \InvalidArgumentException('Invalid media path.');
        }
        return str_replace('_', '-', $area).'/'.$name;
    }

    private static function localPath(string $area, string $name): string
    {
        self::key($area, $name);
        return rtrim((string) config('tracker.'.$area), '\\/').'/'.$name;
    }

    public static function upload(string $area, string $name, UploadedFile $file): void
    {
        if (self::cloud()) {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) throw new \RuntimeException('Unable to read the upload.');
            try {
                if (! self::disk()->put(self::key($area, $name), $stream, ['ContentType' => $file->getMimeType()])) {
                    throw new \RuntimeException('Unable to store the upload.');
                }
            } finally {
                fclose($stream);
            }
            return;
        }
        $path = self::localPath($area, $name);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException('The upload folder is unavailable.');
        }
        $file->move($directory, basename($path));
    }

    public static function exists(string $area, string $name): bool
    {
        return self::cloud() ? self::disk()->exists(self::key($area, $name)) : is_file(self::localPath($area, $name));
    }

    public static function delete(string $area, string $name): void
    {
        if (self::cloud()) {
            if (! self::disk()->delete(self::key($area, $name))) throw new \RuntimeException('Unable to remove stored media.');
        } else {
            $path = self::localPath($area, $name);
            if (is_file($path) && ! unlink($path)) throw new \RuntimeException('Unable to remove stored media.');
        }
    }

    public static function response(string $area, string $name, array $headers = [], ?string $downloadName = null, string $disposition = 'inline'): BinaryFileResponse|StreamedResponse
    {
        if (self::cloud()) return self::disk()->response(self::key($area, $name), $downloadName, $headers, $disposition);
        $response = response()->file(self::localPath($area, $name), $headers);
        if ($downloadName !== null) {
            $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
            $response->setContentDisposition($disposition, $downloadName, $fallback);
        }
        return $response;
    }
}

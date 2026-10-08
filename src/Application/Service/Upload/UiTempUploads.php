<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Upload;

use Semitexa\Core\Support\ProjectRoot;

/**
 * Where an upload waits between HUG and the form action that consumes it.
 *
 * Files are stored under a server-chosen id (`upl_` + 32 hex) with a small
 * metadata sidecar; nothing the client sent names anything on disk. Entries
 * expire (TTL) and are swept as new ones arrive. One directory on the project's
 * volume (var/tmp, ignored by git), so every worker sees every upload.
 */
final class UiTempUploads
{
    public const TTL_SECONDS = 3600;
    private const ID_PATTERN = '/\Aupl_[a-f0-9]{32}\z/';
    private const SWEEP_LIMIT = 50;

    private static ?string $directory = null;

    /** Test seam: a throwaway directory. */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory;
    }

    public static function directory(): string
    {
        return self::$directory ?? ProjectRoot::get() . '/var/tmp/ui-uploads';
    }

    /**
     * @param array{size: int, mime: string, name: string, field: string} $meta
     */
    public static function put(string $sourcePath, array $meta): string
    {
        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The upload directory cannot be created.');
        }
        self::sweep($dir);
        $id = 'upl_' . bin2hex(random_bytes(16));
        $meta['expires'] = time() + self::TTL_SECONDS;
        $metaJson = json_encode($meta, JSON_THROW_ON_ERROR);
        $metaPath = $dir . '/' . $id . '.json';
        $metaTmp = $metaPath . '.tmp';
        // The metadata lands first and whole (written aside, renamed in), the
        // file second: a sweep never reads half a sidecar, a take never finds
        // a file without one, and a failure leaves nothing the sweep cannot see.
        if (@file_put_contents($metaTmp, $metaJson) !== strlen($metaJson) || !@rename($metaTmp, $metaPath)) {
            @unlink($metaTmp);
            throw new \RuntimeException('The upload metadata could not be stored.');
        }
        $target = $dir . '/' . $id . '.bin';
        if (!@rename($sourcePath, $target)) {
            if (!@copy($sourcePath, $target)) {
                @unlink($target);
                @unlink($metaPath);
                throw new \RuntimeException('The upload could not be stored.');
            }
            @unlink($sourcePath);
        }

        return $id;
    }

    /**
     * Take a stored upload off the books — once. The second take of the same
     * id, an expired one or a made-up one gets null.
     */
    public static function take(string $id): ?UiUploadedFile
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $dir = self::directory();
        $claimed = $dir . '/' . $id . '.claimed';
        // rename() is atomic: of two concurrent takes, one wins.
        if (!@rename($dir . '/' . $id . '.bin', $claimed)) {
            return null;
        }
        $metaPath = $dir . '/' . $id . '.json';
        $meta = json_decode((string) @file_get_contents($metaPath), true);
        @unlink($metaPath);
        if (!is_array($meta) || (int) ($meta['expires'] ?? 0) < time()) {
            @unlink($claimed);
            return null;
        }

        return new UiUploadedFile($id, $claimed, (int) $meta['size'], (string) $meta['mime'], (string) $meta['name']);
    }

    /** Expired entries, claimed files nobody moved and abandoned sidecar writes, a bounded batch at a time. */
    private static function sweep(string $dir): void
    {
        $now = time();
        $seen = 0;
        foreach (glob($dir . '/upl_*.json') ?: [] as $metaPath) {
            if (++$seen > self::SWEEP_LIMIT) {
                break;
            }
            $meta = json_decode((string) @file_get_contents($metaPath), true);
            // Metadata that does not decode is only stale once its file is
            // older than the TTL; a fresh one may belong to a put in progress.
            $expired = is_array($meta)
                ? (int) ($meta['expires'] ?? 0) < $now
                : @filemtime($metaPath) < $now - self::TTL_SECONDS;
            if ($expired) {
                @unlink(substr($metaPath, 0, -5) . '.bin');
                @unlink($metaPath);
            }
        }
        foreach ([...(glob($dir . '/upl_*.claimed') ?: []), ...(glob($dir . '/upl_*.json.tmp') ?: [])] as $leftover) {
            if (@filemtime($leftover) < $now - self::TTL_SECONDS) {
                @unlink($leftover);
            }
        }
    }
}

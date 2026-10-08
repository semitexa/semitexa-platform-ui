<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Upload;

/**
 * A file a form action redeemed from its ticket. It is already off the temp
 * store's books: move it where it belongs, or it is swept with the rest.
 *
 * The type is what the bytes are (sniffed at upload), not what the browser
 * said, and the extension comes from that type — never from the client's file
 * name, which is kept for display only.
 */
final readonly class UiUploadedFile
{
    /** Extensions by sniffed type; anything else gets `.bin`. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/zip' => 'zip',
    ];

    public function __construct(
        public string $id,
        public string $path,
        public int $size,
        public string $mimeType,
        public string $clientName,
    ) {}

    public function extension(): string
    {
        return self::EXTENSIONS[$this->mimeType] ?? 'bin';
    }

    public function contents(): string
    {
        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new \RuntimeException('The uploaded file is no longer readable.');
        }

        return $contents;
    }

    /**
     * Move it into `$directory` under a server-chosen name (`$basename` or the
     * upload id, plus the sniffed extension). Returns the new path.
     */
    public function moveTo(string $directory, ?string $basename = null): string
    {
        $basename ??= $this->id;
        if (preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $basename) !== 1) {
            throw new \InvalidArgumentException('A stored file name is letters, digits, "_" and "-" only.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The target directory cannot be created.');
        }
        $target = rtrim($directory, '/') . '/' . $basename . '.' . $this->extension();
        if (!@rename($this->path, $target)) {
            if (!@copy($this->path, $target)) {
                throw new \RuntimeException('The uploaded file could not be moved.');
            }
            @unlink($this->path);
        }

        return $target;
    }
}

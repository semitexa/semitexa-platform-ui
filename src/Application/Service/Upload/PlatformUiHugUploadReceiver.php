<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Upload;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Http\UploadedFile;
use Semitexa\Ssr\Application\Service\UiEvent\HugUploadReceiverInterface;

/**
 * Enforces the policy signed into an upload context — size, then the type the
 * BYTES are (finfo; the browser's Content-Type and the file name are claims,
 * not evidence) — stores the file under a server-chosen id and answers with a
 * signed one-time ticket the form submits in place of the file.
 */
#[SatisfiesServiceContract(of: HugUploadReceiverInterface::class)]
final class PlatformUiHugUploadReceiver implements HugUploadReceiverInterface
{
    public function receive(array $claims, UploadedFile $file): array
    {
        $max = is_int($claims['mx'] ?? null) ? $claims['mx'] : UiUploadTickets::DEFAULT_MAX_BYTES;
        $types = is_array($claims['ty'] ?? null) ? array_values(array_filter($claims['ty'], 'is_string')) : [];
        $size = @filesize($file->tmpPath);
        if ($size === false || $size === 0) {
            return self::refuse('upload_empty', 'The file is empty.');
        }
        if ($size > $max) {
            return self::refuse('upload_too_large', sprintf('The file is larger than %s.', self::humanBytes($max)));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->tmpPath) ?: 'application/octet-stream';
        if (!UiUploadTickets::typeAllowed($mime, $types)) {
            return self::refuse('upload_type_refused', 'This kind of file is not accepted here.');
        }
        $name = UploadedFile::isSafeClientFilename($file->clientFilename) ? $file->clientFilename : 'upload';
        $id = UiTempUploads::put($file->tmpPath, ['size' => $size, 'mime' => $mime, 'name' => mb_substr($name, 0, 120), 'field' => (string) ($claims['fn'] ?? '')]);

        return [200, [
            'status' => 'accepted',
            'ticket' => UiUploadTickets::issue($claims, $id),
            'size'   => $size,
            'type'   => $mime,
            'name'   => mb_substr($name, 0, 120),
        ]];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function refuse(string $reason, string $message): array
    {
        return [422, ['status' => 'rejected', 'reason' => $reason, 'message' => $message]];
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : (int) ceil($bytes / 1024) . ' KB';
    }
}

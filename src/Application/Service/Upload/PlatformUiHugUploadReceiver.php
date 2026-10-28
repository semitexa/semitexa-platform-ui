<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Upload;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\RateLimit\RateLimitKey;
use Semitexa\Core\Http\UploadedFile;
use Semitexa\Core\Log\StaticLoggerBridge;
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
    // One page's signed context took any number of files, each kept an hour:
    // a script filled the disk. Per client (the signed-in user, else the
    // address); 0 turns a limit off.
    #[Config(env: 'PLATFORM_UI_UPLOADS_PER_MINUTE', default: 10)]
    protected int $perMinute = 10;

    #[Config(env: 'PLATFORM_UI_UPLOADS_PER_HOUR', default: 100)]
    protected int $perHour = 100;

    // The per-execution RateLimiter is taken from it per upload: this
    // receiver lives as long as the worker.
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function receive(array $claims, UploadedFile $file): array
    {
        $limited = $this->overLimit();
        if ($limited !== null) {
            return $limited;
        }
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

    /** @return array{0: int, 1: array<string, mixed>}|null the refusal, when this client is over a limit */
    private function overLimit(): ?array
    {
        if (!isset($this->container)) {
            return null;
        }
        try {
            $limiter = $this->container->get(RateLimiter::class);
        } catch (NotFoundExceptionInterface) {
            // No limiter bound in this container: nothing to limit with. Any
            // other container failure (one that could not build it) is a
            // defect and goes to the log below.
            return null;
        } catch (\Throwable $e) {
            // A limiter that fails to build is a defect, not "no request": the
            // upload still goes through, but say that no limit held it.
            StaticLoggerBridge::error('platform_ui', 'Upload rate limiter could not be resolved: the upload limit is not enforced', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
        $taken = [];
        foreach ([[$this->perMinute, 60], [$this->perHour, 3600]] as [$limit, $seconds]) {
            if ($limit <= 0) {
                continue;
            }
            $decision = $limiter->attempt('platform-ui-upload', $limit, $seconds, RateLimitKey::User);
            if (!$decision->allowed) {
                // The minute must not be used up by uploads the hour refused.
                foreach ($taken as $earlier) {
                    $limiter->release($earlier);
                }

                return [429, ['status' => 'rejected', 'reason' => 'upload_rate_limited', 'message' => sprintf('Too many uploads. Try again in %s.', $decision->retryAfter >= 120 ? (int) ceil($decision->retryAfter / 60) . ' minutes' : max(1, $decision->retryAfter) . ($decision->retryAfter > 1 ? ' seconds' : ' second')), 'retryAfter' => $decision->retryAfter]];
            }
            $taken[] = $decision;
        }

        return null;
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

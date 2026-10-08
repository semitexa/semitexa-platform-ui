<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Upload;

use Semitexa\Core\Environment;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * Upload contexts (what a field may send) and tickets (what it sent), both
 * signed and bound to the session and tenant that rendered the form.
 */
final class UiUploadTickets
{
    public const DEFAULT_MAX_BYTES = 1048576;

    /** Room an upload request needs beside the file: multipart framing and the signed context. */
    public const REQUEST_OVERHEAD_BYTES = 16_384;

    /**
     * The largest file any field can accept: the server's request limit
     * (SWOOLE_PACKAGE_MAX_LENGTH, Swoole's package_max_length) less the
     * overhead. A bigger request is dropped by Swoole before a handler runs —
     * the visitor would see a network error, not a size message.
     */
    public static function ceilingBytes(): int
    {
        return Environment::requestLimit() - self::REQUEST_OVERHEAD_BYTES;
    }

    /**
     * @param list<string> $types allowed MIME types; `image/*` style wildcards allowed
     */
    public static function context(string $componentName, string $instanceId, string $field, array $types, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        foreach ($types as $type) {
            if (!is_string($type) || preg_match('#\A[a-z]+/(\*|[a-z0-9.+-]+)\z#', $type) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a MIME type pattern.', is_string($type) ? $type : get_debug_type($type)));
            }
        }
        if ($types === []) {
            throw new \InvalidArgumentException('An upload field must list the types it accepts.');
        }
        $ceiling = self::ceilingBytes();
        if ($maxBytes > $ceiling) {
            // Refused rather than clamped: a field promising 10 MB that quietly
            // turns away a 2 MB file is a bug nobody finds until a visitor does.
            throw new \InvalidArgumentException(sprintf(
                'Upload field "%s" allows %d bytes, but the server accepts requests up to %d bytes for a file. Raise SWOOLE_PACKAGE_MAX_LENGTH (and restart the server) or lower maxBytes.',
                $field,
                $maxBytes,
                $ceiling,
            ));
        }

        return SignedContext::sign([
            'k'  => 'upload',
            'c'  => $componentName,
            'i'  => $instanceId,
            'fn' => $field,
            'mx' => max(1, $maxBytes),
            'ty' => array_values($types),
        ]);
    }

    /** @param array<string, mixed> $context the verified upload context */
    public static function issue(array $context, string $uploadId): string
    {
        return SignedContext::sign([
            'k'  => 'upload-ticket',
            'i'  => $context['i'] ?? '',
            'fn' => $context['fn'] ?? '',
            'u'  => $uploadId,
        ], UiTempUploads::TTL_SECONDS);
    }

    /**
     * Redeem a ticket submitted as field `$field`: once, in the session that
     * uploaded it, before it expires. Anything else is null.
     *
     * `$fieldInstanceId`, when the form signed one for the field, must be the
     * field instance the file was uploaded through. The type and size limits
     * were checked against THAT field's upload context; without this, a ticket
     * from a lenient field of another form with the same field name could be
     * redeemed by a strict one, past its limits.
     */
    public static function redeem(mixed $ticket, string $field, ?string $fieldInstanceId = null): ?UiUploadedFile
    {
        if (!is_string($ticket) || $ticket === '' || strlen($ticket) > 2048) {
            return null;
        }
        $claims = SignedContext::verify($ticket);
        if ($claims === null || ($claims['k'] ?? null) !== 'upload-ticket' || ($claims['fn'] ?? null) !== $field) {
            return null;
        }
        if ($fieldInstanceId !== null && ($claims['i'] ?? null) !== $fieldInstanceId) {
            return null;
        }

        return UiTempUploads::take((string) ($claims['u'] ?? ''));
    }

    /**
     * The file a form action's field carries — the form must have signed the
     * field, and the ticket is redeemed (once) for it.
     */
    public static function fromForm(UiFormSubmitActionContext $context, string $field): ?UiUploadedFile
    {
        foreach ($context->fields as $definition) {
            if ($definition->name === $field) {
                return self::redeem($context->values[$field] ?? null, $field, $definition->instanceId);
            }
        }

        return null;
    }

    /**
     * Types a browser runs as a document when the file is opened from this
     * origin. `image/*` means "a picture", and an SVG is a picture that carries
     * script, so a wildcard never reaches these: a field takes one only by
     * listing it exactly.
     */
    private const ACTIVE_CONTENT_TYPES = [
        'image/svg+xml', 'text/html', 'text/xml', 'text/javascript', 'text/xsl',
        'application/xhtml+xml', 'application/xml', 'application/javascript',
    ];

    /** @param list<string> $patterns */
    public static function typeAllowed(string $mime, array $patterns): bool
    {
        $active = in_array($mime, self::ACTIVE_CONTENT_TYPES, true);
        foreach ($patterns as $pattern) {
            if ($pattern === $mime || (!$active && str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Upload\UiUploadTickets;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

#[AsTwigExtension]
final class UploadTwigExtension
{
    public function registerFunctions(): void
    {
        // ui_upload_context(instanceId, field, accept, maxBytes?) — the signed
        // upload context of a `control: 'file'` field (what HUG may accept).
        // A maxBytes above ui_upload_ceiling() fails the render, by design.
        TwigExtensionRegistry::registerFunction(
            'ui_upload_context',
            static fn (string $instanceId, string $field, array $accept, ?int $maxBytes = null): string => UiUploadTickets::context('platform.field', $instanceId, $field, array_values($accept), $maxBytes ?? UiUploadTickets::DEFAULT_MAX_BYTES),
        );

        // ui_upload_ceiling() — the largest file this server can take in one
        // request (SWOOLE_PACKAGE_MAX_LENGTH less the request overhead), for a
        // field that should allow "as much as the server does".
        TwigExtensionRegistry::registerFunction('ui_upload_ceiling', static fn (): int => UiUploadTickets::ceilingBytes());
    }
}

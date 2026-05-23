<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Raised at render time when the canonical SSE transport mode cannot
 * be resolved to a safe value — typically because a page passed an
 * unknown explicit mode to `ui_page_sse_session_meta()` or the
 * deployment exported an invalid `SEMITEXA_UI_TRANSPORT_MODE` value.
 *
 * Failing fast on the render path surfaces typos as a clear Twig error
 * in dev rather than silently downgrading or silently upgrading the
 * page's SSE behaviour. Public/guest safety relies on the hard
 * default being drain; we therefore refuse to guess.
 */
final class UiTransportModeException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $invalidValue = '')
    {
        parent::__construct($message);
    }
}

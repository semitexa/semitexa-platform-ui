<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\PlatformUi\Application\Resource\Response\WorkbenchPageResponse;

/**
 * `GET /__ui/workbench` — the UI Workbench: every catalog entry, its contract
 * and its worked examples rendered by the real runtime. `entry` selects one
 * (`platform.card`); without it the page lists the catalog. `skin` previews
 * every example under another installed skin.
 *
 * Gated by WorkbenchGate (APP_ENV=dev or PLATFORM_UI_WORKBENCH=1, never
 * cross-site); answers 404 otherwise.
 */
#[AsPublicPayload(
    path: '/__ui/workbench',
    methods: ['GET'],
    responseWith: WorkbenchPageResponse::class,
)]
final class WorkbenchPayload
{
    public string $entry = '';

    /** A skin slug to preview under; empty = the page's own skin. */
    public string $skin = '';

    public function setSkin(mixed $value): void
    {
        $this->skin = is_string($value) && preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $value) === 1 ? $value : '';
    }

    /** light | dark — the colour scheme the stage renders examples in. */
    public string $mode = 'light';

    public function setMode(mixed $value): void
    {
        $this->mode = $value === 'dark' ? 'dark' : 'light';
    }

    public function setEntry(mixed $value): void
    {
        $this->entry = is_string($value) && preg_match('/\A[a-z][a-z0-9.-]{0,95}\z/', $value) === 1 ? $value : '';
    }
}

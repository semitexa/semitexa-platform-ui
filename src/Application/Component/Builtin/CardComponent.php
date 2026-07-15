<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

/**
 * platform.card — a presentational surface that groups related content.
 *
 * Pure composition: no parts, no event handlers, no live behaviour. The
 * template is layout only — it places whatever the caller passes into the
 * named slots (media, header, body, footer) inside a token-styled surface.
 *
 * Slots:
 *   - media  — full-bleed leading content (image/video), rendered first.
 *   - header — title area; falls back to the `title`/`subtitle` props when
 *              the slot is empty.
 *   - body   — the main content region.
 *   - footer — trailing actions / meta.
 *
 * Props:
 *   - variant  — elevated (default) | outlined | plain.
 *   - title    — convenience header title when no `header` slot is given.
 *   - subtitle — convenience header subtitle (only with `title`).
 *
 * Styling lives in css/components.css under @layer platform-ui.primitives,
 * keyed off `[ui-component="card"]` — token-first, skin-neutral.
 */
#[AsComponent(
    name: 'platform.card',
    template: '@platform-ui/components/runtime/card.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'media', description: 'Full-bleed leading media (image/video), rendered above the header.')]
#[UiSlot(name: 'header', description: 'Header content; falls back to the title/subtitle props when empty.')]
#[UiSlot(name: 'body', description: 'Main content region of the card.')]
#[UiSlot(name: 'footer', description: 'Trailing actions or metadata, rendered below the body.')]
final class CardComponent
{
}

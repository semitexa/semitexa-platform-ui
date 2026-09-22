<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;

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
#[AsUiContract(
    summary: 'Group related content in a themed surface with optional media and actions.',
    props: [
        new UiProp('variant', default: 'elevated', values: ['elevated', 'outlined', 'plain']),
        new UiProp('title', default: '', description: 'Heading used when the header slot is empty.'),
        new UiProp('subtitle', default: ''),
    ],
    examples: [
        new UiExample('default', 'Project overview', ['title' => 'Your workspace', 'subtitle' => 'A place for the next idea'], ['body' => 'Build something useful.', 'footer' => 'Updated just now']),
        new UiExample('outlined', 'Outlined', ['variant' => 'outlined', 'title' => 'A quieter surface'], ['body' => 'The same content, another token-driven treatment.']),
        new UiExample('empty', 'Empty', ['title' => 'Nothing here yet'], ['body' => 'Create your first item to get started.']),
    ],
    previewSafe: true,
)]
final class CardComponent
{
}

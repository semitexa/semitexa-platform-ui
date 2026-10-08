<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

/**
 * platform.empty-state — a centered placeholder for no-data situations.
 *
 * Renders an optional icon (by name, via the icon() helper), a title, an
 * optional description, and an `actions` slot for recovery controls. The
 * `media` slot overrides the icon with custom illustration markup.
 *
 * Props:
 *   - icon        — optional icon name (rendered when no `media` slot).
 *   - title       — the headline (e.g. "No results").
 *   - description — optional supporting text.
 * Slots:
 *   - media   — custom illustration; overrides the `icon` prop.
 *   - actions — recovery controls (buttons/links) below the text.
 *
 * Styling: css/components.css, `[ui-component="empty-state"]`.
 */
#[AsComponent(
    name: 'platform.empty-state',
    template: '@platform-ui/components/runtime/empty-state.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'media', description: 'Custom illustration markup; overrides the icon prop when present.')]
#[UiSlot(name: 'actions', description: 'Recovery controls (buttons/links) rendered below the text.')]
#[AsUiContract(
    summary: 'A centred placeholder for a place with nothing to show yet.',
    props: [
        new UiProp('title', required: true),
        new UiProp('description', nullable: true),
        new UiProp('icon', nullable: true, description: 'A Lucide icon name ("inbox").'),
    ],
    examples: [
        new UiExample('default', 'No orders', ['title' => 'No orders yet', 'description' => 'Orders appear here as they arrive.', 'icon' => 'inbox']),
        new UiExample('first-run', 'First run', ['icon' => 'inbox', 'title' => 'No customers yet', 'description' => 'Create your first customer to start sending invoices.'], ['actions' => 'Use the New customer button above.']),
        new UiExample('search', 'No results', ['icon' => 'search', 'title' => 'No matches', 'description' => 'Try a shorter search or clear the filters.']),
    ],
    previewSafe: true,
)]
final class EmptyStateComponent
{
}

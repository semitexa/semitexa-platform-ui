<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

/**
 * platform.navbar — a top navigation bar.
 *
 * Hybrid: the primary links are data-driven (`items` prop) while the brand
 * and right-side actions are caller slots. Renders a semantic `<nav>` with a
 * brand block, an inline `<ul>` of links, and a trailing actions block.
 *
 * Props:
 *   - items     — list of { label, href?, current?: bool }, in order.
 *   - ariaLabel — accessible name for the <nav> (default "Main").
 * Slots:
 *   - brand   — logo / product name, rendered first.
 *   - actions — trailing controls (buttons, avatar, …), rendered last.
 *
 * Styling: css/components.css, `[ui-component="navbar"]`.
 */
#[AsComponent(
    name: 'platform.navbar',
    template: '@platform-ui/components/runtime/navbar.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'brand', description: 'Logo or product name, rendered at the start of the bar.')]
#[UiSlot(name: 'actions', description: 'Trailing controls (buttons, avatar, menu), rendered at the end of the bar.')]
final class NavbarComponent
{
}

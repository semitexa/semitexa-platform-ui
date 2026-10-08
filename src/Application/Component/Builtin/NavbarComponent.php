<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

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
#[AsUiContract(
    summary: 'Top-level navigation bar with brand, links and actions.',
    props: [
        new UiProp('items', UiPropType::Array, default: [], items: new UiProp('item', UiPropType::Object, properties: [new UiProp('label', required: true), new UiProp('href', nullable: true), new UiProp('current', UiPropType::Boolean, default: false)])),
        new UiProp('ariaLabel', default: 'Main'),
    ],
    examples: [
        new UiExample('app', 'Application bar', ['items' => [['label' => 'Dashboard', 'href' => '/', 'current' => true], ['label' => 'Customers', 'href' => '/customers'], ['label' => 'Orders', 'href' => '/orders'], ['label' => 'Reports', 'href' => '/reports']]], ['brand' => 'Acme', 'actions' => 'Jane Doe']),
    ],
    previewSafe: true,
    // A Workbench preview, not yet a block an AI-composed screen may use.
    agent: false,
)]
final class NavbarComponent
{
}

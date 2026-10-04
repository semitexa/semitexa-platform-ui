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
 * platform.list — a data-driven vertical list of items.
 *
 * Each item renders as a row with a title, optional description, optional
 * trailing `meta` text, and an optional `href` (the whole title becomes a
 * link). Semantic `<ul>`/`<li>`; when `items` is empty the `empty` slot is
 * shown instead.
 *
 * Props:
 *   - items — list of { title, description?, meta?, href? }, in order.
 * Slots:
 *   - header — optional heading rendered above the list.
 *   - empty  — shown when there are no items.
 *
 * Styling: css/components.css, `[ui-component="list"]`.
 */
#[AsComponent(
    name: 'platform.list',
    template: '@platform-ui/components/runtime/list.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'header', description: 'Optional heading rendered above the list.')]
#[UiSlot(name: 'empty', description: 'Empty-state content shown when there are no items.')]
#[AsUiContract(
    summary: 'A vertical list of linked items with secondary text.',
    props: [
        new UiProp('items', UiPropType::Array, default: [], items: new UiProp('item', UiPropType::Object, properties: [new UiProp('title', required: true), new UiProp('description', nullable: true), new UiProp('meta', nullable: true), new UiProp('href', nullable: true)])),
    ],
    examples: [
        new UiExample('invoices', 'Invoices', ['items' => [['title' => 'Invoice #1042', 'description' => 'Paid by Jane Doe', 'meta' => '2h ago', 'href' => '/invoices/1042'], ['title' => 'Invoice #1041', 'description' => 'Overdue by 3 days', 'meta' => '1d ago', 'href' => '/invoices/1041'], ['title' => 'Invoice #1040', 'description' => 'Draft', 'meta' => '3d ago']]], ['header' => 'Recent invoices']),
        new UiExample('empty', 'Empty', ['items' => []], ['empty' => 'Nothing to show yet.']),
    ],
    previewSafe: true,
)]
final class ListComponent
{
}

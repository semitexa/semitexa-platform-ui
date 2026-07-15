<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

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
final class ListComponent
{
}

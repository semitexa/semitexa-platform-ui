<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

/**
 * platform.table — a data-driven table.
 *
 * The caller declares `columns` and `rows`; the template renders a semantic
 * `<table>` with a `<thead>` from the columns and a `<tbody>` from the rows
 * (each cell read by the column's `key`). Column `align` maps to a
 * `ui-align` attribute for text alignment.
 *
 * Props:
 *   - columns — list of { key: string, label?: string, align?: 'start'|'center'|'end' }.
 *   - rows    — list of associative arrays keyed by column key.
 *   - caption — optional <caption> text.
 * Slots:
 *   - toolbar — optional controls rendered above the table (search, filters).
 *   - empty   — shown in place of rows when `rows` is empty.
 *
 * Styling: css/components.css, `[ui-component="table"]`.
 */
#[AsComponent(
    name: 'platform.table',
    template: '@platform-ui/components/runtime/table.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'toolbar', description: 'Optional controls rendered above the table (search, filters, actions).')]
#[UiSlot(name: 'empty', description: 'Empty-state content shown when there are no rows.')]
final class TableComponent
{
}

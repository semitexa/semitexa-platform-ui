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
#[AsUiContract(
    summary: 'Display rows of structured data with aligned columns.',
    props: [
        new UiProp('columns', UiPropType::Array, default: [], items: new UiProp('column', UiPropType::Object, properties: [new UiProp('key', required: true), new UiProp('label', nullable: true), new UiProp('align', nullable: true, values: ['start', 'center', 'end'])])),
        new UiProp('rows', UiPropType::Array, default: [], items: new UiProp('row', UiPropType::Object), description: 'Each row is keyed by column key; values render as escaped text.'),
        new UiProp('caption', nullable: true, description: 'Also names the scroll region for assistive tech.'),
        new UiProp('density', nullable: true, values: ['compact', 'comfortable']),
        new UiProp('striped', UiPropType::Boolean, default: false),
        new UiProp('hover', UiPropType::Boolean, default: true),
        new UiProp('variant', nullable: true, values: ['plain'], description: 'plain drops the frame.'),
    ],
    examples: [
        new UiExample('customers', 'Customers', ['caption' => 'Customers', 'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'status', 'label' => 'Status'], ['key' => 'total', 'label' => 'Total', 'align' => 'end']], 'rows' => [['name' => 'Jane Doe', 'email' => 'jane@acme.io', 'status' => 'Active', 'total' => '$1,200.00'], ['name' => 'John Roe', 'email' => 'john@acme.io', 'status' => 'Invited', 'total' => '$0.00'], ['name' => 'Ann Lee', 'email' => 'ann@acme.io', 'status' => 'Suspended', 'total' => '$310.50']]]),
        new UiExample('compact-striped', 'Compact, striped', ['caption' => 'Customers', 'density' => 'compact', 'striped' => true, 'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'status', 'label' => 'Status'], ['key' => 'total', 'label' => 'Total', 'align' => 'end']], 'rows' => [['name' => 'Jane Doe', 'email' => 'jane@acme.io', 'status' => 'Active', 'total' => '$1,200.00'], ['name' => 'John Roe', 'email' => 'john@acme.io', 'status' => 'Invited', 'total' => '$0.00'], ['name' => 'Ann Lee', 'email' => 'ann@acme.io', 'status' => 'Suspended', 'total' => '$310.50']]]),
        new UiExample('empty', 'Empty', ['caption' => 'Invoices', 'columns' => [['key' => 'number', 'label' => 'Number'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'end']], 'rows' => []], ['empty' => 'No invoices yet.']),
    ],
    previewSafe: true,
    // A Workbench preview, not yet a block an AI-composed screen may use.
    agent: false,
)]
final class TableComponent
{
}

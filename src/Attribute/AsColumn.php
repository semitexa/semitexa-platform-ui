<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares one column on a grid component.
 *
 * Placed on a public property of a `#[AsComponent]` class; the property
 * name becomes the column key and `$type` drives rendering inside the
 * `platform.grid` template.
 *
 *   - $label    — header text shown above the column.
 *   - $sortable — whether the column header renders as a sort toggle.
 *   - $type     — one of `text`, `date`, `datetime`, `number`, `mono`, `badge`,
 *                 `link`. `text`/`date`/`datetime`/`number`/`mono` differ only in
 *                 cell CSS and render as plain `textContent`. `badge` and `link`
 *                 are the two RICH cell types: `badge` renders a styled `<span>`
 *                 chosen by the `$badge` value→variant map; `link` renders an
 *                 `<a>` whose href comes from the `$href` template. Both are
 *                 rendered byte-equivalently by the server `grid.html.twig` `<td>`
 *                 loop and the client `grid-runtime.js` `buildRow()`.
 *   - $badge    — REQUIRED for (and only valid on) a `type: 'badge'` column: a
 *                 value→variant map (`['draft' => 'mute', 'published' => 'ok',
 *                 'archived' => 'warn']`). Variants are the fixed token set
 *                 `ok` / `warn` / `mute`; an unmapped cell value falls back to
 *                 `mute`. The map is SERVER-OWNED — the row value only ever sets
 *                 the badge text and selects a variant key, never markup.
 *   - $href     — REQUIRED for (and only valid on) a `type: 'link'` column: a
 *                 SITE-RELATIVE href template with `{field}` placeholders
 *                 interpolated from the row (e.g. `/playground/orm/articles/{id}`).
 *                 Each interpolated value is URL-encoded by both renderers; the
 *                 field names come from this trusted template, not the row. Must
 *                 start with a single `/` (no scheme → bans `javascript:`/`data:`;
 *                 no protocol-relative `//` → bans external origins). The href may
 *                 reference fields that are not declared columns (e.g.
 *                 `?category={categoryId}`).
 *   - $defaultSort — when set (`'asc'` / `'desc'`), this column is the grid's
 *                    DEFAULT sort and the value is its initial direction. The
 *                    resolved token (`${propertyName}_${defaultSort}`, e.g.
 *                    `submittedAt_desc`) is emitted by
 *                    GridComponentMetadataProvider as the `defaultSort` prop and
 *                    seeds the shell's initial `sort` (the same
 *                    declaration→metadata→bundle→runtime thread the declared
 *                    page-size travels). At most one column per grid may set it,
 *                    and it requires `sortable: true`.
 *
 * Read at boot by GridComponentMetadataProvider.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AsColumn
{
    /**
     * @param array<string, string>|null $badge value→variant map for a
     *        `type: 'badge'` column; null for every other type.
     */
    public function __construct(
        public readonly string $label,
        public readonly bool $sortable = false,
        public readonly string $type = 'text',
        public readonly ?string $defaultSort = null,
        public readonly ?array $badge = null,
        public readonly ?string $href = null,
    ) {
        if ($defaultSort !== null) {
            if (!in_array($defaultSort, ['asc', 'desc'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'AsColumn("%s"): defaultSort must be "asc" or "desc", got "%s".',
                    $label,
                    $defaultSort,
                ));
            }
            if (!$sortable) {
                throw new \InvalidArgumentException(sprintf(
                    'AsColumn("%s"): defaultSort requires sortable: true.',
                    $label,
                ));
            }
        }

        // Badge config — only meaningful on a `type: 'badge'` column, and
        // required there (an empty map would render every value as `mute`,
        // which is almost certainly a declaration mistake). Boot-fail loudly,
        // naming the column, exactly like the defaultSort guards above.
        if ($badge !== null && $type !== 'badge') {
            throw new \InvalidArgumentException(sprintf(
                'AsColumn("%s"): badge map is only valid on a type: "badge" column (got type "%s").',
                $label,
                $type,
            ));
        }
        if ($type === 'badge' && ($badge === null || $badge === [])) {
            throw new \InvalidArgumentException(sprintf(
                'AsColumn("%s"): type "badge" requires a non-empty value=>variant map.',
                $label,
            ));
        }

        // Link config — only meaningful on a `type: 'link'` column, and required
        // there. The href MUST be a site-relative path: it starts with a single
        // `/` and is NOT protocol-relative (`//host`). This structurally bans
        // `javascript:` / `data:` schemes (they cannot start with `/`) and
        // external origins — the defence-in-depth complement to the per-field
        // URL-encoding both renderers apply at interpolation time.
        if ($href !== null && $type !== 'link') {
            throw new \InvalidArgumentException(sprintf(
                'AsColumn("%s"): href is only valid on a type: "link" column (got type "%s").',
                $label,
                $type,
            ));
        }
        if ($type === 'link') {
            if ($href === null || $href === '') {
                throw new \InvalidArgumentException(sprintf(
                    'AsColumn("%s"): type "link" requires an href template (e.g. "/path/{id}").',
                    $label,
                ));
            }
            if ($href[0] !== '/' || ($href[1] ?? '') === '/') {
                throw new \InvalidArgumentException(sprintf(
                    'AsColumn("%s"): href must be a site-relative path starting with a single "/" '
                    . '(no scheme, no protocol-relative "//"); got "%s".',
                    $label,
                    $href,
                ));
            }
        }
    }
}

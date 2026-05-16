# platform-ui grammar reference (v1)

Attribute-based CSS grammar for LLM-friendly UI composition.

## Two attribute namespaces

- `sx-*` — layout and composition (applicable to any element)
- `ui-*` — primitive identity and modifiers (used with primitives)

## Grammar domains (v1 — 9 attributes, 43 slices)

### Layout — `sx-layout`

| Value | Effect |
|---|---|
| `stack` | `flex column` |
| `cluster` | `flex row wrap, align-items: center` |
| `grid` | `grid with auto-fit, minmax(0, 1fr)` |
| `frame` | `block` — simple container |

### Spacing scale — `sx-gap`, `sx-padding`

Same scale for both. Rem-based, non-linear:

| Token | rem |
|---|---|
| `0` | 0 |
| `1` | 0.25 |
| `2` | 0.5 |
| `3` | 0.75 |
| `4` | 1 |
| `6` | 1.5 |
| `8` | 2 |

### Radius — `sx-radius`

`none` · `sm` (0.25rem) · `md` (0.5rem) · `lg` (0.75rem) · `pill` (9999px)

### Surface — `sx-surface`

| Value | Background | Border |
|---|---|---|
| `flat` | `--ui-surface-page` | — |
| `panel` | `--ui-surface-panel` | `--ui-border-subtle` |
| `raised` | `--ui-surface-raised` | `--ui-border-subtle` + shadow |

### Tone — `sx-tone`

Semantic color of text content: `neutral` · `brand` · `success` · `warning` · `danger`.

### Text role — `ui-text`

| Value | Use |
|---|---|
| `body` | normal reading weight |
| `muted` | secondary copy |
| `title` | section heading |
| `label` | form label, small strong |

### Alignment — `sx-align`, `sx-justify`

- `sx-align`: `start` · `center` · `end` · `stretch`
- `sx-justify`: `start` · `center` · `end` · `between`

## Composition examples

```twig
<section sx-layout="stack" sx-gap="4" sx-surface="panel" sx-padding="4" sx-radius="lg">
    <header sx-layout="cluster" sx-justify="between" sx-align="center">
        <h2 ui-text="title">Revenue</h2>
        <button ui="button" ui-tone="brand">Export</button>
    </header>
</section>
```

## Introspection

- `bin/semitexa platform-ui:css:explain sx-gap:4` — emitted CSS, referenced tokens, sibling values
- `bin/semitexa platform-ui:css:inspect <template>` — full usage scan
- `Semitexa\PlatformUi\Support\ValueValidator::assert($attr, $value)` — runtime check

## Deferred to v1.1+

`sx-shadow`, `sx-density`, `sx-border`, `sx-row`/`sx-column`, responsive switches, `grid` column counts.

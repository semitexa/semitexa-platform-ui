# Skin generation (v2)

Skins are generated from one seed color via deterministic OKLCH math. The same seed + algorithm + knobs + mode always produces the same token palette, independent of time or LLM state.

## Three algorithms

Each algorithm has a fixed character (colors, shadows, radii, motion) and a handful of **knobs** that fine-tune it without leaving its territory.

| Algorithm | Character | Knobs |
|---|---|---|
| `balanced` | Corporate-readable. Soft drop shadows, conservative radii, smooth transitions. | `radius_scale`, `shadow_intensity`, `motion_speed` |
| `glass` | Translucent frosted panels, larger radii, diffuse shadows, emphasized motion. | `blur_amount`, `surface_transparency`, `shadow_softness` |
| `brutalist` | Bold & structural. Zero radius, hard offset shadows, instant motion, high-contrast colors. | `shadow_offset`, `contrast_boost`, `shadow_color_mode` |

Full knob enums + defaults: run `bin/semitexa skins:generate --describe`, or see [skin-algorithms.md](skin-algorithms.md).

## Seed mode — deterministic

```bash
bin/semitexa skins:generate balanced "#2f6fed" --name=enterprise --write
bin/semitexa skins:generate glass "#6b7bff" --name=frosted --knob=blur_amount:heavy --write
bin/semitexa skins:generate brutalist "#d93025" --name=manifesto --knob=shadow_color_mode:brand --mode=dark --write
```

Omit `--write` for a dry-run CSS preview to stdout.

Produces under the project's `src/skins/<name>/` directory:
- `tokens.css` — `:root { --ui-*: <value>; }`
- `skin.json` — v2 manifest (algorithm, seed, mode, resolved knobs, history, tokens)

`SkinDiscovery` in `semitexa/theme` scans two sources:
1. `vendor/semitexa/skins-base/src/Application/Static/skins/` — framework default (ships only the single `default` reference skin)
2. `src/skins/` — project-local (project slugs override same-named framework slugs)

Both served under the unified URL prefix `/assets/skins/<slug>/tokens.css` (registered at worker boot by `Semitexa\Theme\Runtime\BootProjectSkinsAssetAliasListener`). Theme authors reference a skin by slug — they don't care where it physically lives.

## Prompt mode — LLM-assisted

Requires `semitexa/llm` with a reachable Ollama provider.

```bash
bin/semitexa skins:generate --prompt="punk zine manifesto, anti-corporate" --name=zine --write
```

The LLM skill (`platform-ui.skin.resolve-prompt`) picks **algorithm + seed + knobs + mood** from prose, runs through the output validator, then hands off to the same deterministic OKLCH pipeline as seed mode.

Override the LLM's algorithm choice by passing `--algorithm=<id>` explicitly; override its knob suggestions via `--knob=name:value` (repeatable). CLI always wins over LLM.

See [llm-prompt.md](llm-prompt.md) for the system prompt and output contract.

## Light & dark mode

Every algorithm emits both. Default is light; pass `--mode=dark` for a dark palette built from the same brand hue.

```bash
bin/semitexa skins:generate balanced "#3c7fbf" --name=ocean-dark --mode=dark --write
```

Dark mode inverts surface/text lightness in OKLCH, shifts state colors brighter for legibility, triples shadow alpha (so drop shadows remain perceptible against near-black surfaces), and flips the brutalist neutral shadow from matte-black to near-white. `skin:refine` preserves the mode of the source skin.

## Token contract (41 tokens)

### Color (24) — unchanged since v1

| Role | Token |
|---|---|
| Text | `--ui-text-primary`, `--ui-text-muted`, `--ui-text-on-accent` |
| Surface | `--ui-surface-page`, `--ui-surface-panel`, `--ui-surface-raised`, `--ui-surface-sunken` |
| Border | `--ui-border-subtle`, `--ui-border-strong` |
| Accent | `--ui-accent-brand`, `--ui-accent-brand-contrast` |
| State | `--ui-state-success`, `--ui-state-warning`, `--ui-state-danger`, `--ui-state-info` |
| Interactive | `--ui-focus-ring` |
| Chart | `--ui-chart-1` … `--ui-chart-8` |

### Non-color (17) — new in v2

| Role | Tokens |
|---|---|
| Radius | `--ui-radius-none`, `-sm`, `-md`, `-lg`, `-pill` |
| Shadow | `--ui-shadow-xs`, `-sm`, `-md`, `-lg`, `--ui-shadow-color` |
| Motion duration | `--ui-motion-duration-fast`, `-normal`, `-slow` |
| Motion easing | `--ui-motion-easing-standard`, `-emphasized` |
| Glass effects | `--ui-surface-blur`, `--ui-surface-saturation` |

Primitives and grammar slices consume these via `var(--ui-X, <fallback>)`. Old 24-token skins keep rendering against fallbacks.

Strict definition: `src/Skin/TokenContract.php` (PHP enum).

## Manifest v2 (`skin.json`)

```json
{
  "name": "enterprise",
  "schema_version": "2.0",
  "source": "seed",
  "algorithm": "balanced",
  "mode": "light",
  "seed": "#2f6fed",
  "knobs": { "radius_scale": "default", "shadow_intensity": "default", "motion_speed": "default" },
  "generated_at": "2026-04-24T09:00:00+00:00",
  "updated_at": "2026-04-24T09:00:00+00:00",
  "history": [
    { "at": "…", "kind": "generate", "algorithm": "balanced", "mode": "light", "seed": "#2f6fed", "knobs": {…}, "source": "seed" }
  ],
  "tokens": { "--ui-surface-page": "#fcfcfd", … }
}
```

Manifest is the source of truth; `tokens.css` is derived. Any regeneration (seed, prompt, or refine) appends to `history[]` so the iteration chain is auditable. Refine with LLM also records the prompt, model, attempts, latency, and rationale on each entry.

v1 skins (no `knobs`/`history`/`mode` fields, `schema_version` absent or `"1.0"`) are auto-migrated on first `skin:refine` — the missing fields get defaults and a synthetic `"kind": "migrated"` entry is prepended to `history[]`.

## OKLCH math (balanced — reference algorithm)

1. Seed → OKLCH. Clamp to envelope: L ∈ [0.35, 0.70], C ≤ 0.25.
2. Surface ramp: tints of seed hue. Light-mode L 0.94–1.00, dark-mode L 0.10–0.22.
3. Border ramp: visible against surface. Light L 0.78–0.90, dark L 0.28–0.42.
4. Text: dark seed hue for primary (L 0.22 light / 0.95 dark), mid for muted (L 0.50 / 0.65).
5. Accent: the seed itself, walked in L until WCAG-AA contrast met against `SurfacePage`. Walk direction depends on mode — dark backgrounds need brighter accents, light need darker.
6. States: canonical hues (success 145°, warning 70°, danger 25°, info 240°) with matched chroma; dark mode shifts lightness up ~0.08 so they remain legible on dark surfaces.
7. Charts: 8 equally-spaced hues (45° apart) starting from seed hue.

Glass + brutalist layer their character on top of the same frame — larger radii + blur for glass; zero radius + hard offset shadows + saturated accents for brutalist.

## Extension point — adding an algorithm

Implement `Semitexa\PlatformUi\Contract\SkinAlgorithm` (four methods: `id()`, `description()`, `knobSchema()`, `generate()`) and register it in `SkinAlgorithmRegistry`. The LLM prompt block is built from the registry at runtime, so the new algorithm appears in `skin:generate --prompt "…"` without any prompt-file edit.

Recommended: add 1–2 few-shot examples to `resources/llm/skin-resolve-fewshot.json` — concrete examples still meaningfully shape LLM performance even though the prompt itself is dynamic.

See [skin-algorithms.md](skin-algorithms.md) for the knob-schema convention and walk-through.

## Introspection

- `bin/semitexa skins:generate --describe` — all algorithms with knob enums + defaults
- `bin/semitexa skins:generate <algo> "#hex"` (no `--write`) — dry-run CSS preview
- `bin/semitexa skins:explain-prompt "<text>"` — show LLM resolution, no CSS emission
- `Semitexa\PlatformUi\Skin\Oklch\ContrastScore::contrast($hex1, $hex2)` — WCAG relative-luminance ratio
- See [skin-refinement.md](skin-refinement.md) for iterating on an existing skin

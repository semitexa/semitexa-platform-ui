# Skin algorithms & knob reference

Each algorithm is a `Semitexa\PlatformUi\Contract\SkinAlgorithm` implementation. The registry at `Semitexa\PlatformUi\Skin\SkinAlgorithmRegistry` lists all three shipped algorithms; the generate command, the LLM prompt, and the output validator all read from it.

Inspect live:

```bash
bin/semitexa skins:generate --describe
```

## `balanced`

Corporate-readable. Soft drop shadows, conservative radii, smooth transitions. WCAG-AA contrast on brand. The default when prose is ambiguous.

| Knob | Enum | Default | Effect |
|---|---|---|---|
| `radius_scale` | `compact` · `default` · `rounded` | `default` | How round corners feel — compact=tight, rounded=softer. |
| `shadow_intensity` | `minimal` · `default` · `pronounced` | `default` | Drop-shadow strength on raised surfaces. |
| `motion_speed` | `fast` · `default` · `slow` | `default` | Transition-duration character. |

## `glass`

Translucent frosted panels, larger radii, diffuse shadows, emphasized motion. Modern SaaS / mac-style.

| Knob | Enum | Default | Effect |
|---|---|---|---|
| `blur_amount` | `subtle` · `medium` · `heavy` | `medium` | Backdrop-blur radius on panels (6px / 12px / 20px). |
| `surface_transparency` | `light` · `medium` · `heavy` | `medium` | How much the backdrop shows through panels. |
| `shadow_softness` | `tight` · `standard` · `wide` | `standard` | Spread of diffuse drop shadows. |

## `brutalist`

Bold & structural. Zero radius (except pill), hard offset shadows, instant motion, saturated accents. Neo-brutalist / zine.

| Knob | Enum | Default | Effect |
|---|---|---|---|
| `shadow_offset` | `sharp` · `pronounced` · `extreme` | `sharp` | Pixel offset of hard box-shadow (4 / 8 / 12). |
| `contrast_boost` | `standard` · `high` · `extreme` | `standard` | How dark text gets + how saturated accent becomes. |
| `shadow_color_mode` | `neutral` · `brand` | `neutral` | Shadow is monochrome (neutral) or pulls from `--ui-accent-brand` (brand). In dark mode the neutral shadow flips to near-white so it remains visible. |

## Passing knobs

CLI (repeatable `--knob=name:value`):

```bash
bin/semitexa skins:generate balanced "#2f6fed" \
  --knob=radius_scale:rounded --knob=shadow_intensity:pronounced --write
```

Via LLM (the model emits a `knobs` object alongside algorithm + seed); override by passing `--knob=…` explicitly.

Unknown keys or out-of-enum values fail fast with an `InvalidArgumentException`. Missing knobs take the schema default — never emit partial knob sets.

## Adding a fourth algorithm

1. **Implement `SkinAlgorithm`** in `src/Skin/Algorithm/<Name>Algorithm.php`. Four methods:

   ```php
   public function id(): string               // stable slug, e.g. "editorial"
   public function description(): string      // one-liner shown in CLI + LLM prompt
   public function knobSchema(): array        // ['knob_name' => ['enum' => [...], 'default' => 'x', 'description' => '...']]
   public function generate(SkinParams $params): SkinPalette  // emit all 41 tokens
   ```

2. **Honour `SkinMode`.** `$params->mode` is `SkinMode::Light | SkinMode::Dark`. Both must produce readable palettes with ≥ 4.5:1 brand-accent contrast against `SurfacePage`. Reference implementation: `BalancedAlgorithm::generateColorTokens()`.

3. **Register.** Add the class to the list in `SkinAlgorithmRegistry::__construct()`. It's deliberately hardcoded — one file, one line.

4. **Add 1–2 few-shot examples** to `resources/llm/skin-resolve-fewshot.json` — concrete examples meaningfully shape LLM performance even though the prompt body is dynamic.

5. **Validator + prompt pick it up automatically.** `OutputValidator` accepts any registered algorithm; `PromptResolverFactory` injects the new algorithm's `{{ALGORITHM_SECTIONS}}` block into the system prompt at runtime. No edits to `skin-resolve-prompt.md`, `skin-resolve-schema.json`, or `OutputValidator.php`.

## Contract guarantees

- **Every algorithm emits all 41 tokens, in both modes.** Missing tokens break primitives that rely on `var(--ui-X)` — fallbacks exist but are hardcoded aesthetics that won't match your algorithm's character.
- **`id()` is stable.** Referenced from `skin.json` manifests and theme `skin` fields. Renaming an id is a breaking change for every stored skin.
- **`knobSchema()` is authoritative.** CLI parser, LLM validator, refinement resolver, and the `--describe` output all read it. Never validate knobs separately.
- **Determinism.** Given the same `SkinParams`, `generate()` must always return the same `SkinPalette`. No clocks, no randomness, no environment.

## The `@AsSkinAlgorithm` attribute

An `@AsSkinAlgorithm` PHP attribute is defined for future auto-discovery, but v2 uses manual registration via the registry constructor. Tagging an algorithm with the attribute is harmless today and will become the discovery mechanism in a later iteration.

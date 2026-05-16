# LLM prompt reference

Canonical description of **what platform-ui ships for LLM-assisted skin generation + refinement**. If you integrate an external LLM (not via `semitexa/llm`), use this as the reference for output contract, prompt template, and validation rules.

## Shipped artifacts

All under `packages/semitexa-platform-ui/resources/llm/`:

- **`skin-resolve-prompt.md`** — system prompt template for **generation**. Contains a `{{ALGORITHM_SECTIONS}}` placeholder replaced at runtime by `PromptResolverFactory` with a markdown block describing every registered algorithm (id, description, knob schema). Adding a new algorithm requires zero edits to this file.
- **`skin-resolve-fewshot.json`** — 9 labeled examples covering all three algorithms (balanced / glass / brutalist) and mood categories. Each example is validated against the output contract before shipping.
- **`skin-resolve-schema.json`** — JSON Schema draft 2020-12 defining the strict output contract.

Refinement (`skin:refine`) uses a **different** system prompt, built dynamically from the target skin's algorithm knob schema in `Semitexa\PlatformUi\Llm\RefinementResolver::buildSystemPrompt()`. It is not loaded from a file because the prompt must reflect the current knob values of the skin being refined.

## Generation output contract (enforced by `OutputValidator`)

```json
{
  "seed": "#rrggbb",                            // primary color, lowercase hex
  "algorithm": "balanced" | "glass" | "brutalist",
  "knobs": { "knob_name": "enum_value", … },    // algorithm-specific; omit a knob to accept its default
  "accent_hint": "#rrggbb" | null,              // optional secondary hue
  "mood": "calm" | "energetic" | "corporate" | "playful" | "accessible" | "minimal",
  "rationale": "string, max 200 chars"
}
```

The `algorithm` enum is populated from the `SkinAlgorithmRegistry` — register a new algorithm and it becomes accepted here too.

### Validation pipeline (fail-fast with correction hint)

1. JSON schema — structure + required fields + types
2. Hex format — `^#[0-9a-f]{6}$` for `seed` and `accent_hint`
3. OKLCH sanity — seed lightness ∈ [0.35, 0.55], chroma ≤ 0.25
4. WCAG AA — seed contrast against white ≥ 4.5:1 (configurable via `SkinParams::contrastFloor`)
5. Accent distance — if `accent_hint` present, ≥ 30° hue difference from seed
6. Algorithm exists in registry
7. Knob keys + values validate against the chosen algorithm's `knobSchema()`

Each failure emits a specific **correction hint** fed back to the model for the next retry (see `ValidationException` factory methods — `invalidAlgorithm()`, `invalidKnobs()`, etc.). Retry policy: 3 attempts, no random-seed fallback.

## Refinement output contract (enforced by `RefinementResolver::validate()`)

```json
{
  "knob_deltas": { "knob_name": "new_value", … },
  "rationale": "string, short explanation"
}
```

- `knob_deltas` contains **only** the knobs being changed — the resolver merges them over the current knob set.
- Keys must exist in the target algorithm's `knobSchema()`; values must be in-enum.
- When the user's request can't be expressed within the available knobs, the model must return empty `knob_deltas` and explain in `rationale`.
- Algorithm / seed / mode are **fixed** — never included in this response.

## Dynamic prompt assembly

`PromptResolverFactory::create()` runs at command invocation time:

1. Read `skin-resolve-prompt.md`.
2. For each algorithm in the registry, render a markdown section (id, description, knob table with enums + defaults + descriptions).
3. `str_replace('{{ALGORITHM_SECTIONS}}', $block, $template)`.
4. Instantiate `PromptResolver` with the finished system prompt + few-shot history + `OutputValidator`.

Net effect: adding a `SkinAlgorithm` to the registry teaches the LLM, the validator, and the CLI `--describe` output about the new algorithm in a single edit. No prompt-file maintenance.

## Building your own integration

If you're not using `semitexa/llm`, load the template and render the algorithm block yourself:

```php
use Semitexa\PlatformUi\Skin\SkinAlgorithmRegistry;

$registry = new SkinAlgorithmRegistry();
$template = file_get_contents(__DIR__ . '/resources/llm/skin-resolve-prompt.md');
$sections = array_map(function ($algo) {
    $lines = ["## `{$algo->id()}`", '', $algo->description()];
    foreach ($algo->knobSchema() as $name => $spec) {
        $values = implode(' | ', $spec['enum']);
        $lines[] = "- `{$name}`: `{$values}` (default `{$spec['default']}`) — {$spec['description']}";
    }
    return implode("\n", $lines);
}, $registry->all());
$systemPrompt = str_replace('{{ALGORITHM_SECTIONS}}', implode("\n\n", $sections), $template);

$fewShot = json_decode(file_get_contents(__DIR__ . '/resources/llm/skin-resolve-fewshot.json'), true);
// Build history as [user, assistant] turns from $fewShot, then append current prompt.
// Parse the response through Semitexa\PlatformUi\Llm\OutputValidator::validate().
```

The validator is provider-agnostic — it only sees the text the model returned.

## Observed performance

From eval-corpus runs on `gemma4:e2b` (5.1B Q4_K_M, remote Ollama):

- Hit rate: **80%+** (threshold met for v1; v2 corpus is being expanded with algorithm-specific prompts)
- Typical latency: 13–17s per call
- Common failure: warm-light concepts ("vivid sunset", terracotta) bumping OKLCH lightness outside the [0.35, 0.55] envelope

Larger models (Llama 3.1 70B, Claude Haiku, GPT-4o mini) score higher on warm-mono cases. The validator + retry is provider-agnostic; swap the model via `LLM_REMOTE_OLLAMA_MODEL`.

## Running the eval locally

```bash
bin/semitexa platform-ui:eval:run --fail-threshold=0.8
```

Writes `eval/last-report.json`. Used as regression gate when grammar, prompt template, few-shot, registry, or validator changes.

## Reproducibility

Every LLM-generated skin records its full provenance in `skin.json`:

```json
{
  "source": "prompt",
  "prompt": "punk zine manifesto, anti-corporate",
  "llm": {
    "skill": "platform-ui.skin.resolve-prompt",
    "skill_version": "1.0",
    "model": "gemma4:e2b",
    "attempts": 1,
    "rationale": "…"
  },
  "algorithm": "brutalist",
  "seed": "#d93025",
  "knobs": { "shadow_offset": "pronounced", "contrast_boost": "high", "shadow_color_mode": "brand" },
  "mode": "light",
  "tokens": { "--ui-surface-page": "#fefefe", … }
}
```

Any LLM-generated skin can be re-generated offline via `skin:generate <algorithm> "<resolved.seed>" --knob=…` without touching an LLM. The LLM is a UX layer, never a load-bearing dependency.

# Skin refinement (`skin:refine`)

Iterate on an existing skin by adjusting its knobs. The algorithm stays fixed (brutalist doesn't become glass mid-refinement), the seed stays fixed, the mode stays fixed — only knobs change.

## Two modes

```bash
# LLM — free-form prose; the model decides which knobs to move
bin/semitexa skins:refine enterprise --prompt="more impression, less glare" --write

# Structured — explicit key:value pairs, no LLM involved (repeatable)
bin/semitexa skins:refine enterprise --set=shadow_intensity:pronounced --set=motion_speed:slow --write
```

Mutually exclusive: you can't combine `--prompt` and `--set` in a single call.

Default is dry-run preview; pass `--write` to persist. Dry-run shows proposed deltas, rationale, and the full knob set after the change without touching disk.

## Refining the framework default

If the target slug is the framework-shipped default (in `vendor/semitexa/skins-base/…/default/`), the refine always writes the output to the project's `src/skins/<slug>/` instead of mutating the vendor dir. This keeps the framework default pristine across refinement chains and makes the override project-local. On the next resolution, `SkinDiscovery` returns the project override automatically (project source takes priority over framework).

```bash
bin/semitexa skins:refine default --set=radius_scale:rounded --write
# → wrote src/skins/default/tokens.css — overrides framework default
```

Undo: `rm -rf src/skins/default/` — framework default resumes.

## Overwrite vs fork

```bash
# Overwrite (default) — updates the same slug in place; history[] grows
bin/semitexa skins:refine enterprise --prompt="…" --write

# Fork — writes to a NEW slug, original untouched
bin/semitexa skins:refine enterprise --as=enterprise-dark-shadows --prompt="…" --write
```

Fork copies the full manifest (including full history), then appends the refine entry. Useful for A/B iterations without losing the baseline.

## History audit trail

Every refine appends one entry to `skin.json`'s `history[]`:

```json
{
  "at": "2026-04-24T09:02:00+00:00",
  "kind": "refine",
  "source": "prompt",
  "deltas": { "shadow_intensity": "pronounced" },
  "rationale": "User asked for heavier feel; pronounced shadow moves raised surfaces forward without touching color balance.",
  "prompt": "more impression",
  "llm": { "model": "gemma4:e2b", "attempts": 1, "latency_ms": 2340 }
}
```

Structured `--set` entries have `"source": "set"`, rationale `"(structured --set edit)"`, no `prompt`/`llm` block. Reading `history[]` bottom-to-top reconstructs how a skin arrived at its current knob state.

## v1 migration tolerance

Skins generated before v2 (24 tokens, no `knobs`/`history`/`mode` fields) are auto-migrated in memory on first refine:

1. Missing `knobs` → defaults from the algorithm's `knobSchema()`.
2. Missing `history` → starts empty, but a synthetic first entry is injected so the audit trail doesn't claim the pre-v2 state was "always v2":

   ```json
   { "at": "…", "kind": "migrated", "from_schema": "1.0", "note": "Auto-migrated v1 skin.json to v2 on first refine; knobs filled from algorithm defaults." }
   ```

3. Missing `mode` → `light` (covers both v1 and early-v2 manifests written before dark-mode support landed).

Refinement then proceeds normally against the migrated state. The on-disk file only updates on `--write`.

## What `skin:refine` won't do

- **Switch algorithm.** Brutalist skins refine into brutalist skins. v1.1+ will expose `--algorithm=X` explicitly; never LLM-decided.
- **Switch mode.** Dark skins refine into dark skins. Change the mode by generating a new skin from the same seed with `--mode=dark`.
- **Change the seed.** Seed is the anchor of reproducibility; altering it would produce a different skin entirely.
- **Add knobs outside the algorithm's schema.** Unknown keys fail fast with the list of valid ones.

When the user's request can't be expressed within the available knobs, the LLM returns empty `knob_deltas` and explains in the rationale — no silent failure, no hallucinated knob.

## Validation & retry

The LLM path enforces:

- Response is valid JSON with `knob_deltas` (object) and `rationale` (non-empty string).
- Every key in `knob_deltas` exists in the algorithm's `knobSchema()`.
- Every value is within that knob's `enum`.

On failure, the resolver retries up to `RetryPolicy::maxAttempts` (default 3), feeding the validation error back to the model as a correction hint. See `Semitexa\PlatformUi\Llm\RefinementResolver`.

## Browser caching caveat

When you **overwrite** an existing slug, the filename (`tokens.css`) doesn't change. Browsers and CDNs cache that URL — often for a year. Hard-refresh (Ctrl+Shift+R) in dev, or use `--as=<new-slug>` so the URL path changes and consumers pick up the new file naturally.

A content-hash URL scheme would solve this at the SSR asset-pipeline level; tracked but not shipped in v2.

## Skill status

`skin:refine` is **CLI-only** — it has no `#[AsAiSkill]` attribute, so the LLM Planner does not route prose to it. Refinement is an iterative operator tool; a user saying "make the skin darker" hits `skin:generate --prompt="…"` (which starts fresh), not `skin:refine`. Add the attribute in a consumer project if you want Planner routing.

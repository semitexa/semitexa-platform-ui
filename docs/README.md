# platform-ui docs

Technical documentation for `semitexa/platform-ui` — grammar, primitives, and the skin-generation domain (algorithms, tokens, LLM prompt resolution).

> **Note:** the `skins:generate` / `skins:refine` / `skins:explain-prompt` CLI commands ship in a sibling package, **`semitexa/skins-base`**. platform-ui owns the algorithms + token contract + LLM library; skins-base owns the CLI + the single framework-default skin. Projects generate skins into their own `src/skins/` directory, served via `SkinDiscovery` in `semitexa/theme`.

- **[grammar.md](grammar.md)** — `sx-*` / `ui-*` attribute reference, 9 domains, 43 slices
- **[primitives.md](primitives.md)** — 6 primitives (button, input, label, field-shell, surface, badge) with variants/tones/sizes
- **[technical-design.md](technical-design.md)** — next-generation framework-native UI declaration architecture
- **[framework-layer-improvements.md](framework-layer-improvements.md)** — prerequisite Semitexa Framework changes for the next-generation UI module
- **[skin-generation.md](skin-generation.md)** — v2 generator: 3 algorithms, 41 tokens, light+dark modes, token contract, manifest schema
- **[skin-algorithms.md](skin-algorithms.md)** — per-algorithm knob reference (balanced/glass/brutalist) + how to add a fourth
- **[skin-refinement.md](skin-refinement.md)** — `skin:refine` flow: LLM deltas vs `--set`, fork vs overwrite, v1 migration, history audit trail
- **[ssr-integration.md](ssr-integration.md)** — static bundles today, dynamic per-route (pending hook)
- **[llm-prompt.md](llm-prompt.md)** — shipped system prompt (with dynamic algorithm sections) + validator contract for external LLM integrations
- **[skill-contract.md](skill-contract.md)** — `#[AsAiSkill]` usage and internal LLM consumption

## Quick reference — CLI

```bash
# Generate — deterministic (seed mode)
skins:generate <algo> <hex> [--name --mode=light|dark --knob=name:value --write]

# Generate — LLM-assisted (prompt mode). --algorithm / --knob / --mode override the LLM
skins:generate --prompt="<text>" [--name --algorithm=<id> --mode=light|dark --write]

# Refine an existing skin — LLM deltas OR structured
skins:refine <slug> --prompt="<text>" [--as=<new-slug> --write]
skins:refine <slug> --set=name:value [--set=… --as=<new-slug> --write]

# Introspect
skins:generate --describe                      # all algorithms + knob schemas
skins:explain-prompt "<text>"                  # preview LLM resolution, no CSS emission

# CSS
platform-ui:css:build                                     # precompile full.css, baseline.css
platform-ui:css:inspect <template>                        # scan usage, report slices + bundle size
platform-ui:css:explain <slice-id|primitive-id>           # CSS, layer, tokens referenced

# Eval
platform-ui:eval:run [--fail-threshold=0.8]               # run prompt-resolution eval corpus
```

## Architecture one-liner

`sx-*` / `ui-*` attributes + multi-algorithm OKLCH skin engine (balanced / glass / brutalist, light + dark, 41 design tokens, knob-tuned, LLM-assisted or deterministic) + 6 primitives + per-request slice compilation → per-route CSS bundles typically under 3KB gzipped.

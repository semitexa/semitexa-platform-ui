# platform-ui docs

The user documentation for this module lives in the documentation hub
(`semitexa/docs`), served at `/docs`. This folder keeps only the design
records that describe how the module got here.

## Where the documentation went

| Subject | Page |
|---|---|
| The `ui="..."` primitive vocabulary and its runtime | `rendering/ui-primitives` |
| `UiPart` / `UiSlot`, part props, `bind`, slots | `rendering/ui-composition` |
| `#[UiOn]`, the signed event manifest, dispatch, SSE | `rendering/ui-events` |
| `platform.form`, the submit pipeline, playground | `rendering/ui-forms` |
| The attribute grammar | `rendering/ui-grammar` |
| Sitting on top of the SSR module | `rendering/ui-ssr-integration` |
| The server-side field rules DSL | `validation/ui-field-validation` |
| Generating, tuning and refining skins | `platform/skin-generation`, `platform/skin-algorithms`, `platform/skin-refinement` |
| `#[AsAiSkill]` and the prompt surface | `llm/skill-contract`, `llm/prompt-reference` |

Every attribute and command this module defines is also listed, generated
from the source, in `reference/attributes-platformui.md` and
`reference/commands-platform-ui.md`.

## What stays here

| File | What it is |
|---|---|
| `technical-design.md` | The design that shaped the module. Describes a target state; not all of it shipped. |
| `transport-architecture.md` | ADR-0001, the transport unification decision. |
| `framework-layer-improvements.md` | Framework changes the module asked for. |

These are historical records, not instructions. Where they disagree with the
hub, the hub is right.

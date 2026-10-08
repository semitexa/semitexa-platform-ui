# Semitexa Platform UI

`semitexa/platform-ui`

The UI layer on top of `semitexa/ssr`: design tokens, a CSS grammar (`ui="…"` primitives), built-in components, client behaviours, and LLM-assisted skin generation that feeds the skin builder in `semitexa/theme`.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

## What it provides

- Built-in components (`platform.*`), among them `form`, `field`, `grid`, `table`, `list`, `card`, `stat`, `chart`, `calendar`, `dashboard`, `dashboard-widget`, `navbar`, `breadcrumb`, `pagination`, `empty-state`, `command-palette`, `collab-form`, `appearance-settings`, the auth screens (`sign-in`, `sign-up`, `forgot-password`, `reset-password`) and page blocks (`block-hero`, `block-features`, `block-pricing`, `block-faq`, `block-stats`, `block-cta`, `block-footer`).
- Attributes (namespace `Semitexa\PlatformUi\Attribute`):
  - components: `#[UiPart]`, `#[UiSlot]`, `#[ProvidesUiPart]`, `#[UiState]`, `#[UiUrl]`, `#[UiOn]` (event handler method), `#[HandlesUiEvent]`;
  - registries: `#[AsUiPrimitive]`, `#[AsUiBehavior]`, `#[AsUiContract]`, `#[AsFieldType]`, `#[AsFormSubmitAction]`, `#[AsGridAction]`, `#[AsDashboardWidget]`, `#[AsCommandSource]`, `#[AsUiTreeAction]`, `#[CollaborativeForm]`.
- Console commands:
  - catalog and CSS: `platform-ui:catalog`, `platform-ui:field-types`, `platform-ui:css:build`, `platform-ui:css:explain`, `platform-ui:css:inspect`, `platform-ui:icons:sync`;
  - skins: `skins:generate`, `skins:refine`, `skins:rebuild`, `skins:explain-prompt`, `skins:eval:run`;
  - UI trees for agents: `ui:tree:catalog`, `ui:tree:validate`, `ui:tree:render`, `ui:tree:compose`, and `ui:mcp` (an MCP server on stdio).
- Tables `form_collab_draft` (collaborative form drafts), `platform_calendar_events` and `platform_ui_demo_submissions`, created by `bin/semitexa orm:sync`.

## Documentation

- Primitives: https://semitexa.com/docs/rendering/ui-primitives
- Composition (parts, slots): https://semitexa.com/docs/rendering/ui-composition
- Events (`#[UiOn]`): https://semitexa.com/docs/rendering/ui-events
- Forms: https://semitexa.com/docs/rendering/ui-forms
- Skin generation: https://semitexa.com/docs/platform/skin-generation
- Attributes: https://semitexa.com/docs/reference/attributes-platformui
- Commands: https://semitexa.com/docs/reference/commands-platform-ui

Design records for the module are in [`docs/`](docs/README.md).

## License

MIT, see [LICENSE](LICENSE).

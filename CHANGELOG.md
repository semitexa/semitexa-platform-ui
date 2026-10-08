# Changelog

Changes to `semitexa/platform-ui` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## Unreleased

### Changed
- Component events are `#[UiOn]` methods answered with one effect vocabulary;
  re-renders are server-side morphs. Upgrade guide: docs `migration/one-component-model`.
- `/__ui/event`, `/__ui/dispatch` and `/__ui/form-doc` are gone: feeds are
  subscribed through HUG by route name, frames arrive on KISS, feeds are GET-only.
- The page's KISS session meta is put into `<head>` once by islands and grids;
  a hand-written `ui_page_sse_session_meta()` is no longer needed.
- A handler redirect to anything but a same-origin path is dropped with an error
  log; the rest of the answer goes through.

### Added
- Field types, validation rules, submit lifecycle, HUG uploads, form and display
  primitives, full Lucide set, app shell, dashboards, command palette, grid
  actions, AI UI trees + MCP.
- UI Workbench at `GET /__ui/workbench` (dev, or `PLATFORM_UI_WORKBENCH=1`): every
  catalog entry's contract examples rendered by the real runtime, with copyable Twig.
- `platform.input` takes `label`, rendered as its `aria-label`.

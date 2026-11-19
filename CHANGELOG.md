# Changelog

Changes to `semitexa/platform-ui` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased

### Fixed
- A collaborative form's shared draft now ends. After the record's save commits,
  call `FormCollaborationDrafts::discardForRecord($formKey, $recordId, $values)`
  with the stored value of every managed field: open editors are re-based on
  them (without values they keep their inputs and the page gets the
  `semitexa:collab:reset` event). A draft nobody wrote to or
  was present in for 30 minutes (`PLATFORM_UI_COLLAB_DRAFT_IDLE_MINUTES`,
  0 = never, at most a day) is dropped on the next read. Before, an unsaved edit
  from days ago was written into the next editor's inputs.
- Presence chips and "Locked by …" show a name instead of the user id: the
  app's optional `FormCollaboratorLabelResolverInterface`, else the user's
  `getDisplayName()` / `getName()`, else the id.

### Changed
- A custom `FormCollabDraftStoreInterface` needs `discard()` and the new last
  parameter of `apply()`, `?string $expectedEpoch` (the write is refused when
  the row it would change belongs to another draft), and a custom
  `FormPresenceStoreInterface` needs `lastSeenAt()`.
- An Optimistic save (`form.save`) names the draft it was based on (`epoch`);
  one that does not, from a tab opened before the upgrade, is refused with
  `collab_client_outdated` ("This page is out of date. Reload to continue.").
- A handler's `UiEventError::$details` are sent to the browser with the error
  (`details` in the response body).

## 2026.10.08.0620 — 2026-10-08

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

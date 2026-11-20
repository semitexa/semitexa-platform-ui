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
- A collaborative form no longer lets an older value of a field overwrite the
  one typed last while the editor stays on the page. Each keystroke was its
  own request carrying the whole value, and a request that arrived late
  overwrote a newer one in the shared draft. A field now has one edit in
  flight and the latest value follows when it settles. An edit with no answer
  in 10 s is aborted; a value that failed (network, timeout, 5xx) is sent
  again at once, then on up to three presence heartbeats, then dropped.
  A lock release waits for the field's edits, at most 25 s, and tries a failed
  value once more first. This is best effort: when that try fails too, or the
  wait runs out, the lock is released and the value is not in the draft.
  When the page is hidden the latest value and a waiting release go out at
  once with `keepalive`, except for the fields the app's own form post
  carried: the post carries their values, and an edit arriving behind the
  save would start a new draft. A field of another form, or one typed into
  after the submit, is sent as usual.
  Known limits: a value sent as the page is hidden can still arrive before an
  older edit that was in flight, and a page restored from the back/forward
  cache can retry an older edit after the newer value went out with
  `keepalive` (only a sequence number from the server would order them); an
  edit one participant sent before another participant's save can still
  arrive after that save and open a new draft holding the older value (only
  a server check of the save that ended the draft would refuse it); a
  refused edit (4xx, for example an expired session) is not sent again, as
  before.
- A field another participant holds (FormLock, FieldLock) is in the form the
  app posts. It is `disabled`, a browser leaves disabled controls out, and a
  save made meanwhile stored the field empty. On the form's `submit` the
  runtime enables the fields it disabled itself while the browser reads the
  form, and disables them again right after; an app that enabled the inputs in
  its own submit listener can drop that. A control the page disabled itself is
  left alone, also when a lock is released (it used to be enabled).
  Not covered: `form.submit()` and a `new FormData(form)` built outside a
  submit listener, neither of which fires `submit`.
- A FieldLock field changed without focus is sent live. Only focus took a
  field's lock, so a field a page script filled (an autocomplete setting a
  `<select>` and dispatching `input`) or a script chose an option in was
  queued until someone focused it, and the co-editors saw it only after the
  save. Such a change on a free field now takes the field's lock itself, sends
  the edit and releases the lock once the edit settled, as blur does. A focused
  field already holds its lock: typing is unchanged. The lock is also released
  when focus left the field before the server granted it; it used to stay held
  until the page was closed. A change waiting for its lock is not replaced by
  a snapshot that arrives first.
  Known limits: a second focus or change without focus while a `lock.acquire`
  is on the way posts another `lock.acquire`, as before; and a `lock.acquire`
  that never answers leaves the change queued, and its input closed to
  snapshots, until the field asks again (a focus, or another change).
  A change without focus of a field another participant holds (FieldLock), or
  of any field while another participant holds the form (FormLock), is not
  sent, and no longer goes out later when the lock becomes free; the input
  goes back to the draft's value, since the app's form post would carry what
  the script wrote over what the holder typed.
  FormLock is otherwise unchanged, on purpose: a change without focus does not
  take the whole-form lock (that would make every co-editor's form read-only
  for a script). It is sent at once when this participant holds the form, and
  otherwise with the lock their next focus takes.
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

# Changelog

Changes to `semitexa/platform-ui` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased

### Fixed
- A late request no longer puts an older value back into a collaborative form's
  draft. Field edits were ordered only by when they arrived, and two cases stayed
  open: the value a page sends as it is hidden races the edit still in flight,
  and an edit the page gave up on after 10 s can still be processed, after the
  newer value. Every `field.edit` now carries `seq` (one count per page) and
  `runtime` (the page's id), and the draft keeps the highest sequence stored per
  field and page, written in the same step as the value. An older edit is
  refused (422, `field_edit_superseded`), which the runtime takes as an answer
  and does not send again; the same edit arriving twice is acknowledged and
  written once. Only edits of one page are compared: two people, and two tabs
  of one person, stay ordered by arrival. The order ends with the draft.
  **Schema:** `form_collab_draft` gains the nullable column `edit_marks_json`
  (`bin/semitexa update` / `orm:sync` adds it). **Own draft store:** ordering is
  the new, optional `FormCollabOrderedDraftStoreInterface`; a store that
  implements only `FormCollabDraftStoreInterface` keeps working and keeps
  arrival order.
- A collaborative form keeps saving after its page has been open five minutes.
  Its events carry signed contexts that live `SignedContext::DEFAULT_TTL_SECONDS`
  and nothing renewed them: past that every edit, lock and presence ping was
  refused (422, `Signed context expired.`) with nothing shown. The manifest now
  carries a `renew` context, signed for a day for the session, tenant and
  visitor the page was drawn for. When an event is refused as expired the
  runtime sends it as `manifest.renew`, gets the same event contexts signed
  again, subscribes the document feed with the new one and sends the refused
  event once more. An expired context is still refused. An application's
  `UiInteractionAuthorizerInterface` is asked for the renewal as for any event
  (part `manifest`, event `renew`): one that allows events by name has to
  allow this one. It is then asked about every event of the form's mode
  (`field.edit`, the lock events, ...) with the form's scope in `config`, as if
  each were being sent, and one denial refuses the renewal (403,
  `collab_renew_forbidden`): the rule an application has for edits governs
  renewals too, so access that ended while the tab was open ends with the
  contexts the page holds, not a day later. The replay store gives a renewal
  what it gives any event and no more: one event id is answered once, for at
  most 600 s; a new event id renews again, by design. A renewal the server
  refuses (signed out or into another account, access ended, a day gone) is
  not asked again: the status host gets `data-state="expired"` and the form
  dispatches `semitexa:collab:expired`, for the page to ask for a reload. One
  that got no answer (the network, a 5xx, a 429) is asked again by the next
  expired event, five seconds later at the earliest. The value of a field
  refused meanwhile stays pending and goes with the renewal that succeeds,
  unless a newer one was typed or arrived from another editor meanwhile; a
  refusal whose body arrives after a newer value of the field was typed sends
  nothing.
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
- A FieldLock field changed twice without focus (a script choosing an option,
  then another) sends both changes. The second one was sometimes lost, about
  one time in three: a snapshot published while the first change held the
  field's lock arrived after its release, the page took the lock as still
  held, sent the second change with no lock (422 `field_locked`) and never
  asked again. A lock this page released is no longer brought back by a
  snapshot; it is held again only once the page asked for it. And an edit the
  server answers with `field_locked` now asks for the field's lock once and
  is sent again; refused a second time, or the lock refused, it ends as a
  change of a field another participant holds does. A change the form's own
  post carried is not sent again after a late `field_locked`: the post has it.
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
  is on the way posts another `lock.acquire`, as before. A `lock.acquire`
  that never answers leaves the change queued, and its input closed to
  snapshots, until the field asks again (another change, or a focus unless a
  snapshot already shows the lock as this participant's). A `lock.acquire`
  answered with a failure drops a change made without focus: the input keeps
  the value, and it is not sent. When the answer is lost while a snapshot
  shows this participant holding the field, the lock is renewed and the change
  is not sent (tk-collab-lost-acquire-answer-keeps-a-lock-renewed).
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

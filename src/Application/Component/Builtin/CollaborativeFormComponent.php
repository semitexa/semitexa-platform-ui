<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\FormRootPrimitive;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * Collaborative Form Data · Phase 3 — `platform.collab-form`, the live
 * co-editing composition shell. The collaborative sibling of
 * {@see FormComponent}: where that aggregates client-local validation and
 * submits once, this binds a form's fields to a shared document so every
 * participant sees each other's edits over SSE.
 *
 * The component itself is a thin DECLARATION — it renders a form root, a
 * presence host, and a status host, then emits the signed collab manifest
 * (`ui_collab_manifest()`), which `form-collab-runtime.js` picks up to open the
 * `/__ui/form-doc` feed and relay edits. The actual command handling lives in
 * {@see \Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler},
 * bound to this component's parts via `#[HandlesUiEvent]`.
 *
 * Parts exist as the binding anchors the dispatcher validates a signed event's
 * `(component, part, event)` against — `field`/`presence`/`lock` are declared
 * as slots (no rendered primitive) precisely because the interaction arrives
 * over the canonical `/__ui/event` write path keyed by the signed claim, not by
 * DOM part rendering. `content` is the caller's field markup (inputs tagged
 * `data-ui-field-name`).
 */
#[AsComponent(
    name: 'platform.collab-form',
    template: '@platform-ui/components/runtime/collab-form.html.twig',
    cacheable: false,
)]
#[UiPart(name: 'form', uses: FormRootPrimitive::class)]
#[UiSlot(name: 'field', description: 'Per-field edit binding anchor (field.edit).')]
#[UiSlot(name: 'presence', description: 'Presence heartbeat binding anchor (presence.ping).')]
#[UiSlot(name: 'lock', description: 'Lock lifecycle binding anchor (lock.acquire/release/heartbeat).')]
#[UiSlot(name: 'content', description: 'Caller-provided field inputs (tagged data-ui-field-name).')]
final class CollaborativeFormComponent
{
}

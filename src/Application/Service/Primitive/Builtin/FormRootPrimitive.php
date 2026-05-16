<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

/**
 * Metadata-only primitive that lets FormComponent declare a `form`
 * UiPart without forcing a parallel rendering path.
 *
 * Why a primitive at all? UiPart's contract requires `uses:` to point
 * at a class marked with `#[AsUiPrimitive]` — UiComponentMetadataFactory
 * rejects anything else. The Platform UI dispatcher then resolves
 * `(component, part, event)` against the registered component
 * metadata; `metadata->part('form')` must exist for `UiOn(part:'form',
 * event:'submit')` to be routable.
 *
 * The FormComponent template renders the actual `<form>` element
 * directly (with `data-ui-part="form"` emitted in place) so the
 * caller's content slot, the form-status target, and the submit
 * button all live in one natural DOM subtree. This primitive's
 * template renders an empty `<form>` shell on the (currently unused)
 * `ui_part('form', ...)` path so the helper still works if a
 * downstream template ever invokes it.
 *
 * Submit transport: the `<form>` element fires a native `submit`
 * event the runtime captures through its existing delegated listener.
 * No primitive-level scripting is required.
 */
#[AsUiPrimitive(
    name: 'platform.form-root',
    ui: 'form-root',
    template: '@platform-ui/primitives/runtime/form-root.html.twig',
    style: 'platform-ui:css:full',
)]
final class FormRootPrimitive
{
}

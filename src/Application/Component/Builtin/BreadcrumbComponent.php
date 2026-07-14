<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

/**
 * platform.breadcrumb — a mostly data-driven navigation trail.
 *
 * The trail itself is prop-driven: the caller passes an ordered `items`
 * list and the template renders a semantic `<nav><ol>`. The last item is
 * the current page (aria-current="page", never a link); earlier items link
 * when they carry an `href`.
 *
 * The one slot, `trailing`, holds inline actions rendered after the trail
 * (e.g. a "copy path" button or a status badge) — the same pattern seen in
 * GitHub/GitLab breadcrumb bars. It also makes breadcrumb a first-class
 * Platform UI component: discovery keys components off #[UiPart]/#[UiSlot],
 * so a purely data-driven component would otherwise stay out of the
 * registry/catalog/scaffold surfaces.
 *
 * Props:
 *   - items     — list of { label: string, href?: string }, in order.
 *   - ariaLabel — accessible name for the <nav> (default "Breadcrumb").
 * Slot:
 *   - trailing  — optional inline actions rendered after the trail.
 *
 * Styling lives in css/components.css under @layer platform-ui.primitives,
 * keyed off `[ui-component="breadcrumb"]`; the separator is a CSS ::before
 * so it is never announced by screen readers.
 */
#[AsComponent(
    name: 'platform.breadcrumb',
    template: '@platform-ui/components/runtime/breadcrumb.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'trailing', description: 'Optional inline actions rendered after the trail (e.g. a copy-path button or status badge).')]
final class BreadcrumbComponent
{
}

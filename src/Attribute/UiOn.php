<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares — as metadata only — which (part, event) pair a method is the
 * intended handler for.
 *
 * Identity:
 *   - $part    — name of a #[UiPart] declared on the same component.
 *                The factory rejects unknown parts at metadata-extraction
 *                time.
 *   - $event   — semantic event name (e.g. "change", "click", "blur").
 *                Validated against /^[a-z][a-z0-9:_-]*\$/ — no spaces,
 *                no uppercase, no JavaScript, no Twig delimiters,
 *                no brackets or quotes.
 *   - $updates — optional dot-separated UiValuePath that this event
 *                updates. When omitted AND the target part declares a
 *                bind path, the bind path is inferred. When explicitly
 *                provided AND the part is bound, the path MUST equal
 *                the part bind path (strict-compatibility mode in this
 *                slice).
 *
 * Targets a public, non-static, non-abstract instance method. The method
 * is NOT invoked in this slice — it exists purely as a discoverable
 * handler-intent marker for future runtime steps.
 *
 * Not repeatable on a single method. A class may declare multiple UiOn
 * handlers across different methods, but each (part, event) pair is
 * unique within one component (enforced by the factory).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class UiOn
{
    public function __construct(
        public string $part,
        public string $event,
        public ?string $updates = null,
    ) {}
}

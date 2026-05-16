<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Typed result of a UiFormSubmitActionInterface invocation.
 *
 * The action layer is intentionally narrow in this slice:
 *
 *   - `accepted`     : did the action complete successfully?
 *   - `message`      : user-facing form-status text.
 *                      Used VERBATIM as the `form-status` setText
 *                      value, so it MUST be safe to display.
 *   - `debug`        : safe-to-log shape merged into the response's
 *                      `debug.action` key. NEVER include raw submitted
 *                      values, secrets, class FQCNs, or service ids.
 *   - `extraPatches` : optional UiResponsePatch list the action may
 *                      contribute on top of the per-field + form-level
 *                      summary patches. Validated through the same
 *                      UiPatchValidator the rest of the pipeline uses,
 *                      so an action cannot target an unsigned instance
 *                      or use an unallow-listed op/attribute.
 *
 * Deliberate non-features (left for later slices):
 *
 *   - no `redirect` variant — adding a redirect is a separate slice
 *     (needs CSRF, session policy, allow-list of safe URLs);
 *   - no `persistence` variant — persistence requires storage-specific
 *     validation + authorization;
 *   - no `html` variant — would break the inert-patch trust perimeter.
 */
final readonly class UiFormSubmitActionResult
{
    /**
     * @param array<string, mixed>    $debug
     * @param list<UiResponsePatch>   $extraPatches
     */
    public function __construct(
        public bool   $accepted,
        public string $message,
        public array  $debug = [],
        public array  $extraPatches = [],
    ) {}

    /**
     * @param array<string, mixed>    $debug
     * @param list<UiResponsePatch>   $extraPatches
     */
    public static function accepted(
        string $message = 'Form action completed.',
        array $debug = [],
        array $extraPatches = [],
    ): self {
        return new self(true, $message, $debug, $extraPatches);
    }

    /**
     * @param array<string, mixed>    $debug
     * @param list<UiResponsePatch>   $extraPatches
     */
    public static function rejected(
        string $message = 'Form action rejected.',
        array $debug = [],
        array $extraPatches = [],
    ): self {
        return new self(false, $message, $debug, $extraPatches);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDebug(): array
    {
        $out = [
            'accepted' => $this->accepted,
            'message'  => $this->message,
        ];
        if ($this->debug !== []) {
            $out['detail'] = $this->debug;
        }
        return $out;
    }
}

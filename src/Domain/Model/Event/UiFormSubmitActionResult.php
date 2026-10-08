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
 *   - `fieldErrors`  : field name → message, shown on that field exactly
 *                      like a validation failure (a uniqueness check the
 *                      rules cannot express). Names must be signed fields.
 *   - `reset`        : restore the form's controls after success.
 *   - `redirectTo`   : same-origin path to leave for; the form is then
 *                      neither reset nor re-armed.
 *   - `closeModal`   : close the overlay the form sits in.
 *
 * Built fluently: `rejected('Fix the errors')->withFieldErrors([...])`,
 * `accepted('Saved')->resettingForm()`, `accepted()->redirectingTo('/x')`, `accepted('Sent')->closingModal()`.
 *
 * Deliberate non-features:
 *
 *   - no `persistence` variant — persistence requires storage-specific
 *     validation + authorization;
 *   - no `html` variant — would break the inert-patch trust perimeter.
 */
final readonly class UiFormSubmitActionResult
{
    /**
     * @param array<string, mixed>    $debug
     * @param list<UiResponsePatch>   $extraPatches
     * @param array<string, string>   $fieldErrors
     */
    public function __construct(
        public bool   $accepted,
        public string $message,
        public array  $debug = [],
        public array  $extraPatches = [],
        public array  $fieldErrors = [],
        public bool   $reset = false,
        public ?string $redirectTo = null,
        public bool   $closeModal = false,
    ) {}

    /** @param array<string, string> $fieldErrors */
    public function withFieldErrors(array $fieldErrors): self
    {
        return new self($this->accepted, $this->message, $this->debug, $this->extraPatches, $fieldErrors, $this->reset, $this->redirectTo, $this->closeModal);
    }

    public function resettingForm(): self
    {
        return new self($this->accepted, $this->message, $this->debug, $this->extraPatches, $this->fieldErrors, true, $this->redirectTo, $this->closeModal);
    }

    /** Close the modal (or offcanvas, or <dialog>) the form sits in. */
    public function closingModal(): self
    {
        return new self($this->accepted, $this->message, $this->debug, $this->extraPatches, $this->fieldErrors, $this->reset, $this->redirectTo, true);
    }

    public function redirectingTo(string $path): self
    {
        return new self($this->accepted, $this->message, $this->debug, $this->extraPatches, $this->fieldErrors, $this->reset, $path, $this->closeModal);
    }

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
        if ($this->fieldErrors !== []) {
            $out['fieldErrors'] = array_keys($this->fieldErrors);
        }
        return $out;
    }
}

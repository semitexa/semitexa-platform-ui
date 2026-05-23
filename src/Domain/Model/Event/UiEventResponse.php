<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Canonical handler return contract for the UI Interaction Layer
 * (technical-design.md §12.8).
 *
 * The value object is the *intent* a handler expresses; the dispatcher /
 * normalizer is responsible for translating the high-level patch maps
 * (`$statePatch`, `$partPropsPatch`, `$componentPropsPatch`) and the
 * instructions (`$redirect`, `$notification`, `$sse`, `$frontend`) into the
 * wire-format ops carried by {@see UiResponsePatch} on the existing
 * {@see UiInteractionResult} transport. The two layers compose — they are
 * NOT duplicates.
 *
 * Static factories cover the demonstrated cases from §12.9:
 *   - {@see self::ok()}              — no-op acknowledgement;
 *   - {@see self::commandAccepted()} — async command started (`$correlationId`
 *                                      is the SSE join key);
 *   - {@see self::patch()}           — validation + state/part patches for
 *                                      `change` events;
 *   - {@see self::error()}           — typed error response.
 *
 * Direct constructor use is allowed for cases the factories don't cover
 * yet (e.g. SSE subscription updates emitted alongside a redirect).
 */
final readonly class UiEventResponse
{
    /**
     * @param array<string, mixed>              $statePatch
     * @param array<string, array<string, mixed>> $partPropsPatch
     * @param array<string, mixed>              $componentPropsPatch
     * @param array<string, mixed>              $rerender
     * @param array<string, mixed>              $frontend
     * @param array<string, mixed>              $sse
     */
    public function __construct(
        public UiEventResponseStatus $status = UiEventResponseStatus::Ok,
        public ?UiEventValidationResult $validation = null,
        public array $statePatch = [],
        public array $partPropsPatch = [],
        public array $componentPropsPatch = [],
        public array $rerender = [],
        public array $frontend = [],
        public array $sse = [],
        public ?UiEventRedirectInstruction $redirect = null,
        public ?UiEventNotificationInstruction $notification = null,
        public ?string $correlationId = null,
        public ?UiEventError $error = null,
    ) {}

    public static function ok(?string $correlationId = null): self
    {
        return new self(
            status: UiEventResponseStatus::Ok,
            correlationId: $correlationId,
        );
    }

    /**
     * Async command acknowledgement. The handler accepted the work; later SSE
     * messages on `$correlationId` will carry the actual outcome.
     */
    public static function commandAccepted(?string $correlationId): self
    {
        return new self(
            status: UiEventResponseStatus::Ok,
            correlationId: $correlationId,
        );
    }

    /**
     * Synchronous patch response. Validation, state changes, and per-part
     * prop updates are all optional — pass only what changed.
     *
     * The named param shape mirrors technical-design.md §12.9 (`state`,
     * `parts`) and maps onto the constructor's canonical names internally.
     *
     * @param array<string, mixed>                $state
     * @param array<string, array<string, mixed>> $parts
     */
    public static function patch(
        ?UiEventValidationResult $validation = null,
        array $state = [],
        array $parts = [],
        ?string $correlationId = null,
    ): self {
        $hasValidationErrors = $validation !== null && !$validation->isValid();

        return new self(
            status: $hasValidationErrors ? UiEventResponseStatus::Validation : UiEventResponseStatus::Ok,
            validation: $validation,
            statePatch: $state,
            partPropsPatch: $parts,
            correlationId: $correlationId,
        );
    }

    public static function error(UiEventError $error, ?string $correlationId = null): self
    {
        return new self(
            status: UiEventResponseStatus::Error,
            correlationId: $correlationId,
            error: $error,
        );
    }
}

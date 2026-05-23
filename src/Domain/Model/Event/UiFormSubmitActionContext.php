<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Read-only context passed to a UiFormSubmitActionInterface invocation.
 *
 * Surfaced state:
 *
 *   - `formInstanceId`  : the FormComponent's render-time instance id —
 *                         the only identifier patches may target by
 *                         default.
 *   - `actionName`      : the action name the registry resolved (same
 *                         value that was signed into `cfg.a`).
 *   - `dispatchId`      : the per-attempt id the dispatcher already
 *                         consumed for replay protection. Useful for
 *                         correlating logs.
 *   - `values`          : the sanitised `payload.form.values` snapshot
 *                         (safe-identifier keys, scalar / null values,
 *                         bounded count + length). **Still client-
 *                         submitted**, NOT authoritative state; the
 *                         action must treat these as untrusted input.
 *   - `fields`          : the signed UiFormSubmitFieldDefinition list
 *                         the form validated against. Server-owned,
 *                         tampering breaks the HMAC.
 *   - `submitResult`    : the authoritative validation summary. By the
 *                         time a context is built, this is always
 *                         `valid === true` (the action is never invoked
 *                         on invalid forms) but it carries the per-field
 *                         outcome list for downstream audit / debug.
 *   - `subscriberChannelId`: the form-submitter page's canonical KISS
 *                         subscriber channel id (the `sub` claim from
 *                         the verified signed ctx), or `null` if the
 *                         page never called `ui_page_sse_session_meta()`.
 *                         Lets actions publish typed messages back to
 *                         the originating page over `/__semitexa_kiss`.
 *                         Only the shape was re-checked at extraction;
 *                         HMAC binding is what makes it trustworthy.
 *
 * Intentionally omitted from this slice:
 *
 *   - the raw SignedContext blob (a secret-bearing string);
 *   - the verified-claims map (would leak `cfg` shape internals);
 *   - the Request object (no current convention for handing it down);
 *   - any service container / configuration accessors.
 *
 * Adding any of those is a separate slice — the action seam stays
 * minimal here.
 */
final readonly class UiFormSubmitActionContext
{
    /**
     * @param array<string, scalar|null>          $values
     * @param list<UiFormSubmitFieldDefinition>   $fields
     */
    public function __construct(
        public string $formInstanceId,
        public string $actionName,
        public string $dispatchId,
        public array $values,
        public array $fields,
        public UiFormSubmitResult $submitResult,
        public ?string $subscriberChannelId = null,
    ) {}
}

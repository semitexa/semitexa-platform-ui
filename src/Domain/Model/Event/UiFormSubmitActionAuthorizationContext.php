<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Read-only context passed to UiFormSubmitActionAuthorizerInterface.
 *
 * Surfaced state:
 *
 *   - `formInstanceId`       : the FormComponent render-time instance id.
 *   - `actionName`           : the server-signed action name from cfg.a.
 *   - `dispatchId`           : per-attempt id (replay-protection scope).
 *   - `values`               : sanitised `payload.form.values` snapshot
 *                              (safe-identifier keys, scalar / null,
 *                              bounded). Still client-controlled —
 *                              never authoritative for authorization.
 *   - `fields`               : signed UiFormSubmitFieldDefinition list.
 *   - `submitResult`         : validation summary (always `valid` by
 *                              the time authorize() is called — the
 *                              component never runs authz on invalid
 *                              forms).
 *   - `submitActionContext`  : the same UiFormSubmitActionContext the
 *                              action will receive if authz + policy
 *                              pass; carried here so authorizers can
 *                              inspect/forward without rebuilding it.
 *
 * Deliberately omitted (same boundary as UiFormSubmitActionContext):
 *
 *   - the raw SignedContext blob;
 *   - the Request object;
 *   - container services;
 *   - secrets / tokens.
 *
 * Adding request metadata (session id, auth identity, IP) is a
 * separate slice once Semitexa lands a stable convention for
 * passing it into UI handlers.
 */
final readonly class UiFormSubmitActionAuthorizationContext
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
        public UiFormSubmitActionContext $submitActionContext,
    ) {}
}

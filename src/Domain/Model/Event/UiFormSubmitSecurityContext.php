<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Read-only context passed to UiFormSubmitSecurityPolicyInterface.
 *
 * The security/CSRF policy seam runs AFTER the action authorizer
 * and BEFORE the action itself. The context is intentionally
 * narrower than the action-authorization context because a CSRF /
 * session check should not need to inspect submitted values:
 *
 *   - `formInstanceId`  : the form's render-time instance id.
 *   - `actionName`      : the signed action name.
 *   - `dispatchId`      : per-attempt id (already replay-checked).
 *   - `fields`          : signed UiFormSubmitFieldDefinition list.
 *   - `submitResult`    : validation summary (always `valid`).
 *   - `securityConfig`  : the verified `cfg.s` map (server-signed
 *                         security claims; default-empty for
 *                         backwards compatibility with submit
 *                         contexts rendered before the CSRF slice).
 *
 * Notable omissions:
 *
 *   - `values` is intentionally NOT included — a policy that needs
 *     them is overstepping its concern. CSRF tokens come from
 *     `securityConfig`, not from `payload.form.values`.
 *   - No raw SignedContext blob, no Request object, no secrets.
 */
final readonly class UiFormSubmitSecurityContext
{
    /**
     * @param list<UiFormSubmitFieldDefinition>  $fields
     * @param array<string, mixed>               $securityConfig
     */
    public function __construct(
        public string $formInstanceId,
        public string $actionName,
        public string $dispatchId,
        public array $fields,
        public UiFormSubmitResult $submitResult,
        public array $securityConfig = [],
    ) {}
}

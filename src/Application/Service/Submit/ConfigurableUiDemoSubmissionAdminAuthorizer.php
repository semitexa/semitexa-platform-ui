<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\PlatformUi\Domain\Exception\UiDemoSubmissionAdminAuthorizationException;

/**
 * Default built-in `UiDemoSubmissionAdminAuthorizerInterface` —
 * deny-by-default protected mode for the dev-facing diagnostic listing.
 *
 * Allows access **only** when an explicit environment flag is set:
 *
 *   PLATFORM_UI_DEMO_ADMIN_ENABLED=1     (or true / yes / on / enabled)
 *
 * Anything else — flag missing, empty, `0`, `false`, `off`, random
 * string — denies with `UiDemoSubmissionAdminAuthorizationException`
 * (reason `demo_admin_disabled`). The denial message is operator-
 * safe and never echoes the bad value, the flag name's source, or
 * any class FQCN.
 *
 * Marked `#[SatisfiesServiceContract]` on purpose: the package must
 * not expose submission diagnostics unless the operator explicitly
 * enables the route for a dev environment. Apps can still replace it
 * by either:
 *
 *   - binding their own implementation that wraps / replaces it via
 *     `#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]`
 *     in a module that "extends" semitexa-platform-ui;
 *
 *   - or calling
 *     `UiDemoSubmissionAdminAuthorizer::setActive(new AllowAllUiDemoSubmissionAdminAuthorizer())`
 *     from their own boot listener.
 *
 * Reads the flag exactly once per `authorize()` call via the same
 * `Environment::getEnvValue()` helper the rest of the package uses
 * (no per-instance caching — the dispatcher creates a fresh handler
 * per request, and an env change between requests should take
 * effect immediately).
 *
 * Trust perimeter:
 *
 *   - Pure decision function. No state, no logging of user content,
 *     no persistence.
 *   - Errors never include the env value or the flag name beyond the
 *     stable `demo_admin_disabled` reason code — the operator
 *     diagnostic surface stays minimal.
 */
#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]
final class ConfigurableUiDemoSubmissionAdminAuthorizer implements UiDemoSubmissionAdminAuthorizerInterface
{
    /** Environment key that gates the listing. */
    public const ENV_FLAG = 'PLATFORM_UI_DEMO_ADMIN_ENABLED';

    /**
     * Whitelist of truthy strings. Compared case-insensitively after
     * trimming. Anything outside the list — including the empty
     * string — denies.
     *
     * @var array<string, true>
     */
    private const TRUTHY_VALUES = [
        '1'        => true,
        'true'     => true,
        'yes'      => true,
        'on'       => true,
        'enabled'  => true,
    ];

    public function authorize(): void
    {
        if (self::isEnabled()) {
            return;
        }
        throw new UiDemoSubmissionAdminAuthorizationException(
            // Safe user-facing copy. Never includes the env flag
            // name, the bad value, or anything operator-internal.
            message: 'Diagnostic listing access is disabled. An operator must enable it explicitly.',
            reasonCode: 'demo_admin_disabled',
        );
    }

    /**
     * Static helper — same evaluation logic as authorize() without
     * the throw. Tests use it to assert the env-flag matrix, and the
     * docs reference it from the operator-facing copy.
     */
    public static function isEnabled(): bool
    {
        $raw = Environment::getEnvValue(self::ENV_FLAG, '');
        if (!is_string($raw)) {
            return false;
        }
        $normalised = strtolower(trim($raw));
        return $normalised !== '' && isset(self::TRUTHY_VALUES[$normalised]);
    }
}

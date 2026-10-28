<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit\Auth;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Platform\User\Domain\Enum\PasswordResetOutcome;
use Semitexa\Platform\User\Application\Service\PasswordResetService;

/**
 * The ready action behind `platform.reset-password`: the token rides the
 * form's signed props, the new password its fields. After a reset it sends the
 * browser to the component's `signInHref`, when one is given.
 *
 *     {{ component('platform.reset-password', {submitAction: 'platform.auth.reset-password', token: token, signInHref: '/sign-in'}) }}
 */
#[AsService]
#[ExecutionScoped]
#[AsFormSubmitAction(self::NAME)]
final class ResetPasswordAction implements UiFormSubmitActionInterface
{
    public const NAME = 'platform.auth.reset-password';

    #[InjectAsReadonly]
    protected PasswordResetService $resets;

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        $password = (string) ($context->values['password'] ?? '');
        if ($password !== (string) ($context->values['password_confirmation'] ?? $password)) {
            return UiFormSubmitActionResult::rejected('The two passwords do not match.')
                ->withFieldErrors(['password_confirmation' => 'The two passwords do not match.']);
        }

        $result = $this->resets->reset((string) ($context->props['token'] ?? ''), $password);

        return match ($result->outcome) {
            PasswordResetOutcome::Reset => self::done((string) ($context->props['signInHref'] ?? '')),
            PasswordResetOutcome::PasswordRejected => UiFormSubmitActionResult::rejected('Choose another password.')
                ->withFieldErrors(['password' => (string) $result->passwordProblem]),
            PasswordResetOutcome::InvalidToken => UiFormSubmitActionResult::rejected('This link has expired or was already used. Ask for a new one.'),
        };
    }

    private static function done(string $signInHref): UiFormSubmitActionResult
    {
        $result = UiFormSubmitActionResult::accepted('Password changed. Sign in with the new one.');

        // Only a path on this site, judged as the response validator judges it
        // (`/\evil.com`, `//host`, whitespace): a target it refused after the
        // password had changed showed the user an error for a reset that worked.
        return UiResponsePatch::isSameOriginPath($signInHref)
            ? $result->redirectingTo($signInHref)
            : $result;
    }
}

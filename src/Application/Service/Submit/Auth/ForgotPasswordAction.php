<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit\Auth;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\Platform\User\Application\Service\PasswordResetService;

/**
 * The ready action behind `platform.forgot-password`: a reset link by mail
 * for a semitexa/platform-user account. It answers the same whether or not
 * the address has an account, and limits one client to five requests in
 * fifteen minutes (the service limits mail to one inbox).
 *
 *     {{ component('platform.forgot-password', {submitAction: 'platform.auth.forgot-password'}) }}
 */
#[AsService]
#[ExecutionScoped]
#[AsFormSubmitAction(self::NAME)]
final class ForgotPasswordAction implements UiFormSubmitActionInterface
{
    public const NAME = 'platform.auth.forgot-password';

    #[InjectAsReadonly]
    protected PasswordResetService $resets;

    #[InjectAsMutable]
    protected RateLimiter $limiter;

    #[InjectAsReadonly(optional: true)]
    protected TenantContextStoreInterface $tenants;

    /** The shortest answer, known address or not, in milliseconds. */
    #[Config(env: 'PLATFORM_USER_RESET_ANSWER_MS', default: 1500)]
    protected int $minAnswerMs;

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        if (!$this->limiter->attempt('platform-password-reset', 5, 900)->allowed) {
            return UiFormSubmitActionResult::rejected('Too many requests. Try again later.');
        }

        // The current site's tenant; null on a single-site install, where an
        // address registered on two sites gets no link.
        $tenantId = TenantContextAccess::tenantId(isset($this->tenants) ? $this->tenants->tryGet() : null);
        $started = microtime(true);
        try {
            $this->resets->request(trim((string) ($context->values['email'] ?? '')), $tenantId);
        } catch (\LogicException) {
            // No mailer configured: the same refusal for every address.
            return UiFormSubmitActionResult::rejected('Password reset is not available on this site.');
        }
        // A real account costs a token and a mail, an unknown address nothing:
        // the answer time would tell which addresses exist. Every answer takes
        // at least this long.
        $wait = ($this->minAnswerMs ?? 1500) / 1000 - (microtime(true) - $started);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        return UiFormSubmitActionResult::accepted('If that address has an account, a link is on its way.')->resettingForm();
    }
}

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
use Semitexa\Core\Lifecycle\CarriedContext;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\InFlightRequests;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

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
        $email = trim((string) ($context->values['email'] ?? ''));
        // A real account costs a token and a mail, an unknown address nothing.
        // A minimum wait was not enough: a mailer slower than it (mail sent
        // inline) still told which addresses exist. Inside the server the
        // request runs in a coroutine of its own and the answer goes at the
        // deadline whether it has finished or not; it finishes regardless.
        $deadline = max(0.0, ($this->minAnswerMs ?? 1500) / 1000);
        $started = microtime(true);
        $outcome = self::within($deadline, fn () => $this->resets->request($email, $tenantId));
        if ($outcome instanceof \LogicException) {
            // No mailer configured: the same refusal for every address.
            return UiFormSubmitActionResult::rejected('Password reset is not available on this site.');
        }
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }
        $wait = $deadline - (microtime(true) - $started);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        return UiFormSubmitActionResult::accepted('If that address has an account, a link is on its way.')->resettingForm();
    }

    /**
     * Runs $work and answers what it threw, or null: once it is done, or once
     * $seconds have passed inside the server, when it goes on in its own
     * coroutine with the request's context and a later failure is logged.
     */
    private static function within(float $seconds, \Closure $work): ?\Throwable
    {
        if ($seconds <= 0.0 || !class_exists(Coroutine::class, false) || Coroutine::getCid() < 0) {
            try {
                $work();

                return null;
            } catch (\Throwable $e) {
                return $e;
            }
        }

        $done = new Channel(1);
        $context = CarriedContext::capture();
        Coroutine::create(static function () use ($done, $context, $work): void {
            // Counted as the request it finishes for: a worker's drain spares
            // a request's coroutines for its grace and cancelled this one at
            // once, after the visitor was told a link was on its way.
            $cid = Coroutine::getCid();
            InFlightRequests::enter($cid);
            try {
                CarriedContext::runWith($context, $work);
                $done->push(true);
            } catch (\Throwable $e) {
                if (!$e instanceof \LogicException) {
                    StaticLoggerBridge::error('auth', 'Password reset request failed: ' . $e->getMessage(), ['exception' => $e::class]);
                }
                $done->push($e);
            } finally {
                InFlightRequests::leave($cid);
            }
        });
        $result = $done->pop($seconds);

        return $result instanceof \Throwable ? $result : null;
    }
}

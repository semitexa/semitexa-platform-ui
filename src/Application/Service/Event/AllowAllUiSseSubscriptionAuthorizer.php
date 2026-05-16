<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Core\Attribute\SatisfiesServiceContract;

/**
 * Default SSE subscription authorizer — allows every verified token.
 *
 * Keeps the playground demo working without per-app wiring. Apps that
 * need to gate subscriptions by user/tenant/role bind their own
 * implementation in a module that "extends" semitexa-platform-ui;
 * SatisfiesServiceContract's module-order winner picks the descendant.
 */
#[SatisfiesServiceContract(of: UiSseSubscriptionAuthorizerInterface::class)]
final class AllowAllUiSseSubscriptionAuthorizer implements UiSseSubscriptionAuthorizerInterface
{
    public function authorize(UiSseSubscriptionContext $context): bool
    {
        return true;
    }
}

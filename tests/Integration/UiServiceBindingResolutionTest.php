<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ServiceContractRegistry;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiInteractionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiSseSubscriptionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\CacheBackedUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\RedisUiSseConnectionLimiter;
use Semitexa\PlatformUi\Application\Service\Event\RedisUiSsePatchQueue;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiReplayStoreInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiSseConnectionLimiterInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiSsePatchQueue;
use Semitexa\PlatformUi\Application\Service\Event\UiSseSubscriptionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;

/**
 * Verifies that Platform UI's two new service contracts resolve to the
 * documented default implementations via Semitexa's
 * SatisfiesServiceContract discovery.
 *
 * These tests do NOT bootstrap the application container — they call
 * the contract registry directly, the same way `bin/semitexa
 * contracts:list` does. That's enough to prove:
 *
 *  - the SatisfiesServiceContract attribute is present and well-formed;
 *  - discovery picks up the class from the platform-ui module;
 *  - the active winner is the documented default.
 *
 * If a future Semitexa version changes the registry contract, this is
 * the test that fails loudly so we can adapt.
 */
final class UiServiceBindingResolutionTest extends TestCase
{
    private static ServiceContractRegistry $registry;

    public static function setUpBeforeClass(): void
    {
        self::$registry = new ServiceContractRegistry();
    }

    #[Test]
    public function replay_store_interface_resolves_to_cache_backed_default(): void
    {
        $contracts = self::$registry->getContracts();
        self::assertArrayHasKey(
            UiReplayStoreInterface::class,
            $contracts,
            'UiReplayStoreInterface must have a default binding registered '
            . 'via SatisfiesServiceContract.',
        );
        self::assertSame(
            CacheBackedUiReplayStore::class,
            $contracts[UiReplayStoreInterface::class],
            'CacheBackedUiReplayStore must be the default implementation '
            . 'of UiReplayStoreInterface.',
        );
    }

    #[Test]
    public function authorizer_interface_resolves_to_allow_all_default(): void
    {
        $contracts = self::$registry->getContracts();
        self::assertArrayHasKey(
            UiInteractionAuthorizerInterface::class,
            $contracts,
            'UiInteractionAuthorizerInterface must have a default binding '
            . 'registered via SatisfiesServiceContract.',
        );
        self::assertSame(
            AllowAllUiInteractionAuthorizer::class,
            $contracts[UiInteractionAuthorizerInterface::class],
            'AllowAllUiInteractionAuthorizer must be the default '
            . 'implementation of UiInteractionAuthorizerInterface.',
        );
    }

    #[Test]
    public function replay_store_binding_is_advertised_from_platform_ui_module(): void
    {
        $details = self::$registry->getContractDetails();
        self::assertArrayHasKey(UiReplayStoreInterface::class, $details);

        $impls = $details[UiReplayStoreInterface::class]['implementations'];
        $modules = array_column($impls, 'module');
        self::assertContains(
            'semitexa-platform-ui',
            $modules,
            'semitexa-platform-ui module must register a UiReplayStoreInterface implementation.',
        );
    }

    #[Test]
    public function authorizer_binding_is_advertised_from_platform_ui_module(): void
    {
        $details = self::$registry->getContractDetails();
        self::assertArrayHasKey(UiInteractionAuthorizerInterface::class, $details);

        $impls = $details[UiInteractionAuthorizerInterface::class]['implementations'];
        $modules = array_column($impls, 'module');
        self::assertContains(
            'semitexa-platform-ui',
            $modules,
            'semitexa-platform-ui module must register a UiInteractionAuthorizerInterface implementation.',
        );
    }

    #[Test]
    public function sse_patch_queue_resolves_to_redis_default(): void
    {
        $contracts = self::$registry->getContracts();
        self::assertArrayHasKey(
            UiSsePatchQueue::class,
            $contracts,
            'UiSsePatchQueue must have a default binding registered '
            . 'via SatisfiesServiceContract.',
        );
        self::assertSame(
            RedisUiSsePatchQueue::class,
            $contracts[UiSsePatchQueue::class],
            'RedisUiSsePatchQueue must be the default UiSsePatchQueue impl.',
        );
    }

    #[Test]
    public function sse_subscription_authorizer_resolves_to_allow_all_default(): void
    {
        $contracts = self::$registry->getContracts();
        self::assertArrayHasKey(
            UiSseSubscriptionAuthorizerInterface::class,
            $contracts,
            'UiSseSubscriptionAuthorizerInterface must have a default '
            . 'binding registered via SatisfiesServiceContract.',
        );
        self::assertSame(
            AllowAllUiSseSubscriptionAuthorizer::class,
            $contracts[UiSseSubscriptionAuthorizerInterface::class],
        );
    }

    #[Test]
    public function sse_connection_limiter_resolves_to_redis_default(): void
    {
        $contracts = self::$registry->getContracts();
        self::assertArrayHasKey(
            UiSseConnectionLimiterInterface::class,
            $contracts,
            'UiSseConnectionLimiterInterface must have a default binding '
            . 'registered via SatisfiesServiceContract.',
        );
        self::assertSame(
            RedisUiSseConnectionLimiter::class,
            $contracts[UiSseConnectionLimiterInterface::class],
        );
    }

    #[Test]
    public function field_rule_registry_resolves_to_default_built_ins(): void
    {
        $contracts = self::$registry->getContracts();
        self::assertArrayHasKey(
            UiFieldRuleRegistryInterface::class,
            $contracts,
            'UiFieldRuleRegistryInterface must have a default binding '
            . 'registered via SatisfiesServiceContract.',
        );
        self::assertSame(
            DefaultUiFieldRuleRegistry::class,
            $contracts[UiFieldRuleRegistryInterface::class],
            'DefaultUiFieldRuleRegistry must be the default rule registry impl.',
        );
    }
}

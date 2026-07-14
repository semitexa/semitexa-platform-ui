<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Exception\BehaviorRegistryException;
use Semitexa\PlatformUi\Domain\Model\Behavior\BehaviorMetadata;

/**
 * Registry of #[AsUiBehavior]-marked classes — the third UI tier.
 *
 * Identity model (mirrors UiPrimitiveRegistry):
 *   - canonical $name  (e.g. "platform.dropdown") — used everywhere internally.
 *   - $ui alias        (e.g. "dropdown")          — the value in `ui-behavior="…"`.
 *
 * Both identities must be unique. Seeded by ClassDiscovery at worker boot
 * (BootPlatformUiRegistryListener); manually seedable for tests via register().
 */
final class UiBehaviorRegistry
{
    /** @var array<string, BehaviorMetadata> */
    private static array $byName = [];

    /** @var array<string, string> */
    private static array $byUi = [];

    private static bool $initialized = false;
    private static ?ClassDiscovery $classDiscovery = null;
    private static ?UiBehaviorMetadataFactory $factory = null;

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::$classDiscovery = $classDiscovery;
    }

    public static function setFactory(UiBehaviorMetadataFactory $factory): void
    {
        self::$factory = $factory;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        // No ClassDiscovery wired? Honour any register()ed entries but skip
        // attribute discovery (test/dev path).
        if (self::$classDiscovery === null) {
            self::$initialized = true;
            return;
        }

        $factory = self::$factory ?? new UiBehaviorMetadataFactory();
        $classes = self::$classDiscovery->findClassesWithAttribute(AsUiBehavior::class);

        foreach ($classes as $class) {
            self::registerInternal($factory->fromClass($class));
        }

        self::$initialized = true;
    }

    /**
     * Register a metadata entry manually (test helper / non-discovery flows).
     */
    public static function register(BehaviorMetadata $metadata): void
    {
        self::registerInternal($metadata);
    }

    private static function registerInternal(BehaviorMetadata $metadata): void
    {
        if (isset(self::$byName[$metadata->name])) {
            $existing = self::$byName[$metadata->name];
            if ($existing->class === $metadata->class) {
                return;
            }

            throw new BehaviorRegistryException(sprintf(
                'Duplicate UI behavior name "%s" — declared by %s and %s.',
                $metadata->name,
                $existing->class,
                $metadata->class,
            ));
        }

        if (isset(self::$byUi[$metadata->ui])) {
            $owner = self::$byUi[$metadata->ui];
            throw new BehaviorRegistryException(sprintf(
                'Duplicate UI behavior alias "%s" — already used by "%s" (declared by %s), conflict comes from %s.',
                $metadata->ui,
                $owner,
                self::$byName[$owner]->class,
                $metadata->class,
            ));
        }

        self::$byName[$metadata->name] = $metadata;
        self::$byUi[$metadata->ui] = $metadata->name;
    }

    public static function get(string $nameOrAlias): ?BehaviorMetadata
    {
        self::initialize();

        if (isset(self::$byName[$nameOrAlias])) {
            return self::$byName[$nameOrAlias];
        }

        if (isset(self::$byUi[$nameOrAlias])) {
            return self::$byName[self::$byUi[$nameOrAlias]];
        }

        return null;
    }

    public static function getByName(string $name): ?BehaviorMetadata
    {
        self::initialize();

        return self::$byName[$name] ?? null;
    }

    public static function getByUi(string $ui): ?BehaviorMetadata
    {
        self::initialize();

        $name = self::$byUi[$ui] ?? null;

        return $name !== null ? self::$byName[$name] : null;
    }

    public static function has(string $nameOrAlias): bool
    {
        return self::get($nameOrAlias) !== null;
    }

    /**
     * @return list<BehaviorMetadata>
     */
    public static function all(): array
    {
        self::initialize();

        return array_values(self::$byName);
    }

    /**
     * Reset (test helper).
     */
    public static function reset(): void
    {
        self::$byName = [];
        self::$byUi = [];
        self::$initialized = false;
    }
}

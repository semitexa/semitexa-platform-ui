<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;

/**
 * Registry of #[AsUiPrimitive]-marked classes.
 *
 * Identity model:
 *   - canonical $name  (e.g. "platform.button")  — used everywhere internally.
 *   - $ui alias        (e.g. "button")           — CSS/markup hook only.
 *
 * Both identities must be unique within the registry.
 *
 * Mirrors the static/lazy initialization pattern of Semitexa\Ssr ComponentRegistry
 * so the same boot-time wiring fits both registries.
 */
final class UiPrimitiveRegistry
{
    /** @var array<string, PrimitiveMetadata> */
    private static array $byName = [];

    /** @var array<string, string> */
    private static array $byUi = [];

    private static bool $initialized = false;
    private static ?ClassDiscovery $classDiscovery = null;
    private static ?UiPrimitiveMetadataFactory $factory = null;

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::$classDiscovery = $classDiscovery;
    }

    public static function setFactory(UiPrimitiveMetadataFactory $factory): void
    {
        self::$factory = $factory;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        // No ClassDiscovery wired? Treat the registry as manually-seeded:
        // we still honour any entries provided via register() but skip
        // attribute discovery. This is the test/dev path and is also the
        // current quarantined production state — the registry is not yet
        // bootstrapped by SSR's lifecycle listener. Production wiring lands
        // in the explicit Platform UI runtime step.
        if (self::$classDiscovery === null) {
            self::$initialized = true;
            return;
        }

        $factory = self::$factory ?? new UiPrimitiveMetadataFactory();
        $classes = self::$classDiscovery->findClassesWithAttribute(AsUiPrimitive::class);

        foreach ($classes as $class) {
            self::registerInternal($factory->fromClass($class));
        }

        self::$initialized = true;
    }

    /**
     * Register a metadata entry manually (test helper / non-discovery flows).
     */
    public static function register(PrimitiveMetadata $metadata): void
    {
        self::registerInternal($metadata);
    }

    private static function registerInternal(PrimitiveMetadata $metadata): void
    {
        if (isset(self::$byName[$metadata->name])) {
            $existing = self::$byName[$metadata->name];
            if ($existing->class === $metadata->class) {
                return;
            }

            throw new PrimitiveRegistryException(sprintf(
                'Duplicate UI primitive name "%s" — declared by %s and %s.',
                $metadata->name,
                $existing->class,
                $metadata->class,
            ));
        }

        if (isset(self::$byUi[$metadata->ui])) {
            $owner = self::$byUi[$metadata->ui];
            throw new PrimitiveRegistryException(sprintf(
                'Duplicate UI primitive alias "%s" — already used by "%s" (declared by %s), conflict comes from %s.',
                $metadata->ui,
                $owner,
                self::$byName[$owner]->class,
                $metadata->class,
            ));
        }

        self::$byName[$metadata->name] = $metadata;
        self::$byUi[$metadata->ui] = $metadata->name;
    }

    public static function get(string $nameOrAlias): ?PrimitiveMetadata
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

    public static function getByName(string $name): ?PrimitiveMetadata
    {
        self::initialize();

        return self::$byName[$name] ?? null;
    }

    public static function getByUi(string $ui): ?PrimitiveMetadata
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
     * @return list<PrimitiveMetadata>
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

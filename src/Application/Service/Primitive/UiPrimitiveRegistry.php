<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;

/**
 * Static entry point for the Platform UI primitive catalog.
 *
 * One wired slot and no logic — discovery, indexing and lookup live in
 * {@see UiPrimitiveCatalog}, which container-managed callers inject directly.
 * Retained while the Twig extension, the CLI catalog command and the module
 * test bootstraps still reach for the class-level API.
 *
 * De-staticised by ep-kill-static-facades. Not documented public API, so this is
 * a full deletion candidate once the last static caller is migrated.
 */
final class UiPrimitiveRegistry
{
    private static ?UiPrimitiveCatalog $catalog = null;

    public static function setCatalog(UiPrimitiveCatalog $catalog): void
    {
        self::$catalog = $catalog;
    }

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::catalog()->setClassDiscovery($classDiscovery);
    }

    public static function setFactory(UiPrimitiveMetadataFactory $factory): void
    {
        self::catalog()->setFactory($factory);
    }

    public static function initialize(): void
    {
        self::catalog()->initialize();
    }

    public static function register(PrimitiveMetadata $metadata): void
    {
        self::catalog()->register($metadata);
    }

    public static function get(string $nameOrAlias): ?PrimitiveMetadata
    {
        return self::catalog()->get($nameOrAlias);
    }

    public static function getByName(string $name): ?PrimitiveMetadata
    {
        return self::catalog()->getByName($name);
    }

    public static function getByUi(string $ui): ?PrimitiveMetadata
    {
        return self::catalog()->getByUi($ui);
    }

    public static function has(string $nameOrAlias): bool
    {
        return self::catalog()->has($nameOrAlias);
    }

    /** @return list<PrimitiveMetadata> */
    public static function all(): array
    {
        return self::catalog()->all();
    }

    /**
     * Drop every registration AND the catalog itself.
     *
     * Replacing the catalog rather than only clearing it also discards whatever
     * collaborators were wired into it, so one test cannot inherit the previous
     * one's discovery.
     */
    public static function reset(): void
    {
        // Clear the catalog's state rather than dropping the catalog itself. The
        // container wires this slot once, at worker start; replacing it with a
        // self-created instance would leave discovery unwired for the rest of the
        // worker's life, and an unwired catalog reads empty without erroring.
        self::$catalog?->reset();
    }

    private static function catalog(): UiPrimitiveCatalog
    {
        return self::$catalog ??= new UiPrimitiveCatalog();
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;

/**
 * Static entry point for the Platform UI component catalog.
 *
 * Holds one wired slot and no logic — discovery, validation and the binding
 * rules live in {@see UiComponentCatalog}, which container-managed callers
 * inject directly. Retained while the Twig extension, the interaction
 * dispatcher and the module test bootstraps still reach for the class-level API.
 *
 * De-staticised by ep-kill-static-facades. Not documented public API, so this is
 * a full deletion candidate once the last static caller is migrated.
 */
final class UiComponentRegistry
{
    private static ?UiComponentCatalog $catalog = null;

    public static function setCatalog(UiComponentCatalog $catalog): void
    {
        self::$catalog = $catalog;
    }

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::catalog()->setClassDiscovery($classDiscovery);
    }

    public static function setFactory(UiComponentMetadataFactory $factory): void
    {
        self::catalog()->setFactory($factory);
    }

    public static function initialize(): void
    {
        self::catalog()->initialize();
    }

    public static function register(UiComponentMetadata $metadata): void
    {
        self::catalog()->register($metadata);
    }

    public static function get(string $name): ?UiComponentMetadata
    {
        return self::catalog()->get($name);
    }

    public static function has(string $name): bool
    {
        return self::catalog()->has($name);
    }

    /** @return list<UiComponentMetadata> */
    public static function all(): array
    {
        return self::catalog()->all();
    }

    public static function getBinding(
        string $componentName,
        string $partName,
        string $eventName,
    ): ?UiOnMetadata {
        return self::catalog()->getBinding($componentName, $partName, $eventName);
    }

    /** @return list<UiOnMetadata> */
    public static function bindingsFor(string $componentName): array
    {
        return self::catalog()->bindingsFor($componentName);
    }

    public static function registerExternal(UiExternalHandlerMetadata $metadata): void
    {
        self::catalog()->registerExternal($metadata);
    }

    public static function getExternalBinding(
        string $componentName,
        string $partName,
        string $eventName,
    ): ?UiExternalHandlerMetadata {
        return self::catalog()->getExternalBinding($componentName, $partName, $eventName);
    }

    /** @return list<UiExternalHandlerMetadata> */
    public static function externalBindingsFor(string $componentName): array
    {
        return self::catalog()->externalBindingsFor($componentName);
    }

    /**
     * @param class-string $handlerClass
     */
    public static function registerExternalFromClass(string $handlerClass): void
    {
        self::catalog()->registerExternalFromClass($handlerClass);
    }

    /**
     * Drop every registration AND the catalog itself.
     *
     * Tests call this between cases. Replacing the catalog rather than only
     * clearing it also discards whatever collaborators were wired into it, so a
     * test cannot inherit the previous one's discovery — the failure mode
     * described in the framework's static-registry-reset note, where a reset in
     * one test empties state for the whole process.
     */
    public static function reset(): void
    {
        self::$catalog = null;
    }

    private static function catalog(): UiComponentCatalog
    {
        return self::$catalog ??= new UiComponentCatalog();
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;

/**
 * Registry of Platform UI components — classes that combine #[AsComponent]
 * with one or more #[UiPart] / #[UiSlot] declarations.
 *
 * Rendering still flows through SSR's ComponentRegistry / ComponentRenderer
 * (every Platform UI component is also a regular AsComponent). This registry
 * only exposes the composition layer for introspection and tests.
 *
 * Mirrors the static-with-explicit-DI shape of UiPrimitiveRegistry so the
 * boot listener can wire both registries together.
 */
final class UiComponentRegistry
{
    /** @var array<string, UiComponentMetadata> */
    private static array $byName = [];

    private static bool $initialized = false;
    private static ?ClassDiscovery $classDiscovery = null;
    private static ?UiComponentMetadataFactory $factory = null;

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::$classDiscovery = $classDiscovery;
    }

    public static function setFactory(UiComponentMetadataFactory $factory): void
    {
        self::$factory = $factory;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        if (self::$classDiscovery === null) {
            self::$initialized = true;
            return;
        }

        $factory = self::$factory ?? new UiComponentMetadataFactory();

        // A Platform UI component is any class that declares at least one
        // #[UiPart] or #[UiSlot]. Components without parts/slots remain
        // plain SSR components and stay out of this registry.
        $candidates = array_unique(array_merge(
            self::$classDiscovery->findClassesWithAttribute(UiPart::class),
            self::$classDiscovery->findClassesWithAttribute(UiSlot::class),
        ));

        foreach ($candidates as $class) {
            self::registerInternal($factory->fromClass($class));
        }

        self::$initialized = true;
    }

    public static function register(UiComponentMetadata $metadata): void
    {
        self::registerInternal($metadata);
    }

    private static function registerInternal(UiComponentMetadata $metadata): void
    {
        if (isset(self::$byName[$metadata->name])) {
            $existing = self::$byName[$metadata->name];
            if ($existing->class === $metadata->class) {
                return;
            }
            throw new UiComponentRegistryException(sprintf(
                'Duplicate UI component name "%s" — declared by %s and %s.',
                $metadata->name,
                $existing->class,
                $metadata->class,
            ));
        }
        self::$byName[$metadata->name] = $metadata;
    }

    public static function get(string $name): ?UiComponentMetadata
    {
        self::initialize();
        return self::$byName[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return self::get($name) !== null;
    }

    /** @return list<UiComponentMetadata> */
    public static function all(): array
    {
        self::initialize();
        return array_values(self::$byName);
    }

    public static function reset(): void
    {
        self::$byName = [];
        self::$initialized = false;
    }
}

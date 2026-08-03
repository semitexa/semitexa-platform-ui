<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;

/**
 * The Platform UI primitive catalog: discovery, name/ui indexing and lookup.
 *
 * Container-managed, so a consumer injects this and gets a wired catalog.
 * {@see UiPrimitiveRegistry} remains a static shim over it for callers that cannot
 * receive an injection.
 *
 * Extracted by ep-kill-static-facades, which counted nine facades; this is the
 * eleventh/twelfth. Like its siblings it carried TWO wired slots plus an
 * initialize(), and that three-call incantation was written out verbatim in both
 * BootPlatformUiRegistryListener and CatalogCommand — miss one call and the
 * catalog is silently half-built.
 */
#[AsService]
final class UiPrimitiveCatalog
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /**
     * Not injected: UiPrimitiveMetadataFactory has no container binding — every
     * caller has always built it with new. Settable, with initialize() falling
     * back to constructing one.
     */
    protected UiPrimitiveMetadataFactory $factory;

    /** @var array<string, PrimitiveMetadata> */
    private array $byName = [];

    /** @var array<string, string> */
    private array $byUi = [];

    private bool $initialized = false;

    public function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        $this->classDiscovery = $classDiscovery;
    }

    public function setFactory(UiPrimitiveMetadataFactory $factory): void
    {
        $this->factory = $factory;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // No ClassDiscovery wired yet? Honour any register()ed entries but skip
        // attribute discovery — WITHOUT latching $initialized, so a later
        // initialize() after the boot listener wires ClassDiscovery can still
        // run the discovery pass. Latching here is a silent failure: every read
        // calls initialize() lazily, so one early query would freeze the catalog
        // empty for the worker's whole life, and empty raises no error.
        if (!isset($this->classDiscovery)) {
            return;
        }

        $factory = $this->factory ?? new UiPrimitiveMetadataFactory();
        $classes = $this->classDiscovery->findClassesWithAttribute(AsUiPrimitive::class);

        foreach ($classes as $class) {
            $this->registerInternal($factory->fromClass($class));
        }

        $this->initialized = true;
    }

    /**
     * Register a metadata entry manually (test helper / non-discovery flows).
     */
    public function register(PrimitiveMetadata $metadata): void
    {
        $this->registerInternal($metadata);
    }

    private function registerInternal(PrimitiveMetadata $metadata): void
    {
        if (isset($this->byName[$metadata->name])) {
            $existing = $this->byName[$metadata->name];
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

        if (isset($this->byUi[$metadata->ui])) {
            $owner = $this->byUi[$metadata->ui];
            throw new PrimitiveRegistryException(sprintf(
                'Duplicate UI primitive alias "%s" — already used by "%s" (declared by %s), conflict comes from %s.',
                $metadata->ui,
                $owner,
                $this->byName[$owner]->class,
                $metadata->class,
            ));
        }

        $this->byName[$metadata->name] = $metadata;
        $this->byUi[$metadata->ui] = $metadata->name;
    }

    public function get(string $nameOrAlias): ?PrimitiveMetadata
    {
        $this->initialize();

        if (isset($this->byName[$nameOrAlias])) {
            return $this->byName[$nameOrAlias];
        }

        if (isset($this->byUi[$nameOrAlias])) {
            return $this->byName[$this->byUi[$nameOrAlias]];
        }

        return null;
    }

    public function getByName(string $name): ?PrimitiveMetadata
    {
        $this->initialize();

        return $this->byName[$name] ?? null;
    }

    public function getByUi(string $ui): ?PrimitiveMetadata
    {
        $this->initialize();

        $name = $this->byUi[$ui] ?? null;

        return $name !== null ? $this->byName[$name] : null;
    }

    public function has(string $nameOrAlias): bool
    {
        return $this->get($nameOrAlias) !== null;
    }

    /**
     * @return list<PrimitiveMetadata>
     */
    public function all(): array
    {
        $this->initialize();

        return array_values($this->byName);
    }

    /**
     * Reset (test helper).
     */
    public function reset(): void
    {
        $this->byName = [];
        $this->byUi = [];
        $this->initialized = false;
    }
}

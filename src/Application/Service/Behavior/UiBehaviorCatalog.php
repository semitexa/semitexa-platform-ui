<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Exception\BehaviorRegistryException;
use Semitexa\PlatformUi\Domain\Model\Behavior\BehaviorMetadata;

/**
 * The Platform UI behavior catalog: discovery, name/ui indexing and lookup.
 *
 * Container-managed, so a consumer injects this and gets a wired catalog.
 * {@see UiBehaviorRegistry} remains a static shim over it for callers that cannot
 * receive an injection.
 *
 * Extracted by ep-kill-static-facades, which counted nine facades; this is the
 * eleventh/twelfth. Like its siblings it carried TWO wired slots plus an
 * initialize(), and that three-call incantation was written out verbatim in both
 * BootPlatformUiRegistryListener and CatalogCommand — miss one call and the
 * catalog is silently half-built.
 */
#[AsService]
final class UiBehaviorCatalog
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /**
     * Not injected: UiBehaviorMetadataFactory has no container binding — every
     * caller has always built it with new. Settable, with initialize() falling
     * back to constructing one.
     */
    protected UiBehaviorMetadataFactory $factory;

    /** @var array<string, BehaviorMetadata> */
    private array $byName = [];

    /** @var array<string, string> */
    private array $byUi = [];

    private bool $initialized = false;

    public function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        $this->classDiscovery = $classDiscovery;
    }

    public function setFactory(UiBehaviorMetadataFactory $factory): void
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
        // run the discovery pass (get()/has()/all() lazily call initialize()).
        if (!isset($this->classDiscovery)) {
            return;
        }

        $factory = $this->factory ?? new UiBehaviorMetadataFactory();
        $classes = $this->classDiscovery->findClassesWithAttribute(AsUiBehavior::class);

        foreach ($classes as $class) {
            $this->registerInternal($factory->fromClass($class));
        }

        $this->initialized = true;
    }

    /**
     * Register a metadata entry manually (test helper / non-discovery flows).
     */
    public function register(BehaviorMetadata $metadata): void
    {
        $this->registerInternal($metadata);
    }

    private function registerInternal(BehaviorMetadata $metadata): void
    {
        if (isset($this->byName[$metadata->name])) {
            $existing = $this->byName[$metadata->name];
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

        if (isset($this->byUi[$metadata->ui])) {
            $owner = $this->byUi[$metadata->ui];
            throw new BehaviorRegistryException(sprintf(
                'Duplicate UI behavior alias "%s" — already used by "%s" (declared by %s), conflict comes from %s.',
                $metadata->ui,
                $owner,
                $this->byName[$owner]->class,
                $metadata->class,
            ));
        }

        $this->byName[$metadata->name] = $metadata;
        $this->byUi[$metadata->ui] = $metadata->name;
    }

    public function get(string $nameOrAlias): ?BehaviorMetadata
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

    public function getByName(string $name): ?BehaviorMetadata
    {
        $this->initialize();

        return $this->byName[$name] ?? null;
    }

    public function getByUi(string $ui): ?BehaviorMetadata
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
     * @return list<BehaviorMetadata>
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
        // unset, not `= null`: these are non-nullable typed properties now, and
        // assigning null is a TypeError. Unsetting returns them to the
        // uninitialized state initialize() checks with isset().
        unset($this->classDiscovery, $this->factory);
    }
}

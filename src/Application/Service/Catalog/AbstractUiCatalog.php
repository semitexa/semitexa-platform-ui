<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Catalog;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Domain\Contract\UiCatalogEntryInterface;

/**
 * The shared half of the Platform UI catalogs: discovery-driven population,
 * name/ui indexing, duplicate detection and lookup.
 *
 * `tk-ui-registry-twin-catalogs`: UiPrimitiveCatalog and UiBehaviorCatalog were
 * 9/11 methods identical modulo the words Primitive/Behavior — 52 duplicated
 * statements whose only real differences were the attribute discovered, the
 * metadata factory, the exception type and the noun in its message. Those four
 * are the abstract hooks below; everything else lives here once. The metadata
 * models stay separate classes sharing only {@see UiCatalogEntryInterface} —
 * see that interface for why they are not given a common base.
 *
 * Children keep their own typed `register()` / `setFactory()` (parameter types
 * cannot narrow in an override, so the typed entry points belong to the child)
 * and their `@extends AbstractUiCatalog<T>` annotation gives every lookup back
 * its concrete metadata type.
 *
 * @template T of UiCatalogEntryInterface
 */
abstract class AbstractUiCatalog
{
    /**
     * `final` so a child cannot redeclare it with hooks — which is what lets
     * UiBehaviorCatalog::resetWiring() unset it back to the pre-boot state
     * without tripping `unset.possiblyHookedProperty`.
     */
    #[InjectAsReadonly]
    final protected ClassDiscovery $classDiscovery;

    /** @var array<string, T> */
    private array $byName = [];

    /** @var array<string, string> */
    private array $byUi = [];

    private bool $initialized = false;

    /** The attribute whose carriers populate this catalog. */
    abstract protected function attribute(): string;

    /** The noun used in duplicate-entry messages ("primitive", "behavior"). */
    abstract protected function kind(): string;

    /**
     * Build the metadata entry for a discovered class — the child applies its
     * settable factory (or constructs the default one).
     *
     * @return T
     */
    abstract protected function metadataFromClass(string $class): UiCatalogEntryInterface;

    /** The domain exception a duplicate raises. */
    abstract protected function duplicateException(string $message): \LogicException;

    public function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        $this->classDiscovery = $classDiscovery;
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

        foreach ($this->classDiscovery->findClassesWithAttribute($this->attribute()) as $class) {
            $this->registerInternal($this->metadataFromClass($class));
        }

        $this->initialized = true;
    }

    /**
     * @param T $metadata
     */
    protected function registerInternal(UiCatalogEntryInterface $metadata): void
    {
        // Cross-index collisions (a name equal to another entry's alias, in
        // either insertion order) would make get() and getByUi() disagree
        // about who owns the string — get() checks byName first. Reject them
        // outright. An entry whose OWN ui equals its name stays legal, and the
        // !== guards keep that case (and idempotent re-registration) flowing
        // into the byName check below instead of double-reporting here.
        if (
            isset($this->byUi[$metadata->name])
            && $this->byUi[$metadata->name] !== $metadata->name
        ) {
            $owner = $this->byUi[$metadata->name];
            throw $this->duplicateException(sprintf(
                'UI %s name "%s" conflicts with the alias already used by "%s" (declared by %s).',
                $this->kind(),
                $metadata->name,
                $owner,
                $this->byName[$owner]->class,
            ));
        }

        if (isset($this->byName[$metadata->name])) {
            $existing = $this->byName[$metadata->name];
            if ($existing->class === $metadata->class) {
                return;
            }

            throw $this->duplicateException(sprintf(
                'Duplicate UI %s name "%s" — declared by %s and %s.',
                $this->kind(),
                $metadata->name,
                $existing->class,
                $metadata->class,
            ));
        }

        if (
            isset($this->byName[$metadata->ui])
            && $metadata->ui !== $metadata->name
        ) {
            $owner = $this->byName[$metadata->ui];
            throw $this->duplicateException(sprintf(
                'UI %s alias "%s" conflicts with the name already declared by %s.',
                $this->kind(),
                $metadata->ui,
                $owner->class,
            ));
        }

        if (isset($this->byUi[$metadata->ui])) {
            $owner = $this->byUi[$metadata->ui];
            throw $this->duplicateException(sprintf(
                'Duplicate UI %s alias "%s" — already used by "%s" (declared by %s), conflict comes from %s.',
                $this->kind(),
                $metadata->ui,
                $owner,
                $this->byName[$owner]->class,
                $metadata->class,
            ));
        }

        $this->byName[$metadata->name] = $metadata;
        $this->byUi[$metadata->ui] = $metadata->name;
    }

    /**
     * @return T|null
     */
    public function get(string $nameOrAlias): ?UiCatalogEntryInterface
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

    /**
     * @return T|null
     */
    public function getByName(string $name): ?UiCatalogEntryInterface
    {
        $this->initialize();

        return $this->byName[$name] ?? null;
    }

    /**
     * @return T|null
     */
    public function getByUi(string $ui): ?UiCatalogEntryInterface
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
     * @return list<T>
     */
    public function all(): array
    {
        $this->initialize();

        return array_values($this->byName);
    }

    /**
     * Clears discovered state only — the wiring survives. This used to unset
     * the wiring too on one catalog, which made reset() destructive in a way
     * nothing could undo (the boot listener injects once per worker), and
     * initialize() then quietly skipped discovery on every later read; the
     * failure is silent because empty is not an error. CatalogLateWiringTest
     * guards the trap.
     */
    public function reset(): void
    {
        $this->byName = [];
        $this->byUi = [];
        $this->initialized = false;
    }
}

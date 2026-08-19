<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior;

use Semitexa\Core\Attribute\AsService;
use Semitexa\PlatformUi\Application\Service\Catalog\AbstractUiCatalog;
use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Contract\UiCatalogEntryInterface;
use Semitexa\PlatformUi\Domain\Exception\BehaviorRegistryException;
use Semitexa\PlatformUi\Domain\Model\Behavior\BehaviorMetadata;

/**
 * The Platform UI behavior catalog: discovery, name/ui indexing and lookup.
 *
 * Container-managed, so a consumer injects this and gets a wired catalog.
 * {@see UiBehaviorRegistry} remains a static shim over it for callers that cannot
 * receive an injection.
 *
 * All catalog mechanics live in {@see AbstractUiCatalog}; this class owns only
 * what is genuinely behavior-shaped — the attribute, the factory, the
 * exception, the typed entry points, and the pre-boot test helper.
 *
 * @extends AbstractUiCatalog<BehaviorMetadata>
 */
#[AsService]
final class UiBehaviorCatalog extends AbstractUiCatalog
{
    /**
     * Not injected: UiBehaviorMetadataFactory has no container binding — every
     * caller has always built it with new. Settable, with discovery falling
     * back to constructing one.
     */
    protected UiBehaviorMetadataFactory $factory;

    public function setFactory(UiBehaviorMetadataFactory $factory): void
    {
        $this->factory = $factory;
    }

    /**
     * Register a metadata entry manually (test helper / non-discovery flows).
     */
    public function register(BehaviorMetadata $metadata): void
    {
        $this->registerInternal($metadata);
    }

    protected function attribute(): string
    {
        return AsUiBehavior::class;
    }

    protected function kind(): string
    {
        return 'behavior';
    }

    protected function metadataFromClass(string $class): UiCatalogEntryInterface
    {
        return ($this->factory ?? new UiBehaviorMetadataFactory())->fromClass($class);
    }

    protected function duplicateException(string $message): \LogicException
    {
        return new BehaviorRegistryException($message);
    }

    /**
     * Return the catalog to a genuinely unwired state.
     *
     * Only for tests that need to exercise the pre-boot path. Unset rather than
     * assigned null: these are non-nullable typed properties, so null is a
     * TypeError, and unsetting restores the uninitialized state initialize()
     * probes with isset().
     */
    public function resetWiring(): void
    {
        $this->reset();
        unset($this->classDiscovery, $this->factory);
    }
}

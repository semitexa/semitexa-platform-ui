<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive;

use Semitexa\Core\Attribute\AsService;
use Semitexa\PlatformUi\Application\Service\Catalog\AbstractUiCatalog;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Contract\UiCatalogEntryInterface;
use Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;

/**
 * The Platform UI primitive catalog: discovery, name/ui indexing and lookup.
 *
 * Container-managed, so a consumer injects this and gets a wired catalog.
 * {@see UiPrimitiveRegistry} remains a static shim over it for callers that cannot
 * receive an injection.
 *
 * All catalog mechanics live in {@see AbstractUiCatalog}; this class owns only
 * what is genuinely primitive-shaped — the attribute, the factory, the
 * exception, and the typed entry points.
 *
 * @extends AbstractUiCatalog<PrimitiveMetadata>
 */
#[AsService]
final class UiPrimitiveCatalog extends AbstractUiCatalog
{
    /**
     * Not injected: UiPrimitiveMetadataFactory has no container binding — every
     * caller has always built it with new. Settable, with discovery falling
     * back to constructing one.
     */
    protected UiPrimitiveMetadataFactory $factory;

    public function setFactory(UiPrimitiveMetadataFactory $factory): void
    {
        $this->factory = $factory;
    }

    /**
     * Register a metadata entry manually (test helper / non-discovery flows).
     */
    public function register(PrimitiveMetadata $metadata): void
    {
        $this->registerInternal($metadata);
    }


    /**
     * Covariant native return types: the base implements the mechanics, but
     * this catalog's public API keeps promising concrete metadata — injected
     * callers and tooling that read the language-level signature (not the
     * generics) lose nothing to the extraction.
     */
    public function get(string $nameOrAlias): ?PrimitiveMetadata
    {
        /** @var ?PrimitiveMetadata */
        return parent::get($nameOrAlias);
    }

    public function getByName(string $name): ?PrimitiveMetadata
    {
        /** @var ?PrimitiveMetadata */
        return parent::getByName($name);
    }

    public function getByUi(string $ui): ?PrimitiveMetadata
    {
        /** @var ?PrimitiveMetadata */
        return parent::getByUi($ui);
    }

    /**
     * @return list<PrimitiveMetadata>
     */
    public function all(): array
    {
        /** @var list<PrimitiveMetadata> */
        return parent::all();
    }

    protected function attribute(): string
    {
        return AsUiPrimitive::class;
    }

    protected function kind(): string
    {
        return 'primitive';
    }

    protected function metadataFromClass(string $class): UiCatalogEntryInterface
    {
        return ($this->factory ?? new UiPrimitiveMetadataFactory())->fromClass($class);
    }

    protected function duplicateException(string $message): \LogicException
    {
        return new PrimitiveRegistryException($message);
    }
}

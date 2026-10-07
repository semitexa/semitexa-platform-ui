<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCatalogProjector;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;

/**
 * What an agent may compose a screen from (ep-platform-ai-ui): the catalog's
 * components and primitives that have a typed contract and are open to agents
 * (#[AsUiContract(agent:)], previewSafe by default) — and, for a visitor,
 * only those whose permission the visitor holds. An agent is never told of a
 * component it may not use; the validator refuses one it names anyway.
 */
#[AsService]
final class UiAgentCatalog
{
    #[InjectAsReadonly]
    protected UiCatalogProjector $projector;

    /** @var array<string, UiCatalogItem>|null worker-lifetime: the catalog does not change after boot */
    private ?array $open = null;

    /** @return array<string, UiCatalogItem> name => entry, every agent-open one */
    public function open(): array
    {
        if ($this->open === null) {
            $open = [];
            foreach ($this->projector->items() as $item) {
                if ($item->kind !== 'behavior' && $item->contract !== null && $item->contract->agent) {
                    $open[$item->name()] = $item;
                }
            }
            $this->open = $open;
        }

        return $this->open;
    }

    /** @return array<string, UiCatalogItem> the agent-open entries the visitor may use */
    public function forVisitor(): array
    {
        return array_filter($this->open(), self::allowed(...));
    }

    public static function allowed(UiCatalogItem $item): bool
    {
        $permission = $item->contract?->permission;

        return $permission === null || UiPermissions::allows($permission);
    }

    /**
     * The slots a node's children may go into; the first is where a child
     * with no slot goes ("body" or "content" when there is one).
     *
     * @return list<string>
     */
    public static function slotsOf(UiCatalogItem $item): array
    {
        if (!$item->metadata instanceof UiComponentMetadata) {
            return [];
        }
        $slots = array_keys($item->metadata->slots);
        foreach (['content', 'body'] as $default) {
            if (in_array($default, $slots, true)) {
                return [$default, ...array_values(array_diff($slots, [$default]))];
            }
        }

        return $slots;
    }

    /** Test seam: a fixed set of entries. @param array<string, UiCatalogItem> $entries */
    public function useEntries(array $entries): void
    {
        $this->open = $entries;
    }
}

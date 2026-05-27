<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use ReflectionClass;
use Semitexa\PlatformUi\Attribute\AsColumn;
use Semitexa\PlatformUi\Attribute\AsFilter;
use Semitexa\PlatformUi\Attribute\WithPagination;
use Semitexa\Ssr\Attribute\AsComponentMetadataProvider;
use Semitexa\Ssr\Domain\Contract\ComponentMetadataProviderInterface;

/**
 * Contributes grid-shaped props (`columns`, `filters`, `pagination`)
 * to any component class that uses #[AsColumn] / #[AsFilter] /
 * #[WithPagination] attributes.
 *
 * Discovered and invoked at boot by the SSR
 * ComponentMetadataProviderRegistry — must stay stateless and
 * side-effect-free.
 */
#[AsComponentMetadataProvider(priority: 0)]
final class GridComponentMetadataProvider implements ComponentMetadataProviderInterface
{
    /** @inheritDoc */
    public function supports(ReflectionClass $componentClass): bool
    {
        foreach ($componentClass->getProperties() as $prop) {
            if ($prop->getAttributes(AsColumn::class) !== []) {
                return true;
            }
        }
        return $componentClass->getAttributes(WithPagination::class) !== [];
    }

    /** @inheritDoc */
    public function getProps(ReflectionClass $componentClass): array
    {
        return [
            'columns' => $this->resolveColumns($componentClass),
            'filters' => $this->resolveFilters($componentClass),
            'pagination' => $this->resolvePagination($componentClass),
        ];
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<array{key: string, label: string, sortable: bool, type: string}>
     */
    private function resolveColumns(ReflectionClass $class): array
    {
        $columns = [];
        foreach ($class->getProperties() as $prop) {
            $attrs = $prop->getAttributes(AsColumn::class);
            if ($attrs === []) {
                continue;
            }
            /** @var AsColumn $attr */
            $attr = $attrs[0]->newInstance();
            $columns[] = [
                'key' => $prop->getName(),
                'label' => $attr->label,
                'sortable' => $attr->sortable,
                'type' => $attr->type,
            ];
        }
        return $columns;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<array{field: string, type: string, placeholder: string, label: string}>
     */
    private function resolveFilters(ReflectionClass $class): array
    {
        $filters = [];
        foreach ($class->getProperties() as $prop) {
            $attrs = $prop->getAttributes(AsFilter::class);
            if ($attrs === []) {
                continue;
            }
            /** @var AsFilter $attr */
            $attr = $attrs[0]->newInstance();
            $filters[] = [
                'field' => $prop->getName(),
                'type' => $attr->type,
                'placeholder' => $attr->placeholder,
                'label' => $attr->label,
            ];
        }
        return $filters;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{
     *     defaultLimit: int, limitOptions: list<int>, mode: string,
     *     windowSize: int, autoCountThreshold: int
     * }
     */
    private function resolvePagination(ReflectionClass $class): array
    {
        $attrs = $class->getAttributes(WithPagination::class);
        if ($attrs === []) {
            // No attribute → cursor-mode defaults, matching the prior
            // implicit behaviour for grids that only declare columns.
            return [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50],
                'mode'               => WithPagination::MODE_CURSOR,
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ];
        }
        /** @var WithPagination $attr */
        $attr = $attrs[0]->newInstance();
        if (!in_array($attr->defaultLimit, $attr->limitOptions, true)) {
            throw new \InvalidArgumentException(sprintf(
                'WithPagination on %s: defaultLimit %d must be in limitOptions [%s].',
                $class->getName(),
                $attr->defaultLimit,
                implode(', ', $attr->limitOptions),
            ));
        }
        // Single-field invariants (mode enum, odd windowSize >= 3,
        // non-negative threshold) are enforced in the attribute's
        // constructor; they have already fired by the time newInstance()
        // returns above.
        return [
            'defaultLimit'       => $attr->defaultLimit,
            'limitOptions'       => $attr->limitOptions,
            'mode'               => $attr->mode,
            'windowSize'         => $attr->windowSize,
            'autoCountThreshold' => $attr->autoCountThreshold,
        ];
    }
}

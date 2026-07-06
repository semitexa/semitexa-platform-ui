<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\CalendarEventResource;

/**
 * Self-mapping mapper for {@see CalendarEventResource} — resource is the domain
 * model, both directions are clone-passthroughs.
 */
#[AsMapper(
    resourceModel: CalendarEventResource::class,
    domainModel: CalendarEventResource::class,
)]
final class CalendarEventMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof CalendarEventResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof CalendarEventResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}

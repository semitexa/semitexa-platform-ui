<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\CalendarEventResource;
use Semitexa\PlatformUi\Domain\Model\CalendarEvent;

/** The bridge between the MySQL row and one calendar entry. */
#[AsMapper(resourceModel: CalendarEventResource::class, domainModel: CalendarEvent::class)]
final class CalendarEventMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof CalendarEventResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new CalendarEvent(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            userId: $resourceModel->user_id,
            title: $resourceModel->title,
            startsAt: $resourceModel->starts_at,
            endsAt: $resourceModel->ends_at,
            allDay: $resourceModel->all_day === 1,
            location: $resourceModel->location,
            notes: $resourceModel->notes,
            color: $resourceModel->color,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof CalendarEvent || throw new \InvalidArgumentException('Unexpected domain model.');

        $now = new \DateTimeImmutable();

        return new CalendarEventResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            user_id: $domainModel->getUserId(),
            title: $domainModel->getTitle(),
            starts_at: $domainModel->getStartsAt(),
            ends_at: $domainModel->getEndsAt(),
            all_day: $domainModel->isAllDay() ? 1 : 0,
            location: $domainModel->getLocation(),
            notes: $domainModel->getNotes(),
            color: $domainModel->getColor(),
            created_at: $domainModel->getCreatedAt() ?? $now,
            updated_at: $domainModel->getUpdatedAt() ?? $now,
        );
    }
}

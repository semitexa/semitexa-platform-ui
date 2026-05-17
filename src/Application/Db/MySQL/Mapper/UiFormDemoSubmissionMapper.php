<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\UiFormDemoSubmissionResource;

/**
 * Self-mapping mapper for {@see UiFormDemoSubmissionResource}.
 *
 * The demo submission table is so narrow (and the shape lines up
 * 1:1 with `UiFormDemoSubmissionRecord` already) that we do not
 * need a separate mutable domain model. `Domain → Source` and
 * `Source → Domain` are clone-passthroughs — matching the
 * scheduler-package convention for trivial shapes
 * ({@see Semitexa\Scheduler\Application\Db\MySQL\Mapper\SchedulerRunHistoryMapper}).
 */
#[AsMapper(
    resourceModel: UiFormDemoSubmissionResource::class,
    domainModel:   UiFormDemoSubmissionResource::class,
)]
final class UiFormDemoSubmissionMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof UiFormDemoSubmissionResource
            || throw new \InvalidArgumentException('Unexpected resource model.');
        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof UiFormDemoSubmissionResource
            || throw new \InvalidArgumentException('Unexpected domain model.');
        return clone $domainModel;
    }
}

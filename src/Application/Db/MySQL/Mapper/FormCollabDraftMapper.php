<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\FormCollabDraftResource;

/**
 * Self-mapping mapper for {@see FormCollabDraftResource}.
 *
 * The draft row is narrow and maps 1:1 to its own shape, so — like
 * {@see UiFormDemoSubmissionMapper} — both directions are clone-passthroughs;
 * the callers exchange the immutable {@see \Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraftState}
 * DTO with the store, never this resource directly.
 */
#[AsMapper(
    resourceModel: FormCollabDraftResource::class,
    domainModel:   FormCollabDraftResource::class,
)]
final class FormCollabDraftMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof FormCollabDraftResource
            || throw new \InvalidArgumentException('Unexpected resource model.');
        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof FormCollabDraftResource
            || throw new \InvalidArgumentException('Unexpected domain model.');
        return clone $domainModel;
    }
}

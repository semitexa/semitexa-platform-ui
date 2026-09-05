<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\FormCollabDraftResource;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraft;

/**
 * The bridge between the MySQL row and the shared draft. The field values are
 * a JSON string in the column and a map in the draft; a corrupt column yields
 * an empty draft rather than taking the form down.
 */
#[AsMapper(resourceModel: FormCollabDraftResource::class, domainModel: FormCollabDraft::class)]
final class FormCollabDraftMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof FormCollabDraftResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        $decoded = json_decode($resourceModel->values_json, true);
        $values = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $values[$key] = $value;
                }
            }
        }

        return new FormCollabDraft(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            scopeKey: $resourceModel->scope_key,
            values: $values,
            version: $resourceModel->version,
            updatedBy: $resourceModel->updated_by,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof FormCollabDraft || throw new \InvalidArgumentException('Unexpected domain model.');

        return new FormCollabDraftResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            scope_key: $domainModel->getScopeKey(),
            values_json: (string) json_encode($domainModel->getValues(), JSON_UNESCAPED_UNICODE),
            version: $domainModel->getVersion(),
            updated_by: $domainModel->getUpdatedBy(),
            updated_at: $domainModel->getUpdatedAt() ?? new \DateTimeImmutable(),
        );
    }
}

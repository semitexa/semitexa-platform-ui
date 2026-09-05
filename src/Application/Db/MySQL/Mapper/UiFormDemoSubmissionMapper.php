<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\UiFormDemoSubmissionResource;
use Semitexa\PlatformUi\Domain\Model\UiFormDemoSubmission;

/**
 * The bridge between the MySQL row and one recorded submission. The values are
 * a JSON string in the column; a corrupt one yields an empty submission rather
 * than breaking the listing it appears in.
 */
#[AsMapper(resourceModel: UiFormDemoSubmissionResource::class, domainModel: UiFormDemoSubmission::class)]
final class UiFormDemoSubmissionMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof UiFormDemoSubmissionResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        $decoded = json_decode($resourceModel->values_json, true);
        $values = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $values[$key] = $value;
                }
            }
        }

        return new UiFormDemoSubmission(
            id: $resourceModel->id,
            formInstanceId: $resourceModel->form_instance_id,
            actionName: $resourceModel->action_name,
            submittedAt: $resourceModel->submitted_at,
            values: $values,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof UiFormDemoSubmission || throw new \InvalidArgumentException('Unexpected domain model.');

        return new UiFormDemoSubmissionResource(
            id: $domainModel->getId(),
            form_instance_id: $domainModel->getFormInstanceId(),
            action_name: $domainModel->getActionName(),
            submitted_at: $domainModel->getSubmittedAt(),
            values_json: (string) json_encode($domainModel->getValues(), JSON_UNESCAPED_UNICODE),
        );
    }
}

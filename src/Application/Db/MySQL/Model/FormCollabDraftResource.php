<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * Collaborative Form Data · Phase 2 — the durable half of the hybrid store:
 * the shared in-progress DRAFT of one collaborative form document.
 *
 * One row per (tenant, document scope) — the scope is `formdoc:{formKey}:{recordId}`
 * or `form:{instanceId}` (see {@see \Semitexa\Ssr\Domain\Model\FormDocumentScope}),
 * and the unique index spans `(tenant_id, scope_key)` so two tenants that happen
 * to share a `formKey`/`recordId` get SEPARATE draft rows and never read or
 * overwrite each other's in-progress edits. The row holds the merged field
 * values (`values_json`, the same `json_encode()` shape the demo-submission
 * resource uses) plus a monotonically increasing `version` that powers the
 * optimistic concurrency guard, and last-writer audit columns.
 *
 * Durable on purpose (per the operator's hybrid-store decision): a late joiner
 * gets the current draft, and an in-progress edit survives a worker restart.
 * The ephemeral presence + lock state lives separately in Redis (TTL-driven).
 *
 * Class shape mirrors {@see UiFormDemoSubmissionResource}: `final readonly`
 * (required by ResourceModelMetadataValidator), constructor-promoted properties
 * with per-param column metadata, manual PK (the store mints `fcd_<16hex>`), and
 * an explicit `updated_at` rather than the mutable `HasTimestamps` trait (which
 * is incompatible with a readonly resource).
 */
#[FromTable(name: 'form_collab_draft')]
#[Index(columns: ['tenant_id', 'scope_key'], unique: true, name: 'uniq_form_collab_draft_tenant_scope')]
#[Index(columns: ['updated_at', 'id'], name: 'idx_form_collab_draft_updated')]
final readonly class FormCollabDraftResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $id,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,

        #[Column(type: MySqlType::Varchar, length: 191)]
        public string $scope_key,

        #[Column(type: MySqlType::LongText)]
        public string $values_json,

        #[Column(type: MySqlType::Int)]
        public int $version,

        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $updated_by,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {}
}

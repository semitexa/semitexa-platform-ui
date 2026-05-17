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
 * ORM resource for the database-backed Platform UI demo submissions.
 *
 * Carries ONLY the narrow demo-submission shape — same allow-list the
 * cache-backed `platform.demo.storeContact` action uses. Proves the
 * existing FormComponent submit pipeline can store through a real DB
 * without touching the pipeline or the patch format.
 *
 * NOT stored: signed-ctx blob, dispatchId, CSRF token id, raw CSRF
 * token, request payload, debug internals, headers, IP, session id.
 *
 * The id is supplied by the action (`uifs_<16hex>`); the
 * `strategy: 'manual'` annotation tells the ORM the caller writes
 * the value and the engine MUST NOT overwrite it with a lastInsertId.
 *
 * Class shape:
 *   - `final readonly` (required by ResourceModelMetadataValidator);
 *   - constructor-promoted properties with attributes on each param
 *     (the only shape compatible with `readonly` semantics +
 *     property-level Column/PrimaryKey metadata).
 *
 * Compatibility note: timestamps live on `submitted_at` directly —
 * the framework `HasTimestamps` trait adds mutable `created_at` /
 * `updated_at` properties which are incompatible with a readonly
 * resource. Records carry the user-visible submitted-at timestamp;
 * the audit-grade created/updated columns are an explicit future
 * slice if needed.
 */
#[FromTable(name: 'platform_ui_demo_submissions')]
#[Index(columns: ['form_instance_id'], name: 'idx_pui_demo_form_instance')]
#[Index(columns: ['action_name', 'submitted_at'], name: 'idx_pui_demo_action_submitted')]
#[Index(columns: ['submitted_at', 'id'], name: 'idx_pui_demo_submitted_id')]
final readonly class UiFormDemoSubmissionResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 80)]
        public string $form_instance_id,

        #[Column(type: MySqlType::Varchar, length: 128)]
        public string $action_name,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $submitted_at,

        #[Column(type: MySqlType::LongText)]
        public string $values_json,
    ) {}
}

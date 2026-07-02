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
 * ORM resource for a calendar event — the data behind the `platform.calendar`
 * component.
 *
 * Scoped by (tenant, user): `user_id` null = a shared/global calendar (the OS
 * single-user case), non-null = that user's events. `final readonly` with
 * constructor-promoted columns per the current ORM contract; the UUIDv7 id and
 * timestamps are supplied by the repository (manual PK, explicit timestamp
 * columns — the mutable `HasUuidV7`/`HasTimestamps` traits are incompatible with
 * a readonly resource).
 *
 * v1 holds one-off events only; recurrence (RRULE / cron) is a future slice.
 */
#[FromTable(name: 'platform_calendar_events')]
#[Index(columns: ['tenant_id', 'user_id', 'starts_at'], name: 'idx_platform_calendar_scope_start')]
#[Index(columns: ['starts_at'], name: 'idx_platform_calendar_start')]
final readonly class CalendarEventResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        /** Reserved for multi-tenant scoping (schema-ready); null until tenancy is wired. */
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,

        /** Null = shared/global calendar; non-null = that user's events. */
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $user_id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $title,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $starts_at,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $ends_at,

        /** 0 = timed event, 1 = all-day. */
        #[Column(type: MySqlType::Int, default: '0')]
        public int $all_day,

        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $location,

        #[Column(type: MySqlType::LongText, nullable: true)]
        public ?string $notes,

        /** Optional colour/category token (e.g. 'blue', '#37b7ff'). */
        #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $color,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {}
}

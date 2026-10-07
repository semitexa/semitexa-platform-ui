<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Field;

/**
 * What a stored column looks like, for inferring a field from it — kept free
 * of any ORM class so the field registry does not depend on one. A CRUD layer
 * builds it from its model metadata (semitexa-orm: ColumnMetadata).
 */
final readonly class UiColumnShape
{
    public function __construct(
        public string $name,
        /** The storage type, lowercase: varchar, text, int, bigint, decimal, boolean, date, datetime, json, … */
        public string $type,
        public bool $nullable = false,
        public ?int $length = null,
        public ?int $scale = null,
        public bool $isPrimaryKey = false,
    ) {
    }
}

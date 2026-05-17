<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Grid;

/**
 * Pagination payload shape consumed by the `platform.grid` runtime.
 *
 * Pure data carrier — no validation, no clamping, no derivation.
 * The handler is responsible for producing already-clamped values
 * (the existing project-side handlers do this via the criteria +
 * keyset page DTOs); this object only pins the wire shape so a
 * future grid-data handler cannot accidentally rename a key.
 *
 * Wire keys (exact order): `limit`, `hasMore`, `nextCursor`.
 */
final readonly class UiGridPaginationData
{
    public function __construct(
        public int $limit,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}

    /**
     * @return array{limit:int, hasMore:bool, nextCursor:?string}
     */
    public function toArray(): array
    {
        return [
            'limit'      => $this->limit,
            'hasMore'    => $this->hasMore,
            'nextCursor' => $this->nextCursor,
        ];
    }
}

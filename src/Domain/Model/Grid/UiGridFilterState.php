<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Grid;

/**
 * Filter-state payload shape consumed by the `platform.grid`
 * runtime to update its filter-state strip after each reload.
 *
 * Pure data carrier — the handler is responsible for handing in
 * already-normalised (trimmed / allow-listed) values; this object
 * only pins the wire shape.
 *
 * Wire keys (exact order): `q`, `action`.
 *
 * The fields are deliberately `?string` rather than an open map —
 * a future per-grid filter set would extend the runtime's bundle
 * contract first; both current consumers use exactly these two
 * filters so the typed surface is intentionally narrow.
 */
final readonly class UiGridFilterState
{
    public function __construct(
        public ?string $q,
        public ?string $action,
    ) {}

    /**
     * @return array{q:?string, action:?string}
     */
    public function toArray(): array
    {
        return [
            'q'      => $this->q,
            'action' => $this->action,
        ];
    }
}

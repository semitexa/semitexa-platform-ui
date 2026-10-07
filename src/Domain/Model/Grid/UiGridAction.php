<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Grid;

/**
 * One action a grid offers: on each row, on a selection of rows, or in the
 * header (no rows). It is rendered into the grid's signed props, so the
 * browser can ask for exactly these and nothing else.
 */
final readonly class UiGridAction
{
    public const SCOPES = ['row', 'bulk', 'header'];

    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public string $label,
        public array $scopes,
        public ?string $confirm = null,
        public string $tone = 'neutral',
        /** The question for a selection, when it reads differently ("Delete {count} products?"). */
        public ?string $confirmBulk = null,
        /**
         * The action takes its rows away (a delete): the grid hides them the
         * moment it is confirmed, and shows them again if the server refuses —
         * or if the next frame still has them.
         */
        public bool $removesRows = false,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('A grid action id must be a lowercase word, not "%s".', $id));
        }
        if ($scopes === [] || array_diff($scopes, self::SCOPES) !== []) {
            throw new \InvalidArgumentException(sprintf('Grid action "%s" needs scopes from %s.', $id, implode(', ', self::SCOPES)));
        }
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * The props shape `platform.grid` takes in `serverActions`.
     *
     * @return array{id: string, label: string, scopes: list<string>, confirm?: string, confirmBulk?: string, tone: string, optimistic?: string}
     */
    public function toProps(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'scopes' => array_values($this->scopes), 'tone' => $this->tone]
            + ($this->confirm !== null ? ['confirm' => $this->confirm] : [])
            + ($this->confirmBulk !== null ? ['confirmBulk' => $this->confirmBulk] : [])
            + ($this->removesRows ? ['optimistic' => 'remove'] : []);
    }

    /** @param array<array-key, mixed> $props one entry of a grid's signed `serverActions` */
    public static function fromProps(array $props): ?self
    {
        try {
            return new self(
                id: (string) ($props['id'] ?? ''),
                label: (string) ($props['label'] ?? ''),
                scopes: array_values(array_filter((array) ($props['scopes'] ?? []), 'is_string')),
                confirm: isset($props['confirm']) && is_string($props['confirm']) ? $props['confirm'] : null,
                tone: is_string($props['tone'] ?? null) ? $props['tone'] : 'neutral',
                confirmBulk: isset($props['confirmBulk']) && is_string($props['confirmBulk']) ? $props['confirmBulk'] : null,
                removesRows: ($props['optimistic'] ?? null) === 'remove',
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}

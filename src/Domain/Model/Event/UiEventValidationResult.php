<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Multi-field validation result attached to a {@see UiEventResponse} when the
 * handler decides the input was structurally fine to accept but failed
 * domain-level checks (technical-design.md §12.8).
 *
 * Shape matches Semitexa Core's {@see \Semitexa\Core\Exception\ValidationException}
 * convention: `array<field, list<message>>` so the frontend renderer can
 * group multiple messages under a single field without inventing a parser.
 *
 * Single-field flows that already use {@see UiFieldValidationResult} remain
 * supported — this DTO is the response-level aggregate, not a replacement.
 */
final readonly class UiEventValidationResult
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasField(string $field): bool
    {
        return isset($this->errors[$field]) && $this->errors[$field] !== [];
    }

    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}

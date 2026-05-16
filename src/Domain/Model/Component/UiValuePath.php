<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;

/**
 * Immutable dot-separated value path used by #[UiPart(bind: '…')].
 *
 * Allowed syntax (validated at construction):
 *
 *   ^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$
 *
 * Each segment must:
 *   - start with a letter or underscore;
 *   - continue with letters, digits, or underscores;
 *   - be separated by exactly one dot;
 *   - not be empty.
 *
 * Disallowed: empty string, leading dot, trailing dot, double dots, brackets,
 * wildcards, spaces, Twig delimiters, PHP code.
 *
 * Examples (valid):    value · email · user.email · address.street · filters.search_text
 * Examples (invalid):  "" · ".value" · "value." · "user..email" · "user[email]"
 *                      "user.*" · "user email" · "{{ value }}"
 *
 * `resolve()` walks the segments through an array source. Any missing
 * segment short-circuits to `null` — the caller must treat null as
 * "value is not bound" and decide what to do. Non-array intermediate
 * segments also yield null (e.g. resolving `user.email` on
 * ['user' => 'string-not-an-array'] returns null).
 */
final readonly class UiValuePath
{
    private const PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/';

    private function __construct(
        public string $path,
        /** @var non-empty-list<non-empty-string> */
        public array $segments,
    ) {}

    public static function parse(string $path): self
    {
        if ($path === '') {
            throw new UiComponentRegistryException(
                'UiValuePath cannot be empty.',
            );
        }
        if (preg_match(self::PATTERN, $path) !== 1) {
            throw new UiComponentRegistryException(sprintf(
                'Invalid UiValuePath "%s". Expected dot-separated identifiers matching %s — no brackets, wildcards, spaces, or Twig delimiters.',
                $path,
                self::PATTERN,
            ));
        }

        $segments = explode('.', $path);
        // PATTERN above already guarantees non-empty segments,
        // but the explicit cast keeps PHPStan honest.
        /** @var non-empty-list<non-empty-string> $segments */
        return new self(path: $path, segments: $segments);
    }

    public function isNested(): bool
    {
        return count($this->segments) > 1;
    }

    public function head(): string
    {
        return $this->segments[0];
    }

    /**
     * Walk the path through a nested array. Returns null if any segment is
     * missing or if any intermediate value is not an array.
     *
     * @param array<string, mixed> $source
     */
    public function resolve(array $source): mixed
    {
        /** @var mixed $cursor */
        $cursor = $source;
        foreach ($this->segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            /** @var mixed $cursor */
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    public function __toString(): string
    {
        return $this->path;
    }
}

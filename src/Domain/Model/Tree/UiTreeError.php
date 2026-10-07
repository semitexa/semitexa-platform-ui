<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Tree;

/**
 * One thing wrong with a UI tree, said so that a model — or a person — can fix
 * exactly that: what kind of fault (`code`), where (`path`, a JSON Pointer into
 * the tree), what was expected, what was there, and how to repair it.
 */
final readonly class UiTreeError
{
    public function __construct(
        public string $code,
        public string $path,
        public string $message,
        public ?string $expected = null,
        public ?string $got = null,
        public ?string $hint = null,
    ) {
    }

    /** @return array{code: string, path: string, message: string, expected?: string, got?: string, hint?: string} */
    public function toArray(): array
    {
        return array_filter(
            ['code' => $this->code, 'path' => $this->path, 'message' => $this->message, 'expected' => $this->expected, 'got' => $this->got, 'hint' => $this->hint],
            static fn (?string $v): bool => $v !== null,
        );
    }

    /** A short rendering of a value for `got`: what a model should see, not a dump. */
    public static function describe(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $text = is_string($json) ? $json : get_debug_type($value);

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 77) . '...' : $text;
    }
}

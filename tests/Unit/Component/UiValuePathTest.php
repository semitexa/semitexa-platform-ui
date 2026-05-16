<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiValuePath;

final class UiValuePathTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function validPaths(): iterable
    {
        yield 'single value'             => ['value',          ['value']];
        yield 'email'                    => ['email',          ['email']];
        yield 'two segments'             => ['user.email',     ['user', 'email']];
        yield 'three segments'           => ['address.street', ['address', 'street']];
        yield 'underscored segment'      => ['filters.search_text', ['filters', 'search_text']];
        yield 'leading underscore allow' => ['_private',       ['_private']];
        yield 'digits inside segment'    => ['user1.email2',   ['user1', 'email2']];
    }

    #[DataProvider('validPaths')]
    #[Test]
    public function parses_valid_paths(string $input, array $expectedSegments): void
    {
        $path = UiValuePath::parse($input);

        self::assertSame($input, $path->path);
        self::assertSame($expectedSegments, $path->segments);
        self::assertSame((string) $path, $input);
        self::assertSame(count($expectedSegments) > 1, $path->isNested());
        self::assertSame($expectedSegments[0], $path->head());
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidPaths(): iterable
    {
        yield 'empty string'      => [''];
        yield 'leading dot'       => ['.value'];
        yield 'trailing dot'      => ['value.'];
        yield 'double dot'        => ['user..email'];
        yield 'bracket access'    => ['user[email]'];
        yield 'wildcard'          => ['user.*'];
        yield 'space in segment'  => ['user email'];
        yield 'twig delimiter'    => ['{{ value }}'];
        yield 'starts with digit' => ['1user'];
        yield 'segment starts with digit' => ['user.1email'];
        yield 'php syntax'        => ['$value'];
        yield 'hyphenated segment'=> ['user-email'];
    }

    #[DataProvider('invalidPaths')]
    #[Test]
    public function rejects_invalid_paths(string $input): void
    {
        $this->expectException(UiComponentRegistryException::class);
        UiValuePath::parse($input);
    }

    #[Test]
    public function resolves_top_level_value(): void
    {
        $path = UiValuePath::parse('value');
        self::assertSame('hi', $path->resolve(['value' => 'hi']));
    }

    #[Test]
    public function resolves_nested_path(): void
    {
        $path = UiValuePath::parse('user.email');
        self::assertSame('taras@example.com', $path->resolve([
            'user' => ['email' => 'taras@example.com'],
        ]));
    }

    #[Test]
    public function returns_null_for_missing_top_level_key(): void
    {
        $path = UiValuePath::parse('email');
        self::assertNull($path->resolve(['other' => 'x']));
    }

    #[Test]
    public function returns_null_for_missing_nested_key(): void
    {
        $path = UiValuePath::parse('user.email');
        self::assertNull($path->resolve(['user' => ['name' => 'taras']]));
    }

    #[Test]
    public function returns_null_when_intermediate_is_not_an_array(): void
    {
        $path = UiValuePath::parse('user.email');
        self::assertNull($path->resolve(['user' => 'not-an-array']));
    }

    #[Test]
    public function preserves_explicit_null_when_present(): void
    {
        // An explicitly null bound value cannot be distinguished from "missing"
        // through resolve(); document that null short-circuits the bind step.
        $path = UiValuePath::parse('value');
        self::assertNull($path->resolve(['value' => null]));
    }
}

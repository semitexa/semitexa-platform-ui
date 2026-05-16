<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator;

final class UiInstanceIdGeneratorTest extends TestCase
{
    #[Test]
    public function generates_prefixed_hex_ids(): void
    {
        $id = (new UiInstanceIdGenerator())->next();

        self::assertStringStartsWith(UiInstanceIdGenerator::PREFIX, $id);
        self::assertMatchesRegularExpression('/^uci_[0-9a-f]{16}$/', $id);
    }

    #[Test]
    public function consecutive_ids_do_not_collide(): void
    {
        $gen = new UiInstanceIdGenerator();
        $a = $gen->next();
        $b = $gen->next();
        $c = $gen->next();

        self::assertNotSame($a, $b);
        self::assertNotSame($b, $c);
        self::assertNotSame($a, $c);
    }

    #[Test]
    public function generated_id_passes_is_safe(): void
    {
        // Default generator output is the canonical safe shape.
        self::assertTrue(UiInstanceIdGenerator::isSafe((new UiInstanceIdGenerator())->next()));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function isSafeCases(): iterable
    {
        // Accepted: prefix + alphanumeric / underscore / hyphen tail
        // within the 1..64 char tail bound.
        yield 'generated hex id'         => ['uci_a1b2c3d4e5f60718', true];
        yield 'developer-readable id'    => ['uci_submit_access_code', true];
        yield 'hyphenated id'            => ['uci_submit-confirm', true];
        yield 'mixed case'               => ['uci_AccessCode_01', true];
        yield 'short id (1 tail char)'   => ['uci_a', true];
        yield 'at length limit (64)'     => ['uci_' . str_repeat('a', 64), true];

        // Rejected: anything that could escape an HTML attribute,
        // smuggle a CSS selector, or fall outside the prefix.
        yield 'missing prefix'           => ['access_code', false];
        yield 'empty tail'               => ['uci_', false];
        yield 'over length limit (65)'   => ['uci_' . str_repeat('a', 65), false];
        yield 'has space'                => ['uci_bad name', false];
        yield 'has dot'                  => ['uci_bad.id', false];
        yield 'has hash'                 => ['uci_bad#id', false];
        yield 'has slash'                => ['uci_bad/id', false];
        yield 'has angle bracket'        => ['uci_<x>', false];
        yield 'has quote'                => ['uci_bad"id', false];
        yield 'wrong prefix uci'         => ['UCI_xxxx', false];
        yield 'whitespace only'          => ['   ', false];
        yield 'empty string'             => ['', false];
        yield 'null'                     => [null, false];
        yield 'integer'                  => [42, false];
        yield 'array'                    => [['uci_x'], false];
    }

    #[Test]
    #[DataProvider('isSafeCases')]
    public function is_safe_classifies_values_correctly(mixed $value, bool $expected): void
    {
        self::assertSame($expected, UiInstanceIdGenerator::isSafe($value));
    }
}

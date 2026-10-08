<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Link;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Link\UiSafeHref;

final class UiSafeHrefTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function cases(): iterable
    {
        yield 'path' => ['/docs/start', '/docs/start'];
        yield 'relative' => ['pricing', 'pricing'];
        yield 'dot relative' => ['../up', '../up'];
        yield 'fragment' => ['#faq', '#faq'];
        yield 'query' => ['?page=2', '?page=2'];
        yield 'colon in path' => ['/time/10:30', '/time/10:30'];
        yield 'colon in query' => ['?at=10:30', '?at=10:30'];
        yield 'https' => ['https://example.test/a', 'https://example.test/a'];
        yield 'http upper' => ['HTTP://example.test', 'HTTP://example.test'];
        yield 'mailto' => ['mailto:a@example.test', 'mailto:a@example.test'];
        yield 'tel' => ['tel:+100', 'tel:+100'];
        yield 'trimmed' => ['  /a  ', '/a'];
        yield 'javascript' => ['javascript:alert(1)', ''];
        yield 'javascript mixed case' => ['JaVaScRiPt:alert(1)', ''];
        yield 'javascript leading space' => ['  javascript:alert(1)', ''];
        yield 'javascript with tab' => ["java\tscript:alert(1)", ''];
        yield 'javascript with newline' => ["javascript\n:alert(1)", ''];
        yield 'data' => ['data:text/html,<b>x', ''];
        yield 'vbscript' => ['vbscript:msgbox', ''];
        yield 'protocol relative' => ['//evil.test/x', ''];
        yield 'backslash protocol relative' => ['\\\\evil.test/x', ''];
        yield 'mixed slashes' => ['/\\evil.test', ''];
        yield 'empty' => ['', ''];
        yield 'null' => [null, ''];
        yield 'array' => [['/a'], ''];
        yield 'int' => [42, '42'];
    }

    #[Test]
    #[DataProvider('cases')]
    public function keeps_safe_targets_and_drops_script(mixed $href, string $expected): void
    {
        self::assertSame($expected, UiSafeHref::filter($href));
    }
}

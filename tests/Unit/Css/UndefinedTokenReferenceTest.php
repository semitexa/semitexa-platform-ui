<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Semitexa\Theme\Application\Service\Skin\TokenContract;

/**
 * Every `var(--ui-…)` the package uses names a token that exists: a public
 * skin token (TokenContract) or one this package defines in its own CSS.
 *
 * Found 2026-10-06: the grid runtime leaned on --ui-action-primary and
 * --ui-state-*-surface, and the collab form on --ui-border. No skin defines
 * them, so their light hex fallbacks always won — wrong in dark mode — and
 * where there was no fallback the property simply vanished (an Apply button
 * with no background). Nothing failed; this does.
 */
final class UndefinedTokenReferenceTest extends TestCase
{
    #[Test]
    public function every_ui_token_the_package_uses_is_defined(): void
    {
        $root = \dirname(__DIR__, 3);
        $defined = array_map(static fn (TokenContract $t): string => $t->value, TokenContract::cases());
        $used = [];
        foreach (self::files($root, ['css', 'js', 'twig', 'php']) as $file) {
            if (str_contains($file, '/tests/')) {
                continue;
            }
            $text = @file_get_contents($file);
            self::assertIsString($text, 'cannot read ' . $file . ' — a gate that cannot read a file must not pass it');
            if (str_ends_with($file, '.css')) {
                preg_match_all('/(--ui-[a-z0-9-]+)\s*:/', $text, $m);
                array_push($defined, ...$m[1]);
            }
            // A name built at runtime (`--ui-radius-{$value}`) is not a reference to check.
            preg_match_all('/var\(\s*(--ui-[a-z0-9-]+)\s*[,)]/', $text, $m);
            foreach ($m[1] as $name) {
                $used[$name][] = substr($file, strlen($root) + 1);
            }
        }

        $missing = array_diff_key($used, array_flip($defined));
        $report = array_map(
            static fn (string $name, array $files): string => $name . ' — ' . implode(', ', array_unique($files)),
            array_keys($missing),
            $missing,
        );
        self::assertSame([], $report, "Tokens used but defined nowhere:\n" . implode("\n", $report));
        self::assertGreaterThan(50, count($used), 'the scan found too few references to be scanning anything');
    }

    /**
     * @param list<string> $extensions
     * @return iterable<string>
     */
    private static function files(string $root, array $extensions): iterable
    {
        foreach (['src', 'resources'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (in_array($file->getExtension(), $extensions, true)) {
                    yield $file->getPathname();
                }
            }
        }
    }
}

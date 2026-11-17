<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Console\Command\Css\BuildCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * tk-full-css-drifts-from-its-sources: pages are served the committed full.css,
 * not the sources it is built from. On 2026-11-16 it was found built from a
 * 10-07 tree: two source fixes (AA contrast of soft-tone text, the invalid border
 * after :checked) had never reached a page, and nothing noticed. The bundles a
 * fresh compile gives must be the committed ones.
 */
final class CommittedBundlesMatchTheirSourcesTest extends TestCase
{
    private const COMMITTED = __DIR__ . '/../../../src/Application/Static/css';
    private const RESOURCES = __DIR__ . '/../../../resources';

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir === null || !is_dir($this->dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    #[Test]
    public function the_committed_bundles_match_their_sources(): void
    {
        $tester = new CommandTester(new BuildCommand());
        $exit = $tester->execute(['--check' => true]);

        // On failure the display names the stale bundle and the command that rebuilds it.
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame('full.css and baseline.css match their sources.', trim($tester->getDisplay()));
    }

    #[Test]
    public function a_bundle_that_drifted_from_its_sources_fails_and_names_the_rebuild(): void
    {
        $static = $this->directory('static');
        copy(self::COMMITTED . '/baseline.css', $static . '/baseline.css');
        file_put_contents($static . '/full.css', file_get_contents(self::COMMITTED . '/full.css') . "\n/* a source fix nobody rebuilt */\n");

        $json = new CommandTester((new BuildCommand())->withDirectories($static));
        self::assertSame(Command::FAILURE, $json->execute(['--check' => true, '--json' => true]));
        self::assertSame([
            'artifact' => 'semitexa.platform-ui.css-build/v1',
            'check' => true,
            'up_to_date' => false,
            'stale' => ['full.css'],
            'unreadable' => [],
            'rebuild' => 'bin/semitexa platform-ui:css:build',
        ], json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR));

        $text = new CommandTester((new BuildCommand())->withDirectories($static));
        self::assertSame(Command::FAILURE, $text->execute(['--check' => true]));
        self::assertSame(
            'Stale: full.css. The sources changed since it was built, and pages are served the built file, not the sources. '
            . 'Rebuild: bin/semitexa platform-ui:css:build, and commit the result.',
            trim($text->getDisplay()),
        );

        self::assertStringEndsWith(
            "/* a source fix nobody rebuilt */\n",
            (string) file_get_contents($static . '/full.css'),
            'a check writes nothing',
        );
    }

    /**
     * A source the compile could not read leaves the same bytes out of the fresh
     * bundle as out of one built without it, so the comparison alone passed
     * (review, platform-ui#91). The check fails and names the source.
     */
    #[Test]
    public function a_source_the_check_cannot_read_fails_it_even_when_the_bytes_agree(): void
    {
        $resources = $this->directory('resources/baseline');
        foreach (glob(self::RESOURCES . '/baseline/*.css') ?: [] as $source) {
            if (basename($source) !== 'reset.css') {
                copy($source, $resources . '/' . basename($source));
            }
        }
        $static = $this->directory('static');
        $resourcesRoot = dirname($resources);

        // Built without the source: a build goes on without it, as before.
        $build = new CommandTester((new BuildCommand())->withDirectories($static, $resourcesRoot));
        self::assertSame(Command::SUCCESS, $build->execute([]));

        $check = new CommandTester((new BuildCommand())->withDirectories($static, $resourcesRoot));
        self::assertSame(Command::FAILURE, $check->execute(['--check' => true, '--json' => true]));
        self::assertSame([
            'artifact' => 'semitexa.platform-ui.css-build/v1',
            'check' => true,
            'up_to_date' => false,
            'stale' => [],
            'unreadable' => [$resourcesRoot . '/baseline/reset.css'],
            'rebuild' => 'bin/semitexa platform-ui:css:build',
        ], json_decode($check->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** The deprecated --skin warning came before --json's envelope on stdout (review, platform-ui#91). */
    #[Test]
    public function the_json_envelope_is_the_whole_of_stdout_even_with_the_deprecated_skin_flag(): void
    {
        $console = new CommandTester(new BuildCommand());
        self::assertSame(Command::SUCCESS, $console->execute(['--check' => true, '--json' => true, '--skin' => 'legacy'], ['capture_stderr_separately' => true]));
        self::assertTrue(self::upToDate($console->getDisplay()));
        self::assertStringContainsString('Warning: --skin flag is deprecated and has no effect.', $console->getErrorOutput());

        // An output with no stderr of its own drops the warning rather than break the JSON.
        $plain = new CommandTester(new BuildCommand());
        self::assertSame(Command::SUCCESS, $plain->execute(['--check' => true, '--json' => true, '--skin' => 'legacy']));
        self::assertTrue(self::upToDate($plain->getDisplay()));
    }

    /** The envelope's verdict; decoding throws when stdout is not JSON alone. */
    private static function upToDate(string $stdout): mixed
    {
        $envelope = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);

        return $envelope['up_to_date'] ?? null;
    }

    private function directory(string $relative): string
    {
        $this->dir ??= sys_get_temp_dir() . '/css-drift-' . bin2hex(random_bytes(6));
        $path = $this->dir . '/' . $relative;
        mkdir($path, 0777, true);

        return $path;
    }
}

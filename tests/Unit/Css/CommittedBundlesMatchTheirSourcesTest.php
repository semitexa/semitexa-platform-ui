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
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir === null) {
            return;
        }
        foreach (['full.css', 'baseline.css'] as $file) {
            if (is_file($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
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
        $this->dir = sys_get_temp_dir() . '/css-drift-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $committed = dirname(__DIR__, 3) . '/src/Application/Static/css';
        copy($committed . '/baseline.css', $this->dir . '/baseline.css');
        file_put_contents($this->dir . '/full.css', file_get_contents($committed . '/full.css') . "\n/* a source fix nobody rebuilt */\n");

        $json = new CommandTester((new BuildCommand())->withStaticCssDir($this->dir));
        self::assertSame(Command::FAILURE, $json->execute(['--check' => true, '--json' => true]));
        self::assertSame([
            'artifact' => 'semitexa.platform-ui.css-build/v1',
            'check' => true,
            'up_to_date' => false,
            'stale' => ['full.css'],
            'rebuild' => 'bin/semitexa platform-ui:css:build',
        ], json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR));

        $text = new CommandTester((new BuildCommand())->withStaticCssDir($this->dir));
        self::assertSame(Command::FAILURE, $text->execute(['--check' => true]));
        self::assertSame(
            'Stale: full.css. The sources changed since it was built, and pages are served the built file, not the sources. '
            . 'Rebuild: bin/semitexa platform-ui:css:build, and commit the result.',
            trim($text->getDisplay()),
        );

        self::assertStringEndsWith(
            "/* a source fix nobody rebuilt */\n",
            (string) file_get_contents($this->dir . '/full.css'),
            'a check writes nothing',
        );
    }
}

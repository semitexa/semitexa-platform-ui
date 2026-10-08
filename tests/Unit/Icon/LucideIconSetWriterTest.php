<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Icon;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Icon\LucideIconSetWriter;

final class LucideIconSetWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lucide-writer-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/pkg/icons', 0775, true);
        file_put_contents($this->root . '/pkg/tags.json', json_encode(['menu' => ['bars'], 'x' => ['close']]));
        file_put_contents($this->root . '/pkg/package.json', json_encode(['version' => '1.2.3']));
        file_put_contents($this->root . '/pkg/LICENSE', 'ISC');
        file_put_contents($this->root . '/pkg/icons/menu.svg', "<svg viewBox=\"0 0 24 24\">\n  <path d=\"M4 6h16\" />\n</svg>");
        file_put_contents($this->root . '/pkg/icons/x.svg', '<svg viewBox="0 0 24 24"><path d="M18 6 6 18"/></svg>');
        mkdir($this->root . '/out');
        file_put_contents($this->root . '/out/m.json', '{"menu":"previous"}');
    }

    protected function tearDown(): void
    {
        @chmod($this->root . '/out', 0775);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function writes_the_sharded_catalog(): void
    {
        $result = (new LucideIconSetWriter())->write($this->root . '/pkg', $this->root . '/out');

        self::assertSame(['icons' => 2, 'aliases' => 0, 'version' => '1.2.3'], $result);
        self::assertSame(['menu' => '<path d="M4 6h16"/>'], json_decode((string) file_get_contents($this->root . '/out/m.json'), true));
        self::assertFileExists($this->root . '/out/x.json');
        self::assertSame("1.2.3\n", file_get_contents($this->root . '/out/VERSION'));
        self::assertSame([], glob($this->root . '/out/.sync-*'), 'no staging directory is left behind');
    }

    #[Test]
    public function an_svg_that_is_not_an_svg_fails_the_sync_and_keeps_the_previous_catalog(): void
    {
        file_put_contents($this->root . '/pkg/icons/menu.svg', 'not an svg');

        try {
            (new LucideIconSetWriter())->write($this->root . '/pkg', $this->root . '/out');
            self::fail('a blank icon was published as a successful sync');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('menu.svg', $e->getMessage());
        }
        self::assertSame('{"menu":"previous"}', file_get_contents($this->root . '/out/m.json'));
    }

    #[Test]
    public function a_target_that_cannot_be_written_fails_the_sync_and_keeps_the_previous_catalog(): void
    {
        chmod($this->root . '/out', 0555);
        if (is_writable($this->root . '/out')) {
            self::markTestSkipped('running as a user that ignores directory permissions');
        }

        try {
            (new LucideIconSetWriter())->write($this->root . '/pkg', $this->root . '/out');
            self::fail('the sync reported success without writing the catalog');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Cannot', $e->getMessage());
        }
        self::assertSame('{"menu":"previous"}', file_get_contents($this->root . '/out/m.json'));
    }
}

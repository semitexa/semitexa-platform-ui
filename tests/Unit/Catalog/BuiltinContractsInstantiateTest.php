<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every built-in component, primitive and behavior can build its own
 * attributes. A contract checks itself when instantiated (a prop's default
 * against its type, say), and that used to happen first at worker boot: a
 * chart declared `height` with default 48 but no Integer type, and the app
 * refused to start. This instantiates them all, in a test.
 */
final class BuiltinContractsInstantiateTest extends TestCase
{
    #[Test]
    public function every_builtin_declaration_instantiates(): void
    {
        $root = dirname(__DIR__, 3) . '/src/Application';
        $checked = 0;
        foreach (['Component/Builtin', 'Service/Primitive/Builtin', 'Service/Behavior/Builtin'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $class = 'Semitexa\\PlatformUi\\Application\\' . str_replace('/', '\\', $relative);
                // A file whose class does not load is a fault, not a skip: its
                // attributes would go unchecked until worker boot.
                self::assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class), sprintf('%s declares no %s (namespace or class name off its path).', $relative . '.php', $class));
                foreach ((new \ReflectionClass($class))->getAttributes() as $attribute) {
                    try {
                        $attribute->newInstance();
                    } catch (\Throwable $e) {
                        self::fail(sprintf('%s #[%s]: %s', $class, $attribute->getName(), $e->getMessage()));
                    }
                    $checked++;
                }
            }
        }

        self::assertGreaterThan(50, $checked, 'the built-ins were found');
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Palette;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Attribute\AsCommandSource;
use Semitexa\PlatformUi\Domain\Model\Palette\UiPaletteItem;

/**
 * The discovered #[AsCommandSource] services (as per-request factories, so an
 * #[ExecutionScoped] source injects the visitor's auth) and the permission
 * check the palette applies to their commands. Set at worker boot.
 */
final class UiCommandSources
{
    public const PER_SOURCE = 8;
    public const TOTAL = 20;

    /** @var array<class-string, \Closure(): UiCommandSourceInterface> */
    private static array $sources = [];

    /** @var (\Closure(string): bool)|null */
    private static ?\Closure $allows = null;

    /**
     * @param iterable<class-string> $classes
     * @param callable(class-string): object $resolve makes the source for one request
     */
    public static function discover(iterable $classes, callable $resolve): void
    {
        $sources = [];
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->getAttributes(AsCommandSource::class) === []) {
                continue;
            }
            if (!$reflection->implementsInterface(UiCommandSourceInterface::class)) {
                throw new \LogicException(sprintf('#[AsCommandSource] class %s must implement %s.', $class, UiCommandSourceInterface::class));
            }
            $sources[$class] = static function () use ($resolve, $class): UiCommandSourceInterface {
                $source = $resolve($class);
                if (!$source instanceof UiCommandSourceInterface) {
                    throw new \LogicException(sprintf('%s did not resolve to a command source.', $class));
                }

                return $source;
            };
        }
        self::$sources = $sources;
    }

    /** @param \Closure(): UiCommandSourceInterface|UiCommandSourceInterface $source test seam */
    public static function add(string $key, \Closure|UiCommandSourceInterface $source): void
    {
        self::$sources[$key] = $source instanceof \Closure ? $source : static fn (): UiCommandSourceInterface => $source;
    }

    /** @param (\Closure(string): bool)|null $allows does the visitor hold this permission? */
    public static function checkPermissionsWith(?\Closure $allows): void
    {
        self::$allows = $allows;
    }

    public static function reset(): void
    {
        self::$sources = [];
        self::$allows = null;
    }

    /** @return list<UiPaletteItem> */
    public static function search(string $query): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 100) {
            return [];
        }
        $results = [];
        foreach (self::$sources as $key => $factory) {
            $taken = 0;
            // One source that fails (its table missing, its service down) is
            // logged and skipped, like a dashboard widget: the other sources
            // still answer, instead of the whole palette going blank.
            try {
                foreach ($factory()->search($query, self::PER_SOURCE) as $command) {
                    if (!$command instanceof UiPaletteItem || !self::visible($command)) {
                        continue;
                    }
                    $results[] = $command;
                    if (++$taken >= self::PER_SOURCE || count($results) >= self::TOTAL) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                StaticLoggerBridge::error('platform-ui', 'Command palette source failed', [
                    'source' => (string) $key,
                    'error' => $e->getMessage(),
                ]);
            }
            if (count($results) >= self::TOTAL) {
                break;
            }
        }

        return $results;
    }

    /** A permission nobody can check is a permission not granted. */
    private static function visible(UiPaletteItem $command): bool
    {
        if ($command->permission === null) {
            return true;
        }

        return self::$allows !== null && (self::$allows)($command->permission);
    }
}

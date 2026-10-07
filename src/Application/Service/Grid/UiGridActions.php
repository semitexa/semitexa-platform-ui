<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Grid;

use Semitexa\PlatformUi\Attribute\AsGridAction;

/**
 * The #[AsGridAction] handlers by name, discovered at worker boot and each
 * resolved per call (from the request scope, so its injections are the
 * visitor's). A bad or duplicate name, or a class that is not a grid action,
 * fails the boot rather than the first click.
 */
final class UiGridActions
{
    private const NAME_PATTERN = '/\A[a-z][a-z0-9_.-]{0,127}\z/';

    /** @var array<string, \Closure(): UiGridActionInterface> */
    private static array $handlers = [];

    /**
     * @param iterable<class-string>         $classes
     * @param callable(class-string): object $resolve
     */
    public static function discover(iterable $classes, callable $resolve): void
    {
        $handlers = [];
        $declaredBy = [];
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(AsGridAction::class);
            if ($attributes === []) {
                continue;
            }
            $name = $attributes[0]->newInstance()->name;
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new \LogicException(sprintf('#[AsGridAction] on %s has an invalid name "%s".', $class, $name));
            }
            if (isset($declaredBy[$name])) {
                throw new \LogicException(sprintf('#[AsGridAction("%s")] is declared twice (%s and %s).', $name, $declaredBy[$name], $class));
            }
            if (!$reflection->implementsInterface(UiGridActionInterface::class)) {
                throw new \LogicException(sprintf('#[AsGridAction] class %s must implement %s.', $class, UiGridActionInterface::class));
            }
            /** @var UiGridActionInterface $bare */
            $bare = $reflection->newInstanceWithoutConstructor();
            if ($bare->name() !== $name) {
                throw new \LogicException(sprintf('%s::name() returns "%s" but its #[AsGridAction] says "%s".', $class, $bare->name(), $name));
            }
            $declaredBy[$name] = $class;
            $handlers[$name] = static function () use ($resolve, $class): UiGridActionInterface {
                $handler = $resolve($class);
                if (!$handler instanceof UiGridActionInterface) {
                    throw new \LogicException(sprintf('%s did not resolve to a grid action.', $class));
                }

                return $handler;
            };
        }
        self::$handlers = $handlers;
    }

    /** Test seam. */
    public static function add(UiGridActionInterface $handler): void
    {
        self::$handlers[$handler->name()] = static fn (): UiGridActionInterface => $handler;
    }

    public static function has(string $name): bool
    {
        return isset(self::$handlers[$name]);
    }

    public static function get(string $name): ?UiGridActionInterface
    {
        $factory = self::$handlers[$name] ?? null;

        return $factory === null ? null : $factory();
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }
}

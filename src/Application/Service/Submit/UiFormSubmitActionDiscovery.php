<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;

/**
 * `#[AsFormSubmitAction]` classes → the name-keyed action map the registry
 * consults first. Checked at boot by reflection alone; each submit gets a
 * freshly resolved action. Every mistake fails the boot, not the first submit: a bad
 * or duplicate name, a class that is not an action, or a `name()` that
 * disagrees with its attribute.
 */
final class UiFormSubmitActionDiscovery
{
    /**
     * @param iterable<class-string> $classes
     * @param callable(class-string): object $resolve makes the action for one submit
     *        (production: the request scope, so its injections are the visitor's)
     * @return array<string, \Closure(): UiFormSubmitActionInterface>
     */
    public static function fromClasses(iterable $classes, callable $resolve): array
    {
        $actions = [];
        $declaredBy = [];
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(AsFormSubmitAction::class);
            if ($attributes === []) {
                continue;
            }
            $name = $attributes[0]->newInstance()->name;
            if (preg_match(UiFormSubmitActionInterface::NAME_PATTERN, $name) !== 1) {
                throw new \LogicException(sprintf('#[AsFormSubmitAction] on %s has an invalid name "%s".', $class, $name));
            }
            if (isset($declaredBy[$name])) {
                throw new \LogicException(sprintf('#[AsFormSubmitAction("%s")] is declared twice (%s and %s).', $name, $declaredBy[$name], $class));
            }
            if (!$reflection->implementsInterface(UiFormSubmitActionInterface::class)) {
                throw new \LogicException(sprintf('#[AsFormSubmitAction] class %s must implement %s.', $class, UiFormSubmitActionInterface::class));
            }
            // name() is a constant answer; asking a bare instance needs no
            // container — there is no request (no visitor to inject) at boot.
            /** @var UiFormSubmitActionInterface $bare */
            $bare = $reflection->newInstanceWithoutConstructor();
            if ($bare->name() !== $name) {
                throw new \LogicException(sprintf('%s::name() returns "%s" but its #[AsFormSubmitAction] says "%s".', $class, $bare->name(), $name));
            }
            $declaredBy[$name] = $class;
            $actions[$name] = static function () use ($resolve, $class): UiFormSubmitActionInterface {
                $action = $resolve($class);
                if (!$action instanceof UiFormSubmitActionInterface) {
                    throw new \LogicException(sprintf('%s did not resolve to a form submit action.', $class));
                }

                return $action;
            };
        }

        return $actions;
    }
}

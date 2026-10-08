<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Attribute\AsUiTreeAction;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;

/**
 * The kinds of action a UI tree may name (#[AsUiTreeAction]), found once per
 * worker; checks a tree's actions for the visitor and resolves what a prop
 * referring to one is drawn with.
 */
#[AsService]
final class UiTreeActions
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** @var array<string, class-string<UiTreeActionKindInterface>>|null kind => class, worker-lifetime */
    private ?array $kinds = null;

    /** @var array<string, UiTreeActionKindInterface> test seam: kind => handler */
    private array $handlers = [];

    /** @return list<string> the kinds a tree may name */
    public function kinds(): array
    {
        $kinds = array_keys($this->classes());
        sort($kinds);

        return $kinds;
    }

    /** One line per kind, for an agent. */
    public function describe(): string
    {
        $lines = [];
        foreach ($this->kinds() as $kind) {
            $handler = $this->kind($kind);
            if ($handler !== null) {
                $lines[] = '- ' . $handler->describe();
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<UiTreeError> */
    public function check(UiTree $tree): array
    {
        $errors = [];
        foreach ($tree->actions as $name => $action) {
            $path = '/actions/' . UiTreeParser::escape($name);
            $kind = $this->kind((string) ($action['kind'] ?? ''));
            if ($kind === null) {
                $errors[] = new UiTreeError('tree.action_kind', $path . '/kind', 'An action is one of the kinds this application runs.', implode(' | ', $this->kinds()), UiTreeError::describe($action['kind'] ?? null));
                continue;
            }
            array_push($errors, ...$kind->check($action, $path));
        }

        return $errors;
    }

    public function propValue(UiTree $tree, string $name): string
    {
        $action = $tree->actions[$name] ?? null;
        $kind = $action === null ? null : $this->kind((string) ($action['kind'] ?? ''));

        return $kind === null || $action === null ? '' : $kind->propValue($action);
    }

    private function kind(string $kind): ?UiTreeActionKindInterface
    {
        if (isset($this->handlers[$kind])) {
            return $this->handlers[$kind];
        }
        $class = $this->classes()[$kind] ?? null;
        if ($class === null) {
            return null;
        }
        $handler = RequestScopedContainer::forCurrentExecution($this->container)->get($class);

        return $handler instanceof UiTreeActionKindInterface ? $handler : null;
    }

    /** @return array<string, class-string<UiTreeActionKindInterface>> */
    private function classes(): array
    {
        if ($this->kinds === null) {
            $kinds = [];
            foreach ($this->classDiscovery->findClassesWithAttribute(AsUiTreeAction::class) as $class) {
                $attribute = (new \ReflectionClass($class))->getAttributes(AsUiTreeAction::class)[0] ?? null;
                if ($attribute === null || !is_subclass_of($class, UiTreeActionKindInterface::class)) {
                    continue;
                }
                $kind = $attribute->newInstance()->kind;
                if (isset($kinds[$kind])) {
                    throw new \LogicException(sprintf('Two classes claim the tree action kind "%s": %s and %s.', $kind, $kinds[$kind], $class));
                }
                $kinds[$kind] = $class;
            }
            $this->kinds = $kinds;
        }

        return $this->kinds;
    }

    /** Test seam: these kinds only, as given. @param array<string, UiTreeActionKindInterface> $handlers */
    public function useHandlers(array $handlers): void
    {
        $this->handlers = $handlers;
        $this->kinds = array_map(static fn (UiTreeActionKindInterface $h): string => $h::class, $handlers);
    }
}

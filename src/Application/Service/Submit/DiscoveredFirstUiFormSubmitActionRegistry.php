<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Actions registered with #[AsFormSubmitAction] first, then whatever registry
 * the application bound (the built-ins, or its own).
 *
 * A discovered action is a factory, called for each submit: the action comes
 * from the CURRENT request's scope, so what it injects (#[InjectAsMutable]
 * auth, session, tenant) belongs to the visitor submitting, not to whoever the
 * worker served first.
 */
final class DiscoveredFirstUiFormSubmitActionRegistry implements UiFormSubmitActionRegistryInterface
{
    /**
     * @param array<string, UiFormSubmitActionInterface|\Closure(): UiFormSubmitActionInterface> $discovered
     */
    public function __construct(
        private readonly array $discovered,
        private readonly UiFormSubmitActionRegistryInterface $fallback,
    ) {}

    public function resolve(string $actionName): UiFormSubmitActionInterface
    {
        $action = $this->discovered[$actionName] ?? null;
        if ($action === null) {
            return $this->fallback->resolve($actionName);
        }

        return $action instanceof \Closure ? $action() : $action;
    }

    public function knownActionNames(): array
    {
        return array_values(array_unique([...array_keys($this->discovered), ...$this->fallback->knownActionNames()]));
    }
}

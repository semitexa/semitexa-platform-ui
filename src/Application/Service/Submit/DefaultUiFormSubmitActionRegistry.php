<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoAcceptAction;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionException;

/**
 * Default action registry — knows one built-in:
 *
 *   - `platform.demo.accept`  → PlatformDemoAcceptAction
 *
 * Bound as the default UiFormSubmitActionRegistryInterface
 * implementation via SatisfiesServiceContract. Apps register their
 * own implementation in a module that "extends" semitexa-platform-ui:
 *
 *     #[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]
 *     final class AppFormSubmitActionRegistry implements UiFormSubmitActionRegistryInterface
 *     {
 *         private DefaultUiFormSubmitActionRegistry $builtins;
 *
 *         public function __construct()
 *         {
 *             $this->builtins = new DefaultUiFormSubmitActionRegistry();
 *         }
 *
 *         public function resolve(string $actionName): UiFormSubmitActionInterface
 *         {
 *             return match ($actionName) {
 *                 'app.signup.preview' => new SignupPreviewAction(),
 *                 default              => $this->builtins->resolve($actionName),
 *             };
 *         }
 *
 *         public function knownActionNames(): array
 *         {
 *             return [...$this->builtins->knownActionNames(), 'app.signup.preview'];
 *         }
 *     }
 *
 * Like the rule registry, resolution uses a fixed `match` expression
 * on the action name. The registry NEVER reflects a class FQCN out
 * of the action name. This is the security-perimeter contract every
 * custom registry MUST honour.
 */
#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]
final class DefaultUiFormSubmitActionRegistry implements UiFormSubmitActionRegistryInterface
{
    public function resolve(string $actionName): UiFormSubmitActionInterface
    {
        return match ($actionName) {
            PlatformDemoAcceptAction::NAME => new PlatformDemoAcceptAction(),
            default => throw new UiFormSubmitActionException(
                sprintf(
                    'Unknown form submit action "%s". Known actions: %s.',
                    $actionName,
                    implode(', ', $this->knownActionNames()),
                ),
                $actionName,
            ),
        };
    }

    /** @return list<string> */
    public function knownActionNames(): array
    {
        return [PlatformDemoAcceptAction::NAME];
    }
}

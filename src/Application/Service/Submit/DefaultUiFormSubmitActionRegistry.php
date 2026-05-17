<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoAcceptAction;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoStoreContactAction;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoStoreContactDbAction;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionException;

/**
 * Default action registry — knows the built-in demo actions:
 *
 *   - `platform.demo.accept`         → PlatformDemoAcceptAction
 *     (inert; returns an accepted message; no persistence)
 *   - `platform.demo.storeContact`   → PlatformDemoStoreContactAction
 *     (cache-backed demo persistence via
 *     {@see UiFormDemoSubmissionRepositoryInterface}; 24h TTL)
 *   - `platform.demo.storeContactDb` → PlatformDemoStoreContactDbAction
 *     (database-backed demo persistence via
 *     {@see UiFormDatabaseDemoSubmissionRepositoryInterface}; table
 *     `platform_ui_demo_submissions`, durable)
 *
 * The storage-capable actions need their respective repositories;
 * the registry pulls the active repo for each action from its
 * static holder (`UiFormDemoSubmissionRepository::getActive()`
 * for the cache action, `UiFormDatabaseDemoSubmissionRepository::getActive()`
 * for the DB action) lazily at resolve time. The registry itself
 * stays stateless and can be instantiated from the lazy-default
 * fallback path without any container access.
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
            PlatformDemoStoreContactAction::NAME => new PlatformDemoStoreContactAction(
                UiFormDemoSubmissionRepository::getActive(),
            ),
            PlatformDemoStoreContactDbAction::NAME => new PlatformDemoStoreContactDbAction(
                UiFormDatabaseDemoSubmissionRepository::getActive(),
            ),
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
        return [
            PlatformDemoAcceptAction::NAME,
            PlatformDemoStoreContactAction::NAME,
            PlatformDemoStoreContactDbAction::NAME,
        ];
    }
}

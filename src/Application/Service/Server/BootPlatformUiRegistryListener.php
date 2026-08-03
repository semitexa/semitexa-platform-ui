<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorCatalog;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorRegistry;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveCatalog;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiDemoSubmissionAdminAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiDemoSubmissionAdminAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistryInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStoreInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicyInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;

/**
 * ## Ordering contract
 *
 * `UiComponentRegistry`, `UiPrimitiveRegistry` and `UiBehaviorRegistry` each resolve
 * their catalog with a `??=` fallback that constructs an unwired instance on first
 * use. `setCatalog()` replaces that instance outright, so anything registered on a
 * fallback before this listener runs would be silently dropped.
 *
 * Verified 2026-08-03: no production code registers into the three facades outside
 * the catalogs themselves, so nothing is stranded today. A future boot-time writer
 * has to run after this listener — `WorkerStartAfterContainer` priority `-5`, i.e.
 * after semitexa-ssr's `WireCoreInstancesListener` at `-10` and before every
 * `WorkerStartFinalize` listener.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: -5,
    requiresContainer: true,
)]
final class BootPlatformUiRegistryListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected UiComponentCatalog $componentCatalog;

    #[InjectAsReadonly]
    protected UiPrimitiveCatalog $primitiveCatalog;

    #[InjectAsReadonly]
    protected UiBehaviorCatalog $behaviorCatalog;

    #[InjectAsReadonly]
    protected UiFieldRuleRegistryInterface $fieldRuleRegistry;

    #[InjectAsReadonly]
    protected UiFormSubmitActionRegistryInterface $formSubmitActionRegistry;

    #[InjectAsReadonly]
    protected UiFormSubmitActionAuthorizerInterface $formSubmitActionAuthorizer;

    #[InjectAsReadonly]
    protected UiFormSubmitSecurityPolicyInterface $formSubmitSecurityPolicy;

    #[InjectAsReadonly]
    protected UiFormSubmitCsrfTokenStoreInterface $formSubmitCsrfTokenStore;

    #[InjectAsReadonly]
    protected UiFormDemoSubmissionRepositoryInterface $formDemoSubmissionRepository;

    #[InjectAsReadonly]
    protected UiFormDatabaseDemoSubmissionRepositoryInterface $formDatabaseDemoSubmissionRepository;

    #[InjectAsReadonly]
    protected UiDemoSubmissionAdminAuthorizerInterface $demoSubmissionAdminAuthorizer;

    public function handle(ServerLifecycleContext $context): void
    {
        UiPrimitiveRegistry::setCatalog($this->primitiveCatalog);
        UiPrimitiveRegistry::initialize();

        // The catalog arrives fully wired by the container — no setter dance.
        UiComponentRegistry::setCatalog($this->componentCatalog);
        UiComponentRegistry::initialize();

        // Third tier: client-only behaviors (#[AsUiBehavior]). Same discovery
        // seam as primitives/components — dropping in an attributed class is the
        // whole registration step.
        UiBehaviorRegistry::setCatalog($this->behaviorCatalog);
        UiBehaviorRegistry::initialize();

        // Stash the container-bound rule registry so the
        // `ui_field_rules` Twig helper (instantiated via reflection
        // by TwigExtensionRegistry, NOT through DI) and the
        // UiInteractionDispatcher's `UsesUiFieldRuleRegistry` bridge
        // can both read the same authoritative registry without
        // needing direct container access.
        UiFieldRuleRegistry::setActive($this->fieldRuleRegistry);

        // Same pattern for the form submit action registry — the
        // FormComponent template's `ui_form_submit_action_name`
        // helper and FormComponent::onSubmit() both read from the
        // static holder.
        UiFormSubmitActionRegistry::setActive($this->formSubmitActionRegistry);

        // FormComponent submit action authorizer + security policy —
        // both stashed in their own static holders so the submit
        // handler can reach them without container access. The
        // authorizer runs after authoritative validation and before
        // the security policy; the security policy runs after the
        // authorizer and before the action's handle().
        UiFormSubmitActionAuthorizer::setActive($this->formSubmitActionAuthorizer);
        UiFormSubmitSecurityPolicy::setActive($this->formSubmitSecurityPolicy);

        // CSRF token store: the `ui_form_issue_submit_csrf` Twig
        // helper (reflection-instantiated) and
        // CacheBackedUiFormSubmitSecurityPolicy both read from the
        // static holder so the same container-bound winner backs
        // render-time issue() and dispatch-time consume() inside one
        // worker.
        UiFormSubmitCsrfTokenStore::setActive($this->formSubmitCsrfTokenStore);

        // Demo submission repository — the
        // PlatformDemoStoreContactAction (constructed lazily by
        // DefaultUiFormSubmitActionRegistry::resolve()) reads the
        // active repository from the static holder. Cache-backed in
        // production via SatisfiesServiceContract; in-memory
        // fallback in tests / single-worker dev.
        UiFormDemoSubmissionRepository::setActive($this->formDemoSubmissionRepository);

        // Database-backed demo submission repository — the
        // PlatformDemoStoreContactDbAction reads the active repo
        // through its dedicated holder so the cache-backed
        // `platform.demo.storeContact` action is not silently
        // re-targeted at the database.
        UiFormDatabaseDemoSubmissionRepository::setActive($this->formDatabaseDemoSubmissionRepository);

        // Read-only diagnostic-listing authorizer — the
        // `/ui-playground/admin/demo-submissions` handler reads the
        // active authorizer through its dedicated static holder.
        // Default impl denies unless PLATFORM_UI_DEMO_ADMIN_ENABLED
        // is truthy; apps may bind their own via SatisfiesServiceContract.
        UiDemoSubmissionAdminAuthorizer::setActive($this->demoSubmissionAdminAuthorizer);
    }
}

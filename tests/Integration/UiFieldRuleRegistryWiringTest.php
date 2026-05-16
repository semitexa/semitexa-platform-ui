<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiInteractionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;

/**
 * End-to-end coverage for the container-bound rule registry wiring:
 *
 *   - UiFieldRuleRegistry static holder is set and read correctly.
 *   - ui_field_rules() Twig helper path uses the active registry
 *     (modelled here through UiFieldRuleParser, which the helper
 *     instantiates with the active registry).
 *   - UiInteractionDispatcher's UsesUiFieldRuleRegistry bridge
 *     hands the active registry to FieldComponent before
 *     onInputChanged() runs.
 *   - Custom slug rule survives a full render→sign→dispatch
 *     round-trip when the active registry is swapped.
 *   - Default behaviour is unchanged when no custom registry is set.
 */
final class UiFieldRuleRegistryWiringTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    private int $dispatchSeq = 0;

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-rule-wiring-test');
        putenv('APP_ENV=dev');

        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FieldComponent::class),
        );
        UiFieldRuleRegistry::reset();
        $this->dispatchSeq = 0;
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
        UiFieldRuleRegistry::reset();
        if ($this->previousSecret === null) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET=' . $this->previousSecret);
        }
        if ($this->previousEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }

    private function freshDispatchId(): string
    {
        $this->dispatchSeq++;
        return sprintf('ui_evt_%032s', dechex(($this->dispatchSeq << 16) | random_int(0, 0xFFFF)));
    }

    /**
     * Build the same kind of signed ctx the FieldComponent template
     * mints — including `cfg.r` populated by the active registry.
     *
     * @param array<int, mixed> $rawRules
     */
    private function ctxWithRules(array $rawRules, string $instance = 'uci_wiring_test_01'): string
    {
        $metadata = UiComponentRegistry::get('platform.field');
        $wire = $rawRules === []
            ? []
            : (new UiFieldRuleParser(UiFieldRuleRegistry::getActive()))->parseAllToWire($rawRules);
        $manifest = (new UiEventManifestBuilder())->build(
            metadata:    $metadata,
            instanceId:  $instance,
            ttlSeconds:  300,
            eventConfig: $wire === [] ? [] : ['input.change' => ['r' => $wire]],
        );
        return $manifest->entries[0]->signedContext;
    }

    private function newDispatcher(?UiFieldRuleRegistryInterface $registry = null): UiInteractionDispatcher
    {
        return new UiInteractionDispatcher(
            payloadGuard:   new UiPayloadFieldGuard(),
            patchValidator: new UiPatchValidator(),
            replayStore:    new InMemoryUiReplayStore(),
            authorizer:     new AllowAllUiInteractionAuthorizer(),
            productionLike: false,
            ruleRegistry:   $registry,
        );
    }

    private function validationMessage(UiInteractionResult $result): string
    {
        // Validation message lives in the third patch
        // (aria-invalid, ui-state, validation-message, server-ack).
        $patch = $result->patches[2];
        self::assertIsString($patch->value);
        return $patch->value;
    }

    // -------------------------------------------------------------
    //  ui_field_rules() helper path (modelled through the parser
    //  it instantiates).
    // -------------------------------------------------------------

    #[Test]
    public function helper_path_uses_default_registry_for_built_ins(): void
    {
        // Default registry (no setActive call): built-ins accepted.
        $wire = (new UiFieldRuleParser(UiFieldRuleRegistry::getActive()))
            ->parseAllToWire(['required', ['minLength', 3]]);
        self::assertSame([
            ['n' => 'required'],
            ['n' => 'minLength', 'p' => [3]],
        ], $wire);
    }

    #[Test]
    public function helper_path_rejects_slug_under_default_registry(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/Unknown rule "slug"/');
        (new UiFieldRuleParser(UiFieldRuleRegistry::getActive()))
            ->parseAllToWire(['slug']);
    }

    #[Test]
    public function helper_path_accepts_slug_under_custom_registry(): void
    {
        UiFieldRuleRegistry::setActive(new RuleWiringSlugRegistry());
        $wire = (new UiFieldRuleParser(UiFieldRuleRegistry::getActive()))
            ->parseAllToWire(['required', 'slug']);
        self::assertSame([
            ['n' => 'required'],
            ['n' => 'slug'],
        ], $wire);
    }

    #[Test]
    public function helper_path_compact_wire_shape_unchanged_for_built_ins(): void
    {
        // Pin the wire shape (regression — must not drift even after
        // the registry was rewired through DI).
        UiFieldRuleRegistry::setActive(new RuleWiringSlugRegistry());
        $wire = (new UiFieldRuleParser(UiFieldRuleRegistry::getActive()))
            ->parseAllToWire([['minLength', 5], ['maxLength', 30]]);
        self::assertSame([
            ['n' => 'minLength', 'p' => [5]],
            ['n' => 'maxLength', 'p' => [30]],
        ], $wire);
    }

    // -------------------------------------------------------------
    //  FieldComponent dispatch-time bridge.
    // -------------------------------------------------------------

    #[Test]
    public function field_component_uses_default_registry_when_none_provided(): void
    {
        // No custom registry, no bridge call. Standalone FieldComponent
        // resolves built-ins through the static holder's lazy-default.
        $component = new FieldComponent();
        $result = $component->validate('ab');
        self::assertFalse($result->isValid());
        self::assertSame('Please enter at least 3 characters.', $result->message);
    }

    #[Test]
    public function dispatcher_calls_uses_field_rule_registry_bridge(): void
    {
        // Pin: a component implementing UsesUiFieldRuleRegistry gets
        // the dispatcher's active registry handed to it BEFORE the
        // handler method runs. We rely on the side effect — the
        // dispatcher resolves a slug rule via the custom registry.
        $custom = new RuleWiringSlugRegistry();
        $ctx = $this->ctxForRulesWithRegistry($custom, ['required', 'slug']);

        $dispatcher = $this->newDispatcher($custom);
        $result = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'Bad Slug']);
        self::assertSame('Please enter a valid slug.', $this->validationMessage($result));
        // ui-state patch flips to invalid → side-effect proof that
        // the slug rule fired through the registry bridge.
        self::assertSame('invalid', $result->patches[1]->value);
    }

    #[Test]
    public function dispatcher_falls_back_to_active_registry_when_constructor_arg_omitted(): void
    {
        // The dispatcher's ruleRegistry ctor arg defaults to null —
        // in that case the bridge reaches into the UiFieldRuleRegistry
        // static holder (matching the production path where the boot
        // listener has already called setActive()).
        UiFieldRuleRegistry::setActive(new RuleWiringSlugRegistry());
        $ctx = $this->ctxWithRules(['required', 'slug']);

        // NOTE: no ruleRegistry passed to the dispatcher constructor.
        $dispatcher = $this->newDispatcher();
        $result = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'Bad Slug']);
        self::assertSame('Please enter a valid slug.', $this->validationMessage($result));
    }

    #[Test]
    public function dispatch_with_valid_slug_returns_valid_result(): void
    {
        $custom = new RuleWiringSlugRegistry();
        $ctx = $this->ctxForRulesWithRegistry($custom, ['required', 'slug']);
        $dispatcher = $this->newDispatcher($custom);

        $result = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'platform-ui-2026']);
        self::assertSame('Looks good.', $this->validationMessage($result));
        self::assertSame('valid', $result->patches[1]->value);
    }

    #[Test]
    public function default_dispatcher_rejects_signed_slug_when_registry_does_not_know_it(): void
    {
        // Build the ctx with a custom registry (so it CAN sign a slug
        // rule), then dispatch through a dispatcher wired with the
        // DEFAULT registry. The handler must surface 422
        // invalid_validation_rule because the active registry rejects
        // the slug rule name at resolve time.
        $custom = new RuleWiringSlugRegistry();
        $ctx = $this->ctxForRulesWithRegistry($custom, ['slug']);
        $dispatcher = $this->newDispatcher(new DefaultUiFieldRuleRegistry());

        $this->expectException(\Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException::class);
        try {
            $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'hello-world']);
        } catch (\Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException $e) {
            self::assertSame('invalid_validation_rule', $e->reason);
            // Safe message — no class FQCN / file path.
            self::assertStringNotContainsString('Semitexa\\', $e->getMessage());
            self::assertStringNotContainsString('SlugRule', $e->getMessage());
            throw $e;
        }
    }

    // -------------------------------------------------------------
    //  Regression — existing built-in dispatch + replay paths.
    // -------------------------------------------------------------

    #[Test]
    public function existing_built_in_dispatch_unchanged(): void
    {
        // Default registry (no setActive call). The "validates fine
        // with the built-ins via signed cfg.r" path must continue to
        // produce the same diagnostics the previous slice shipped.
        $ctx = $this->ctxWithRules(['required', ['minLength', 3], ['maxLength', 20]]);
        $dispatcher = $this->newDispatcher();

        $r = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => '']);
        self::assertSame('This field is required.', $this->validationMessage($r));

        $r = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'ab']);
        self::assertSame('Please enter at least 3 characters.', $this->validationMessage($r));

        $r = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'this-is-way-too-long-for-the-rule']);
        self::assertSame('Please enter no more than 20 characters.', $this->validationMessage($r));

        $r = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'taras']);
        self::assertSame('Looks good.', $this->validationMessage($r));
    }

    #[Test]
    public function payload_rules_remains_rejected(): void
    {
        $ctx = $this->ctxWithRules(['required']);
        $dispatcher = $this->newDispatcher();
        $this->expectException(\Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException::class);
        $dispatcher->dispatch($ctx, $this->freshDispatchId(), [
            'value' => 'ab',
            'rules' => [['minLength', 1]],
        ]);
    }

    /**
     * Build a ctx whose cfg.r was signed using the given registry —
     * lets a test mint a token containing rule names the default
     * registry does NOT know.
     *
     * @param array<int, mixed> $rawRules
     */
    private function ctxForRulesWithRegistry(UiFieldRuleRegistryInterface $registry, array $rawRules): string
    {
        $metadata = UiComponentRegistry::get('platform.field');
        $wire = $rawRules === []
            ? []
            : (new UiFieldRuleParser($registry))->parseAllToWire($rawRules);
        $manifest = (new UiEventManifestBuilder())->build(
            metadata:    $metadata,
            instanceId:  'uci_wiring_test_01',
            ttlSeconds:  300,
            eventConfig: $wire === [] ? [] : ['input.change' => ['r' => $wire]],
        );
        return $manifest->entries[0]->signedContext;
    }
}

/**
 * Test fixture: slug rule + registry composing the built-ins. Same
 * design as CustomRuleRegistryFixtureTest but distinct names so the
 * two test files don't collide if PHPUnit runs them in the same
 * process.
 */
final class RuleWiringSlugRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'slug';
    public const PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';
    public const MESSAGE = 'Please enter a valid slug.';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $stringValue = is_scalar($value) ? (string) $value : '';
        $trimmed = trim($stringValue);
        if ($trimmed === '') {
            return null;
        }
        return preg_match(self::PATTERN, $trimmed) === 1
            ? null
            : UiFieldValidationResult::invalid(self::MESSAGE);
    }
}

final class RuleWiringSlugRegistry implements UiFieldRuleRegistryInterface
{
    private DefaultUiFieldRuleRegistry $builtins;

    public function __construct()
    {
        $this->builtins = new DefaultUiFieldRuleRegistry();
    }

    public function resolve(UiFieldRuleSpec $spec): UiFieldValidationRuleInterface
    {
        if ($spec->name === RuleWiringSlugRule::NAME) {
            if ($spec->params !== []) {
                throw new UiFieldValidationRuleException(
                    'Rule "slug" takes no parameters.',
                    $spec->name,
                );
            }
            return new RuleWiringSlugRule();
        }
        return $this->builtins->resolve($spec);
    }

    /** @return list<string> */
    public function knownRuleNames(): array
    {
        return [...$this->builtins->knownRuleNames(), RuleWiringSlugRule::NAME];
    }
}

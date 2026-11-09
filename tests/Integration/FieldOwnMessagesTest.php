<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\FormComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\FormRootPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\CacheBackedUiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Twig\PlatformUiTwigExtension;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitConfigParser;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Tests\Support\HugDispatch;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * A field says its errors in the app's words: `messages: {rule: text}` goes
 * into each rule's signed spec and replaces the built-in English text, on
 * the live check and on submit. A live run (2026-11-07) had no way to show
 * "Обов'язкове поле" and wrote its own rule registry for it.
 */
final class FieldOwnMessagesTest extends TestCase
{
    private const REQUIRED_UK = "Обов'язкове поле";
    private const SHORT_UK = 'Щонайменше 3 символи';

    private string|false $previousSecret = false;
    private string|false $previousEnv = false;

    /** @var array<string, mixed>|null the Twig functions as they were, when a test registered its own */
    private ?array $previousFunctions = null;

    private ?\Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface $previousRegistry = null;

    protected function setUp(): void
    {
        $this->previousSecret = getenv('APP_SECRET');
        $this->previousEnv = getenv('APP_ENV');
        putenv('APP_SECRET=platform-ui-field-messages-test');
        putenv('APP_ENV=dev');
        // getActive() would create one; read what is there, null included.
        $this->previousRegistry = (new \ReflectionProperty(UiFieldRuleRegistry::class, 'active'))->getValue();
    }

    protected function tearDown(): void
    {
        // Leave the Twig catalog and the active rule registry as the next test expects them.
        if ($this->previousFunctions !== null) {
            (new \ReflectionProperty($this->catalog(), 'functions'))->setValue($this->catalog(), $this->previousFunctions);
            $this->previousFunctions = null;
        }
        UiFieldRuleRegistry::setActive($this->previousRegistry);
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiFormSubmitSecurityPolicy::reset();
        UiFormSubmitCsrfTokenStore::reset();
        putenv($this->previousSecret === false ? 'APP_SECRET' : 'APP_SECRET=' . $this->previousSecret);
        putenv($this->previousEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousEnv);
    }

    #[Test]
    public function the_template_function_signs_each_rule_with_the_fields_own_message(): void
    {
        $rules = $this->registeredUiFieldRules();

        self::assertSame(
            [['n' => 'required', 'm' => self::REQUIRED_UK], ['n' => 'minLength', 'p' => [3]]],
            $rules(['required', ['minLength', 3]], ['required' => self::REQUIRED_UK]),
        );
        self::assertSame([['n' => 'required']], $rules(['required']), 'no messages: the spec is as before');
    }

    #[Test]
    public function a_message_for_a_rule_the_field_does_not_have_is_refused(): void
    {
        $wire = [['n' => 'required']];

        foreach ([
            [['requierd' => 'x'], 'names rule "requierd", which the field does not have (rules: required)'],
            [[self::REQUIRED_UK], 'must map rule names to messages'],
            [['required' => ' '], 'must be a non-empty string'],
            [['required' => str_repeat('я', UiFieldRuleParser::MAX_MESSAGE_LENGTH + 1)], 'exceeds 500 characters'],
        ] as [$messages, $expected]) {
            try {
                UiFieldRuleParser::withMessages($wire, $messages);
                self::fail('refused: ' . $expected);
            } catch (UiFieldValidationRuleException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
        // `valid` is not a rule, and "" means "say nothing when it passes".
        self::assertSame($wire, UiFieldRuleParser::withMessages($wire, ['valid' => '']));
    }

    #[Test]
    public function every_message_is_checked_even_on_a_field_without_rules_and_together_they_stay_small(): void
    {
        $rules = $this->registeredUiFieldRules();

        foreach ([
            [['valid' => ['not', 'text']], 'must be a non-empty string'],
            [['valid' => str_repeat('я', UiFieldRuleParser::MAX_MESSAGE_LENGTH + 1)], 'exceeds 500 characters'],
            [['required' => self::REQUIRED_UK], 'which the field does not have (rules: none)'],
        ] as [$messages, $expected]) {
            try {
                $rules([], $messages);
                self::fail('refused on a field without rules: ' . $expected);
            } catch (UiFieldValidationRuleException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }

        // Five rules at the per-message maximum: ~5 KB of Cyrillic in the signed ctx, twice.
        $wire = array_map(static fn (string $n): array => ['n' => $n], ['required', 'minLength', 'maxLength', 'email', 'url']);
        $long = array_fill_keys(['required', 'minLength', 'maxLength', 'email', 'url'], str_repeat('я', UiFieldRuleParser::MAX_MESSAGE_LENGTH));
        $this->expectExceptionMessage('exceed 2048 bytes together');
        UiFieldRuleParser::withMessages($wire, $long);
    }

    #[Test]
    public function a_field_without_rules_still_says_its_own_success_text(): void
    {
        self::assertSame('Збережемо', $this->liveMessage('on', [], ['vm' => 'Збережемо']));
    }

    #[Test]
    public function a_success_text_on_a_forms_field_entry_is_refused_as_never_shown(): void
    {
        $this->expectExceptionMessage('is never shown: a submit clears the success text');
        (new UiFormSubmitConfigParser(new UiFieldRuleParser(new DefaultUiFieldRuleRegistry())))
            ->parse([['name' => 'name', 'rules' => ['required'], 'messages' => ['valid' => 'OK']]]);
    }

    #[Test]
    public function the_live_check_shows_the_fields_own_messages(): void
    {
        $rules = [['n' => 'required', 'm' => self::REQUIRED_UK], ['n' => 'minLength', 'p' => [3], 'm' => self::SHORT_UK]];

        self::assertSame(self::REQUIRED_UK, $this->liveMessage('', $rules));
        self::assertSame(self::SHORT_UK, $this->liveMessage('ab', $rules));
        // A rule with no message of its own keeps the built-in text.
        self::assertSame('Please enter at least 3 characters.', $this->liveMessage('ab', [['n' => 'minLength', 'p' => [3]]]));
        // Passing: the field's own success text, "" clears the slot (an earlier
        // error must not stay next to a valid value), the default otherwise.
        self::assertSame('Готово', $this->liveMessage('abc', $rules, ['vm' => 'Готово']));
        self::assertSame('', $this->liveMessage('abc', $rules, ['vm' => '']));
        self::assertSame('Looks good.', $this->liveMessage('abc', $rules));
    }

    #[Test]
    public function a_submit_shows_the_fields_own_message_through_the_dispatcher(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiPrimitiveRegistry::register((new UiPrimitiveMetadataFactory())->fromClass(FormRootPrimitive::class));
        UiComponentRegistry::register((new UiComponentMetadataFactory())->fromClass(FormComponent::class));
        UiFormSubmitSecurityPolicy::setActive(new CacheBackedUiFormSubmitSecurityPolicy());
        UiFormSubmitCsrfTokenStore::setActive(new InMemoryUiFormSubmitCsrfTokenStore());
        // The developer-facing `fields:` prop, parsed as the form template does.
        $fields = (new UiFormSubmitConfigParser(new UiFieldRuleParser(new DefaultUiFieldRuleRegistry())))
            ->parse([['name' => 'name', 'rules' => ['required'], 'messages' => ['required' => self::REQUIRED_UK], 'instanceId' => 'uci_field_messages_name']])
            ->toWireShape();
        $handle = UiFormSubmitCsrfTokenStore::getActive()->issue(600);
        $ctx = SignedContext::sign([
            'c' => 'platform.form', 'i' => 'uci_field_messages_form', 'p' => 'form', 'e' => 'submit',
            'cfg' => ['f' => $fields, 's' => ['k' => $handle->id, 't' => $handle->raw]],
        ]);

        $response = (new HugDispatch())->sendLegacy(json_encode([
            'ctx' => $ctx,
            'dispatchId' => 'ui_evt_' . bin2hex(random_bytes(16)),
            'payload' => ['form' => ['values' => ['name' => '']]],
        ], JSON_THROW_ON_ERROR));
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $texts = array_column(array_filter($body['patches'] ?? [], static fn (array $p): bool => ($p['target']['instance'] ?? null) === 'uci_field_messages_name' && $p['op'] === 'setText'), 'value');
        self::assertContains(self::REQUIRED_UK, $texts);
        self::assertNotContains('This field is required.', $texts);
    }

    /** The real ui_field_rules, as PlatformUiTwigExtension registers it; the catalog is put back in tearDown(). */
    private function registeredUiFieldRules(): callable
    {
        $property = new \ReflectionProperty($this->catalog(), 'functions');
        $this->previousFunctions ??= $property->getValue($this->catalog());
        (new PlatformUiTwigExtension())->registerFunctions();

        // Read back as registered; getFunctions() would boot class discovery.
        return $property->getValue($this->catalog())['ui_field_rules']['callback'];
    }

    private function catalog(): object
    {
        return (new \ReflectionMethod(TwigExtensionRegistry::class, 'catalog'))->invoke(null);
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed> $config
     */
    private function liveMessage(string $value, array $rules, array $config = []): string
    {
        $event = new UiInteractionEvent(
            componentName: 'platform.field', instanceId: 'uci_field_messages_01', partName: 'input', eventName: 'change',
            updatesPath: null, payload: ['value' => $value], issuedAt: time(), expiresAt: time() + 60,
            claims: [], dispatchId: 'ui_evt_field_messages', config: ['r' => $rules, 'fn' => 'name'] + $config,
        );
        foreach ((new FieldComponent())->onInputChanged($event)->patches as $patch) {
            if ($patch->targetName === 'validation-message') {
                return (string) $patch->value;
            }
        }
        self::fail('no validation-message patch');
    }
}

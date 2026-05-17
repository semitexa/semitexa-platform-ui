<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\FormComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Component\UiPartPropResolver;
use Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitConfigParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitDefinitionExtractor;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionException;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Renders the FormComponent template through a minimal Twig environment.
 * Mirrors FieldComponentRenderTest's harness — same loader path, same
 * slot/primitive helper shims — so the form template runs through the
 * same code paths the live runtime uses.
 *
 * The form template only needs `primitive()`, `slot()`, and
 * `ui_component_instance()`. No event manifest, no part resolution.
 */
final class FormComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        // Pin a stable APP_SECRET / APP_ENV so SignedContext::sign
        // (called by ui_event_manifest under the hood) succeeds.
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-form-render-test');
        putenv('APP_ENV=dev');

        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        AssetCollectorStore::reset();

        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(ButtonPrimitive::class),
        );
        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(InputPrimitive::class),
        );
        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(\Semitexa\PlatformUi\Application\Service\Primitive\Builtin\FormRootPrimitive::class),
        );
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FormComponent::class),
        );
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FieldComponent::class),
        );

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->twig = new TwigEnvironment($loader, [
            'cache' => false,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        $renderer = new PrimitiveRenderer($this->twig);
        $this->twig->addFunction(new TwigFunction(
            'primitive',
            static fn (string $name, array $props = []): Markup =>
                new Markup($renderer->render($name, $props), 'UTF-8'),
            ['is_safe' => ['html']],
        ));

        $this->twig->addFunction(new TwigFunction(
            'slot',
            static function (array $context, string $name): Markup {
                $slots = $context['_slots'] ?? [];
                $value = is_array($slots) ? ($slots[$name] ?? null) : null;
                return new Markup($value === null ? '' : (string) $value, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_component_instance',
            static fn (): string => 'uci_' . bin2hex(random_bytes(8)),
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_component_instance_for',
            static function (mixed $override = null): string {
                if ($override === null) {
                    return 'uci_' . bin2hex(random_bytes(8));
                }
                if (!\Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator::isSafe($override)) {
                    throw new \Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException(
                        'override must match safe instance-id shape',
                    );
                }
                /** @var string $override */
                return $override;
            },
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_form_submit_fields',
            static function (array $rawFields): array {
                if ($rawFields === []) {
                    return [];
                }
                return (new UiFormSubmitConfigParser())
                    ->parse($rawFields)
                    ->toWireShape();
            },
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_form_field_submit_marker',
            static function (array $def): Markup {
                $name = $def['n'] ?? null;
                if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $name) !== 1) {
                    return new Markup('', 'UTF-8');
                }
                $instanceId = $def['i'] ?? null;
                if (!is_string($instanceId) || !UiInstanceIdGenerator::isSafe($instanceId)) {
                    return new Markup('', 'UTF-8');
                }
                $rules = is_array($def['r'] ?? null) ? $def['r'] : [];
                $payload = ['n' => $name, 'i' => $instanceId, 'r' => $rules];
                $rawLabel = $def['l'] ?? null;
                if (is_string($rawLabel) && $rawLabel !== '') {
                    $payload['l'] = $rawLabel;
                }
                if (!empty($def['q'])) {
                    $payload['q'] = true;
                }
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $json = str_replace('</', '<\\/', $json);
                return new Markup(
                    '<script type="application/json" data-ui-field-submit-definition>' . $json . '</script>',
                    'UTF-8',
                );
            },
            ['is_safe' => ['html']],
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_form_resolve_submit_fields',
            static function (string $slotHtml, bool $auto, $manual = null): array {
                $manualList = is_array($manual) ? $manual : [];
                if ($auto) {
                    if ($manualList !== []) {
                        throw new UiComponentRegistryException(
                            'FormComponent: autoFields=true is mutually exclusive with the `fields` prop.',
                        );
                    }
                    return (new UiFormSubmitDefinitionExtractor())->extract($slotHtml)['config']->toWireShape();
                }
                if ($manualList === []) {
                    return [];
                }
                return (new UiFormSubmitConfigParser())->parse($manualList)->toWireShape();
            },
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_form_strip_submit_markers',
            static function (string $html): string {
                return (new UiFormSubmitDefinitionExtractor())->stripMarkers($html);
            },
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_form_issue_submit_csrf',
            static function (?string $actionName = null, ?int $ttlSeconds = null): ?array {
                if ($actionName === null || $actionName === '') {
                    return null;
                }
                $ttl = $ttlSeconds ?? 600;
                $handle = UiFormSubmitCsrfTokenStore::getActive()->issue($ttl);
                return ['k' => $handle->id, 't' => $handle->raw];
            },
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_form_resolve_submit_action',
            static function (mixed $name = null): ?string {
                if ($name === null || $name === '') {
                    return null;
                }
                if (!is_string($name)) {
                    throw new UiFormSubmitActionException('FormComponent `submitAction` prop must be a string.');
                }
                if (preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,127}\z/', $name) !== 1) {
                    throw new UiFormSubmitActionException(
                        'FormComponent `submitAction` must match [A-Za-z_][A-Za-z0-9_.-]{0,127}.',
                        $name,
                    );
                }
                UiFormSubmitActionRegistry::getActive()->resolve($name);
                return $name;
            },
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_field_rules',
            static function (array $rawRules): array {
                if ($rawRules === []) {
                    return [];
                }
                return (new \Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser(
                    new \Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry(),
                ))->parseAllToWire($rawRules);
            },
        ));

        // FieldComponent rendering for the autoFields tests below: same
        // shape as FieldComponentRenderTest, condensed inline.
        $resolver = new UiPartPropResolver();
        $this->twig->addFunction(new TwigFunction(
            'ui_part',
            static function (array $context, string $partName, array $overrides = []) use ($resolver, $renderer): Markup {
                $component = $context['_component'] ?? null;
                $metadata = is_array($component) && isset($component['name']) && is_string($component['name'])
                    ? UiComponentRegistry::get($component['name'])
                    : null;
                if ($metadata === null) {
                    return new Markup('', 'UTF-8');
                }
                $part = $metadata->part($partName);
                if ($part === null) {
                    return new Markup('', 'UTF-8');
                }
                $componentProps = [];
                foreach ($context as $key => $value) {
                    if (is_string($key) && $key !== '' && $key[0] !== '_') {
                        $componentProps[$key] = $value;
                    }
                }
                $resolved = $resolver->resolve($metadata, $partName, $componentProps, $overrides);
                $html = $renderer->render($part->primitiveName, $resolved);
                $markerAttr = sprintf(
                    'data-ui-part="%s"',
                    htmlspecialchars($partName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
                $count = 0;
                $injected = preg_replace(
                    '/^(\s*<[a-zA-Z][a-zA-Z0-9-]*)(?=\s|>|\/>)/',
                    '$1 ' . str_replace('\\', '\\\\', str_replace('$', '\\$', $markerAttr)),
                    $html,
                    1,
                    $count,
                );
                return new Markup($injected ?? $html, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));

        // SSR's `component('platform.field', props, slots)` shim. The
        // production helper resolves the template via SSR's
        // ComponentRenderer; the harness keeps a tight name→template
        // map so autoFields can see real FieldComponent renders without
        // booting the full SSR stack.
        $templateByName = [
            'platform.field' => '@platform-ui/components/runtime/field.html.twig',
            'platform.form'  => '@platform-ui/components/runtime/form.html.twig',
        ];
        $classByName = [
            'platform.field' => FieldComponent::class,
            'platform.form'  => FormComponent::class,
        ];
        $twig = &$this->twig;
        $this->twig->addFunction(new TwigFunction(
            'component',
            static function (string $name, array $props = [], array $slots = []) use (&$twig, $templateByName, $classByName): Markup {
                if (!isset($templateByName[$name])) {
                    return new Markup('', 'UTF-8');
                }
                $html = $twig->render($templateByName[$name], array_merge($props, [
                    '_component' => ['name' => $name, 'class' => $classByName[$name]],
                    '_slots' => $slots,
                ]));
                return new Markup($html, 'UTF-8');
            },
            ['is_safe' => ['html']],
        ));

        $manifestBuilder = new \Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder();
        $this->twig->addFunction(new TwigFunction(
            'ui_event_manifest',
            static function (array $context, string $instanceId, ?int $ttlSeconds = null, array $eventConfig = []) use ($manifestBuilder): Markup {
                $component = $context['_component'] ?? null;
                $name = is_array($component) ? ($component['name'] ?? null) : null;
                $metadata = is_string($name) ? UiComponentRegistry::get($name) : null;
                if ($metadata === null) {
                    return new Markup('', 'UTF-8');
                }
                $manifest = $manifestBuilder->build($metadata, $instanceId, $ttlSeconds, $eventConfig);
                $json = json_encode(
                    $manifest->toJsonShape(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
                $json = str_replace('</', '<\\/', $json);
                $iattr = htmlspecialchars($instanceId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cattr = htmlspecialchars($manifest->componentName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return new Markup(
                    sprintf(
                        '<script type="application/json" data-ui-event-manifest="%s" data-ui-component="%s">%s</script>',
                        $iattr,
                        $cattr,
                        $json,
                    ),
                    'UTF-8',
                );
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        AssetCollectorStore::reset();
        UiFormSubmitCsrfTokenStore::reset();
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

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $slots
     */
    private function renderForm(array $props = [], array $slots = []): string
    {
        return $this->twig->render(
            '@platform-ui/components/runtime/form.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.form', 'class' => FormComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function form_component_is_registered_in_ui_component_registry(): void
    {
        $metadata = UiComponentRegistry::get('platform.form');
        self::assertNotNull($metadata);
        self::assertSame(FormComponent::class, $metadata->class);
    }

    #[Test]
    public function form_root_carries_canonical_markers(): void
    {
        $html = $this->renderForm(['title' => 'Sign-up']);

        self::assertStringContainsString('data-ui-component="platform.form"', $html);
        self::assertStringContainsString('data-ui-form-aggregate="1"', $html);
        self::assertStringContainsString('ui-component="form"', $html);
        // The Platform UI instance id is the canonical id the existing
        // patch applier resolves — no separate ufi_* id is introduced.
        self::assertMatchesRegularExpression(
            '/data-ui-component-instance-id="uci_[a-f0-9]{16}"/',
            $html,
        );
    }

    #[Test]
    public function form_renders_title_and_description_when_provided(): void
    {
        $html = $this->renderForm([
            'title' => 'Sign-up details',
            'description' => 'Server-validated only.',
        ]);

        self::assertStringContainsString('Sign-up details', $html);
        self::assertStringContainsString('Server-validated only.', $html);
    }

    #[Test]
    public function form_omits_title_block_when_not_provided(): void
    {
        $html = $this->renderForm();

        self::assertStringNotContainsString('<h2', $html);
    }

    #[Test]
    public function form_renders_form_status_target_by_default(): void
    {
        $html = $this->renderForm();

        self::assertStringContainsString('data-ui-patch-target="form-status"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('No fields validated yet.', $html);
    }

    #[Test]
    public function form_status_message_is_customisable(): void
    {
        $html = $this->renderForm([
            'statusInitialMessage' => 'Type into a field to begin.',
        ]);

        self::assertStringContainsString('Type into a field to begin.', $html);
        self::assertStringNotContainsString('No fields validated yet.', $html);
    }

    #[Test]
    public function form_status_can_be_disabled(): void
    {
        $html = $this->renderForm(['showStatus' => false]);

        self::assertStringNotContainsString('data-ui-patch-target="form-status"', $html);
    }

    #[Test]
    public function form_omits_submit_block_by_default(): void
    {
        $html = $this->renderForm();

        self::assertStringNotContainsString('data-ui-form-submit-row', $html);
    }

    #[Test]
    public function form_renders_optional_submit_button(): void
    {
        $html = $this->renderForm([
            'showSubmit' => true,
            'submitText' => 'Create account',
        ]);

        self::assertStringContainsString('data-ui-form-submit-row', $html);
        self::assertStringContainsString('Create account', $html);
        // The button is visual only — no disabled patch-allow-list
        // expansion in this slice.
        self::assertStringContainsString('data-ui-primitive="platform.button"', $html);
    }

    #[Test]
    public function form_content_slot_renders_caller_provided_markup(): void
    {
        $html = $this->renderForm(
            [],
            [
                'content' => '<div data-test="caller-injected">field-1</div><div data-test="caller-injected-2">field-2</div>',
            ],
        );

        self::assertStringContainsString('data-test="caller-injected"', $html);
        self::assertStringContainsString('field-1', $html);
        self::assertStringContainsString('field-2', $html);

        // Fields render inside the canonical fields wrapper.
        self::assertStringContainsString('data-ui-form-fields', $html);
        $wrapperStart = strpos($html, 'data-ui-form-fields');
        $statusStart = strpos($html, 'data-ui-patch-target="form-status"');
        self::assertNotFalse($wrapperStart);
        self::assertNotFalse($statusStart);
        self::assertLessThan($statusStart, $wrapperStart, 'Status target must follow fields wrapper.');
    }

    #[Test]
    public function aria_label_renders_when_no_visual_title(): void
    {
        $html = $this->renderForm(['ariaLabel' => 'Sign-up form']);

        self::assertStringContainsString('aria-label="Sign-up form"', $html);
        self::assertStringContainsString('role="group"', $html);
    }

    // ------------------------------------------------------------
    // autoFields — render-scope collector derivation of cfg.f
    // ------------------------------------------------------------

    private function decodeManifestPayload(string $html): array
    {
        $matched = preg_match(
            '/<script type="application\/json" data-ui-event-manifest="[^"]+" data-ui-component="platform\.form">(.*?)<\/script>/s',
            $html,
            $m,
        );
        self::assertSame(1, $matched, 'platform.form manifest script must be present.');
        return json_decode(str_replace('<\\/', '</', $m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    private function extractFormSubmitCfg(string $html): ?array
    {
        $manifest = $this->decodeManifestPayload($html);
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['p'] ?? null) === 'form' && ($event['e'] ?? null) === 'submit') {
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                return $payload['cfg']['f'] ?? null;
            }
        }
        return null;
    }

    private function autoFieldsSlot(): string
    {
        return '{% set _fields %}'
            . "{{ component('platform.field', {label:'Access code', name:'access_code', required:true, rules:['required',['minLength',4]]}) }}"
            . "{{ component('platform.field', {label:'Confirm access code', name:'confirm_access_code', required:true, rules:['required',['sameAsField','access_code','Codes must match.']]}) }}"
            . '{% endset %}{{ _fields|raw }}';
    }

    /**
     * Render the form with two real FieldComponents in the slot.
     * SSR slot semantics are eager: the inline template renders both
     * fields (each emitting an inert
     * `<script data-ui-field-submit-definition>` marker) FIRST,
     * captures the HTML, then hands it to the form template via the
     * content slot — which is exactly the production path the
     * playground exercises.
     *
     * @param array<string, mixed> $props
     */
    private function renderFormWithRealFields(array $props): string
    {
        $slotTemplate = $this->autoFieldsSlot();
        $slotHtml = $this->twig->createTemplate($slotTemplate)->render([
            '_component' => ['name' => 'platform.form', 'class' => FormComponent::class],
        ]);
        return $this->renderForm($props, ['content' => $slotHtml]);
    }

    #[Test]
    public function autofields_true_signs_cfg_f_from_slotted_fields(): void
    {
        $html = $this->renderFormWithRealFields([
            'showSubmit' => true,
            'autoFields' => true,
            'submitText' => 'Validate form',
        ]);
        $cfgFields = $this->extractFormSubmitCfg($html);
        self::assertIsArray($cfgFields, 'auto-derived cfg.f must be signed into the submit ctx.');
        self::assertCount(2, $cfgFields);
        self::assertSame('access_code', $cfgFields[0]['n']);
        self::assertSame('confirm_access_code', $cfgFields[1]['n']);
        self::assertTrue($cfgFields[0]['q']);
        self::assertSame('Access code', $cfgFields[0]['l']);
        self::assertSame('Confirm access code', $cfgFields[1]['l']);

        // Auto-derived rule wire is the same shape ui_field_rules emits.
        self::assertSame([['n' => 'required'], ['n' => 'minLength', 'p' => [4]]], $cfgFields[0]['r']);
        self::assertSame(
            [['n' => 'required'], ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']]],
            $cfgFields[1]['r'],
        );
    }

    #[Test]
    public function autofields_true_cfg_f_instance_id_matches_rendered_field_root_id(): void
    {
        $html = $this->renderFormWithRealFields([
            'showSubmit' => true,
            'autoFields' => true,
        ]);
        $cfgFields = $this->extractFormSubmitCfg($html);
        self::assertIsArray($cfgFields);
        foreach ($cfgFields as $field) {
            $instanceId = $field['i'];
            self::assertNotEmpty($instanceId);
            // Same uci_<…> appears on a field root that carries the
            // matching data-ui-field-name.
            self::assertMatchesRegularExpression(
                '/data-ui-component="platform\\.field" data-ui-component-instance-id="' . preg_quote($instanceId, '/') . '" data-ui-field-name="' . preg_quote($field['n'], '/') . '"/',
                $html,
                "cfg.f.i must match the same field's rendered root id and field name (n={$field['n']}).",
            );
        }
    }

    #[Test]
    public function autofields_false_keeps_manual_fields_behaviour(): void
    {
        // Manual path still works untouched. The collector frame opens
        // and closes harmlessly around any content; cfg.f is parsed
        // from the explicit `fields` prop.
        $html = $this->renderForm([
            'showSubmit' => true,
            'fields' => [[
                'name' => 'manual_field',
                'instanceId' => 'uci_manual_field_pin',
                'label' => 'Manual',
                'required' => true,
                'rules' => ['required'],
            ]],
        ], ['content' => '<div data-test="manual-slot"></div>']);

        $cfgFields = $this->extractFormSubmitCfg($html);
        self::assertCount(1, $cfgFields);
        self::assertSame('manual_field', $cfgFields[0]['n']);
        self::assertSame('uci_manual_field_pin', $cfgFields[0]['i']);
    }

    #[Test]
    public function autofields_true_with_fields_prop_throws_loud(): void
    {
        // Twig wraps the helper-thrown exception in a RuntimeError.
        // We assert on the inner cause for stability across Twig
        // versions: the wrapped message carries the helper's own
        // message verbatim.
        try {
            $this->renderFormWithRealFields([
                'showSubmit' => true,
                'autoFields' => true,
                'fields' => [[
                    'name' => 'conflict_field',
                    'rules' => ['required'],
                ]],
            ]);
            self::fail('Expected autoFields + fields conflict to throw at render time.');
        } catch (\Throwable $e) {
            self::assertStringContainsString(
                'autoFields',
                $e->getMessage(),
                'Conflict error must call out autoFields explicitly.',
            );
            // Walk the previous chain — the cause is the typed UiComponentRegistryException.
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }
            self::assertInstanceOf(UiComponentRegistryException::class, $cause);
        }
    }

    #[Test]
    public function autofields_false_and_no_fields_emits_no_submit_cfg(): void
    {
        $html = $this->renderForm(['showSubmit' => true], ['content' => '<div></div>']);
        $manifest = $this->decodeManifestPayload($html);
        $hasSubmitEvent = false;
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['e'] ?? null) === 'submit') {
                $hasSubmitEvent = true;
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                // No cfg.f — submit ctx still signs the routing keys
                // but the form-level summary handler still runs as
                // `no_signed_fields`.
                self::assertArrayNotHasKey('cfg', $payload);
            }
        }
        self::assertTrue($hasSubmitEvent, 'submit event entry must still be present');
    }

    #[Test]
    public function autofields_true_with_unsafe_field_name_does_not_register(): void
    {
        // A field with an unsafe name (contains a space) is rendered
        // into the slot. Its name fails the safe-identifier regex, so
        // the field emits NO marker and the form's extractor sees
        // nothing — manifest carries no cfg.f.
        $slot = "{% set _fields %}"
            . "{{ component('platform.field', {label:'unsafe', name:'has space', rules:['required']}) }}"
            . "{% endset %}{{ _fields|raw }}";
        $slotHtml = $this->twig->createTemplate($slot)->render([
            '_component' => ['name' => 'platform.form', 'class' => FormComponent::class],
        ]);
        $html = $this->renderForm(['showSubmit' => true, 'autoFields' => true], ['content' => $slotHtml]);
        $manifest = $this->decodeManifestPayload($html);
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['e'] ?? null) === 'submit') {
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                self::assertArrayNotHasKey('cfg', $payload, 'No collected fields → no cfg.f signed.');
            }
        }
    }

    #[Test]
    public function autofields_true_strips_submit_definition_markers_from_final_html(): void
    {
        $html = $this->renderFormWithRealFields([
            'showSubmit' => true,
            'autoFields' => true,
        ]);
        // Markers were consumed by the extractor; they must NOT be
        // present in the final DOM.
        self::assertStringNotContainsString('data-ui-field-submit-definition', $html);
    }

    // ------------------------------------------------------------
    // submitAction signing into cfg.a
    // ------------------------------------------------------------

    #[Test]
    public function submit_action_prop_is_signed_into_cfg_a(): void
    {
        $html = $this->renderFormWithRealFields([
            'showSubmit'   => true,
            'autoFields'   => true,
            'submitAction' => 'platform.demo.accept',
        ]);
        $manifest = $this->decodeManifestPayload($html);
        $found = null;
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['e'] ?? null) === 'submit') {
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                $found = $payload['cfg']['a'] ?? null;
            }
        }
        self::assertSame('platform.demo.accept', $found);
    }

    #[Test]
    public function form_without_submit_action_signs_no_cfg_a(): void
    {
        $html = $this->renderFormWithRealFields([
            'showSubmit' => true,
            'autoFields' => true,
        ]);
        $manifest = $this->decodeManifestPayload($html);
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['e'] ?? null) === 'submit') {
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                self::assertArrayNotHasKey('a', $payload['cfg'] ?? []);
            }
        }
    }

    #[Test]
    public function unknown_submit_action_throws_loud_at_render_time(): void
    {
        try {
            $this->renderFormWithRealFields([
                'showSubmit'   => true,
                'autoFields'   => true,
                'submitAction' => 'app.never.registered',
            ]);
            self::fail('Expected unknown-action render-time failure.');
        } catch (\Throwable $e) {
            // Twig wraps the helper-thrown exception. Walk to the cause.
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }
            self::assertInstanceOf(UiFormSubmitActionException::class, $cause);
            self::assertStringContainsString('Unknown form submit action', $cause->getMessage());
        }
    }

    #[Test]
    public function unsafe_submit_action_shape_throws_loud_at_render_time(): void
    {
        try {
            $this->renderFormWithRealFields([
                'showSubmit'   => true,
                'autoFields'   => true,
                'submitAction' => 'evil action with spaces',
            ]);
            self::fail('Expected unsafe-shape render-time failure.');
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }
            self::assertInstanceOf(UiFormSubmitActionException::class, $cause);
        }
    }

    #[Test]
    public function submit_action_render_signs_cfg_s_with_safe_csrf_token(): void
    {
        UiFormSubmitCsrfTokenStore::setActive(new InMemoryUiFormSubmitCsrfTokenStore());
        $html = $this->renderFormWithRealFields([
            'showSubmit'   => true,
            'autoFields'   => true,
            'submitAction' => 'platform.demo.accept',
        ]);
        $manifest = $this->decodeManifestPayload($html);
        $cfgS = null;
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['e'] ?? null) === 'submit') {
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                $cfgS = $payload['cfg']['s'] ?? null;
            }
        }
        self::assertIsArray($cfgS, 'cfg.s must be signed when submitAction is set.');
        self::assertMatchesRegularExpression('/\Auicsrf_[a-f0-9]{16}\z/', $cfgS['k']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $cfgS['t']);
    }

    #[Test]
    public function form_without_submit_action_signs_no_cfg_s(): void
    {
        UiFormSubmitCsrfTokenStore::setActive(new InMemoryUiFormSubmitCsrfTokenStore());
        $html = $this->renderFormWithRealFields([
            'showSubmit' => true,
            'autoFields' => true,
        ]);
        $manifest = $this->decodeManifestPayload($html);
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['e'] ?? null) === 'submit') {
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                self::assertArrayNotHasKey('s', $payload['cfg'] ?? []);
            }
        }
    }

    #[Test]
    public function cfg_s_carries_only_compact_token_pair(): void
    {
        UiFormSubmitCsrfTokenStore::setActive(new InMemoryUiFormSubmitCsrfTokenStore());
        $html = $this->renderFormWithRealFields([
            'showSubmit'   => true,
            'autoFields'   => true,
            'submitAction' => 'platform.demo.accept',
        ]);
        $manifest = $this->decodeManifestPayload($html);
        foreach ($manifest['events'] ?? [] as $event) {
            if (($event['e'] ?? null) === 'submit') {
                $payload = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']) ?? [];
                $cfgS = $payload['cfg']['s'];
                // ONLY `k` + `t`. No session id, no cache key format,
                // no internal fields.
                self::assertSame(['k', 't'], array_keys($cfgS));
            }
        }
    }

    #[Test]
    public function cfg_a_does_not_carry_class_or_service_names(): void
    {
        $html = $this->renderFormWithRealFields([
            'showSubmit'   => true,
            'autoFields'   => true,
            'submitAction' => 'platform.demo.accept',
        ]);
        // The ENTIRE manifest script body must not leak class FQCNs
        // or service ids — even though we know the signed action
        // string is the safe `platform.demo.accept`, this pins the
        // perimeter against a future regression in the helper.
        self::assertSame(1, preg_match(
            '/<script type="application\/json" data-ui-event-manifest="[^"]+" data-ui-component="platform\.form">(.*?)<\/script>/s',
            $html,
            $matches,
        ));
        $body = $matches[1] ?? '';
        self::assertStringNotContainsString('PlatformDemoAcceptAction', $body);
        self::assertStringNotContainsString('Semitexa\\\\', $body);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Component\UiPartPropResolver;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Renders the FieldComponent template through a real Twig environment
 * with `primitive()` and `slot()` helpers wired the same way the live
 * runtime wires them. Bypasses ModuleTemplateRegistry so the test stays
 * free of the full SSR boot.
 */
final class FieldComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        // Pin dev secret + APP_ENV so SignedContext::sign succeeds inside
        // the test container.
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-test-secret');
        putenv('APP_ENV=dev');

        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        AssetCollectorStore::reset();

        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(InputPrimitive::class),
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
            static fn (string $name, array $props = []): \Twig\Markup =>
                new \Twig\Markup($renderer->render($name, $props), 'UTF-8'),
            ['is_safe' => ['html']],
        ));
        $this->twig->addFunction(new TwigFunction(
            'slot',
            static function (array $context, string $name): \Twig\Markup {
                $slots = $context['_slots'] ?? [];
                $value = is_array($slots) ? ($slots[$name] ?? null) : null;
                return new \Twig\Markup($value === null ? '' : (string) $value, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));

        $resolver = new UiPartPropResolver();
        $this->twig->addFunction(new TwigFunction(
            'ui_part_props',
            static function (array $context, string $partName, array $overrides = []) use ($resolver): array {
                $component = $context['_component'] ?? null;
                if (!is_array($component) || !isset($component['name']) || !is_string($component['name'])) {
                    throw new UiComponentRegistryException('ui_part_props() called outside a Platform UI component render context.');
                }
                $metadata = UiComponentRegistry::get($component['name']);
                if ($metadata === null) {
                    throw new UiComponentRegistryException('Component ' . $component['name'] . ' is not registered.');
                }
                $componentProps = [];
                foreach ($context as $key => $value) {
                    if (is_string($key) && $key !== '' && $key[0] !== '_') {
                        $componentProps[$key] = $value;
                    }
                }
                return $resolver->resolve($metadata, $partName, $componentProps, $overrides);
            },
            ['needs_context' => true],
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_part',
            static function (array $context, string $partName, array $overrides = []) use ($resolver, $renderer): Markup {
                $component = $context['_component'] ?? null;
                if (!is_array($component) || !isset($component['name']) || !is_string($component['name'])) {
                    throw new UiComponentRegistryException('ui_part() called outside a Platform UI component render context.');
                }
                $metadata = UiComponentRegistry::get($component['name']);
                if ($metadata === null) {
                    throw new UiComponentRegistryException('Component ' . $component['name'] . ' is not registered.');
                }
                $part = $metadata->part($partName);
                if ($part === null) {
                    throw new UiComponentRegistryException('Unknown part ' . $partName);
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
                if ($injected === null || $count !== 1) {
                    throw new UiComponentRegistryException('ui_part marker injection failed');
                }
                return new Markup($injected, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_component_instance',
            static fn (): string => (new UiInstanceIdGenerator())->next(),
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_component_instance_for',
            static function (mixed $override = null): string {
                if ($override === null) {
                    return (new UiInstanceIdGenerator())->next();
                }
                if (!UiInstanceIdGenerator::isSafe($override)) {
                    throw new UiComponentRegistryException(
                        'ui_component_instance_for() override must match the safe instance-id shape.',
                    );
                }
                /** @var string $override */
                return $override;
            },
        ));

        $manifestBuilder = new UiEventManifestBuilder();
        $this->twig->addFunction(new TwigFunction(
            'ui_event_manifest',
            static function (array $context, string $instanceId, ?int $ttlSeconds = null, array $eventConfig = []) use ($manifestBuilder): Markup {
                $component = $context['_component'] ?? null;
                if (!is_array($component) || !isset($component['name']) || !is_string($component['name'])) {
                    throw new UiComponentRegistryException('ui_event_manifest() called outside a Platform UI component render context.');
                }
                $metadata = UiComponentRegistry::get($component['name']);
                if ($metadata === null) {
                    throw new UiComponentRegistryException('Component ' . $component['name'] . ' is not registered.');
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

        // Validation rule helper used by the field template's signed
        // manifest. In the harness we wire a minimal pass-through to
        // the real parser so the render tests exercise the same code
        // path the production template does.
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

        // Submit-definition marker helper used by the field template
        // for the FormComponent autoFields path. Mirrors the
        // production helper so the field-only harness emits the same
        // inert marker payload the form's extractor consumes.
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
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        AssetCollectorStore::reset();

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
    private function renderField(array $props = [], array $slots = []): string
    {
        return $this->twig->render(
            '@platform-ui/components/runtime/field.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.field', 'class' => FieldComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function basic_field_renders_label_and_input_with_root_marker(): void
    {
        $html = $this->renderField([
            'label' => 'Email address',
            'name' => 'email',
            'placeholder' => 'name@example.com',
        ]);

        self::assertStringContainsString('data-ui-component="platform.field"', $html);
        self::assertStringContainsString('ui-component="field"', $html);
        self::assertStringContainsString('<label', $html);
        self::assertStringContainsString('for="email"', $html);
        self::assertStringContainsString('Email address', $html);
        self::assertStringContainsString('data-ui-primitive="platform.input"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('id="email"', $html);
        self::assertStringContainsString('placeholder="name@example.com"', $html);
    }

    #[Test]
    public function help_text_renders_below_input_when_provided(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'help' => 'We never share email addresses.',
        ]);

        self::assertStringContainsString('We never share email addresses.', $html);
        self::assertStringContainsString('id="email-help"', $html);
        self::assertStringContainsString('aria-describedby="email-help"', $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
    }

    #[Test]
    public function error_state_sets_invalid_and_aria_attributes(): void
    {
        $html = $this->renderField([
            'label' => 'Username',
            'name' => 'username',
            'value' => 'me!',
            'error' => 'Letters and digits only.',
        ]);

        self::assertStringContainsString('Letters and digits only.', $html);
        self::assertStringContainsString('id="username-error"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="username-error"', $html);
        self::assertStringContainsString('ui-state="invalid"', $html);
    }

    #[Test]
    public function disabled_passes_through_to_input(): void
    {
        $html = $this->renderField([
            'label' => 'Locked',
            'name' => 'locked',
            'value' => 'cannot change',
            'disabled' => true,
        ]);

        self::assertStringContainsString('disabled', $html);
    }

    #[Test]
    public function missing_slots_render_nothing_extra(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
        ]);

        // No cluster wrapper since neither prefix nor suffix is set.
        self::assertStringNotContainsString('sx-layout="cluster"', $html);
    }

    #[Test]
    public function provided_slot_renders_in_expected_place(): void
    {
        $html = $this->renderField(
            ['label' => 'Search', 'name' => 'q', 'placeholder' => 'Search...'],
            ['suffix' => '<button data-test="suffix-btn" type="submit">Go</button>'],
        );

        self::assertStringContainsString('data-test="suffix-btn"', $html);
        self::assertStringContainsString('sx-layout="cluster"', $html);

        $inputPos = strpos($html, 'data-ui-primitive="platform.input"');
        $suffixPos = strpos($html, 'data-test="suffix-btn"');
        self::assertNotFalse($inputPos);
        self::assertNotFalse($suffixPos);
        self::assertLessThan($suffixPos, $inputPos, 'Suffix slot must render AFTER the input.');
    }

    #[Test]
    public function prefix_slot_renders_before_input(): void
    {
        $html = $this->renderField(
            ['label' => 'URL', 'name' => 'url'],
            ['prefix' => '<span data-test="prefix-span">https://</span>'],
        );

        $prefixPos = strpos($html, 'data-test="prefix-span"');
        $inputPos = strpos($html, 'data-ui-primitive="platform.input"');
        self::assertNotFalse($prefixPos);
        self::assertNotFalse($inputPos);
        self::assertLessThan($inputPos, $prefixPos, 'Prefix slot must render BEFORE the input.');
    }

    #[Test]
    public function required_prop_propagates_to_input_and_label_marker(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'required' => true,
        ]);

        // <input required> on the primitive
        self::assertStringContainsString('required', $html);
        // Visible required marker next to the label
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    #[Test]
    public function inputprops_caller_overrides_reach_the_rendered_input(): void
    {
        $html = $this->renderField([
            'label' => 'Search',
            'name' => 'q',
            'placeholder' => 'should be replaced',
            'inputProps' => [
                'placeholder' => 'forced via inputProps',
            ],
        ]);

        self::assertStringContainsString('placeholder="forced via inputProps"', $html);
        self::assertStringNotContainsString('placeholder="should be replaced"', $html);
    }

    #[Test]
    public function inputprops_can_introduce_new_attributes_passed_through_the_primitive(): void
    {
        $html = $this->renderField([
            'label' => 'PIN',
            'name' => 'pin',
            'inputProps' => [
                'aria_describedby' => 'custom-help-id',
            ],
        ]);

        self::assertStringContainsString('aria-describedby="custom-help-id"', $html);
    }

    #[Test]
    public function bound_value_lands_on_the_rendered_input(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'value' => 'hello@example.com',
        ]);

        self::assertStringContainsString('value="hello@example.com"', $html);
    }

    #[Test]
    public function inputprops_value_wins_over_bound_value(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'value' => 'hello@example.com',
            'inputProps' => [
                'value' => 'override@example.com',
            ],
        ]);

        self::assertStringContainsString('value="override@example.com"', $html);
        self::assertStringNotContainsString('value="hello@example.com"', $html);
    }

    #[Test]
    public function missing_value_does_not_emit_value_attribute(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
        ]);

        self::assertStringNotContainsString(' value="', $html);
    }

    #[Test]
    public function rendered_field_does_not_leak_event_runtime_attributes(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'value' => 'hello@example.com',
        ]);

        // The signed manifest is inert JSON; no runtime wiring attributes.
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('onchange', $html);
        self::assertStringNotContainsString('oninput', $html);
        self::assertStringNotContainsString('data-ui-handler', $html);
        self::assertStringNotContainsString('data-ui-event-url', $html);
        self::assertStringNotContainsString('data-ui-on=', $html);
        self::assertStringNotContainsString('onInputChanged', $html);
    }

    #[Test]
    public function rendered_field_emits_instance_id_and_manifest_script(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'value' => 'hello@example.com',
        ]);

        // 1) Root carries a per-render instance id.
        self::assertMatchesRegularExpression(
            '/data-ui-component-instance-id="uci_[0-9a-f]{16}"/',
            $html,
        );

        // 2) An inline JSON manifest script tag is present and matches that id.
        self::assertMatchesRegularExpression(
            '/<script type="application\\/json" data-ui-event-manifest="uci_[0-9a-f]{16}" data-ui-component="platform\\.field">/',
            $html,
        );

        // 3) The script body is valid JSON with the expected compact shape.
        self::assertMatchesRegularExpression('/<script[^>]*>\s*\{.*\}\s*<\/script>/s', $html);
    }

    #[Test]
    public function rendered_manifest_json_carries_signed_context_for_input_change(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'value' => 'hello@example.com',
        ]);

        // Extract the manifest JSON. Reverse the defensive `</` → `<\/` escape
        // so json_decode sees the original.
        self::assertSame(1, preg_match(
            '/<script type="application\\/json" data-ui-event-manifest="(uci_[0-9a-f]{16})"[^>]*>(.+?)<\/script>/s',
            $html,
            $matches,
        ));
        $instanceId = $matches[1];
        $rawJson = $matches[2];
        $payload = json_decode(str_replace('<\\/', '</', $rawJson), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['v']);
        self::assertSame('platform.field', $payload['c']);
        self::assertSame($instanceId, $payload['i']);
        self::assertCount(1, $payload['events']);

        $event = $payload['events'][0];
        self::assertSame('input', $event['p']);
        self::assertSame('change', $event['e']);
        self::assertSame('value', $event['u']);
        self::assertStringStartsWith('sc1.', $event['ctx']);

        // Round-trip the signed context: server-side verification must accept it.
        $claims = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($event['ctx']);
        self::assertNotNull($claims);
        self::assertSame('platform.field', $claims['c']);
        self::assertSame($instanceId, $claims['i']);
        self::assertSame('input', $claims['p']);
        self::assertSame('change', $claims['e']);
        self::assertSame('value', $claims['u']);

        // The method name and class FQCN must not leak to the client.
        self::assertStringNotContainsString('onInputChanged', $rawJson);
        self::assertStringNotContainsString('FieldComponent', $rawJson);
        self::assertStringNotContainsString('Semitexa\\\\', $rawJson);
    }

    #[Test]
    public function two_renders_produce_different_instance_ids_in_dom(): void
    {
        $a = $this->renderField(['label' => 'A', 'name' => 'a']);
        $b = $this->renderField(['label' => 'B', 'name' => 'b']);

        preg_match('/data-ui-component-instance-id="(uci_[0-9a-f]{16})"/', $a, $ma);
        preg_match('/data-ui-component-instance-id="(uci_[0-9a-f]{16})"/', $b, $mb);

        self::assertNotEmpty($ma[1] ?? '');
        self::assertNotEmpty($mb[1] ?? '');
        self::assertNotSame($ma[1], $mb[1]);
    }

    #[Test]
    public function rendered_input_carries_data_ui_part_marker(): void
    {
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
            'value' => 'hello@example.com',
        ]);

        // Marker present and attached to the input root tag.
        self::assertMatchesRegularExpression(
            '/<input\s+data-ui-part="input"\s/',
            $html,
            'Input must start with data-ui-part marker injected by ui_part().',
        );
        // Original primitive attributes still present.
        self::assertStringContainsString('data-ui-primitive="platform.input"', $html);
        self::assertStringContainsString('ui="input"', $html);
        self::assertStringContainsString('value="hello@example.com"', $html);
        // Marker is emitted exactly once per render.
        self::assertSame(1, substr_count($html, 'data-ui-part="input"'));
    }

    #[Test]
    public function data_ui_part_marker_is_html_escaped(): void
    {
        // The part name passes through htmlspecialchars in the marker.
        // FieldComponent uses a safe `input` name; this guards against
        // future components using less-safe names by sanity-checking the
        // helper produces an exact, properly-quoted attribute.
        $html = $this->renderField([
            'label' => 'Email',
            'name' => 'email',
        ]);

        // The marker must use double-quoted form (no embedded characters
        // that need escaping) and must NOT appear as e.g. `data-ui-part=input`
        // unquoted.
        self::assertStringNotContainsString('data-ui-part=input', $html);
        self::assertMatchesRegularExpression(
            '/\sdata-ui-part="input"/',
            $html,
        );
    }

    #[Test]
    public function manifest_script_is_emitted_inside_the_component_root(): void
    {
        $html = $this->renderField(['label' => 'Email', 'name' => 'email']);

        // The script tag must live inside the root <div data-ui-component=...>.
        $rootStart = strpos($html, 'data-ui-component="platform.field"');
        $rootEnd = strpos($html, '</div>', $rootStart ?: 0);
        $scriptPos = strpos($html, 'data-ui-event-manifest=');

        self::assertNotFalse($rootStart);
        self::assertNotFalse($rootEnd);
        self::assertNotFalse($scriptPos);
        self::assertLessThan($scriptPos, $rootStart);
        self::assertGreaterThan($scriptPos, $rootEnd);
    }

    #[Test]
    public function safe_name_prop_lands_as_data_ui_field_name_marker_on_root(): void
    {
        $html = $this->renderField([
            'label' => 'Username',
            'name' => 'username',
        ]);

        // FormComponent's client-local aggregate keys per-field state by
        // this marker — the regex matches the same identifier shape the
        // server-side patch validator accepts for target names.
        self::assertStringContainsString('data-ui-field-name="username"', $html);

        // Marker lives on the component root, not on the input primitive.
        $rootStart = strpos($html, 'data-ui-component="platform.field"');
        $markerPos = strpos($html, 'data-ui-field-name="username"');
        $inputPos = strpos($html, 'data-ui-primitive="platform.input"');
        self::assertNotFalse($rootStart);
        self::assertNotFalse($markerPos);
        self::assertNotFalse($inputPos);
        self::assertLessThan($inputPos, $markerPos, 'data-ui-field-name must be on the root, before the input.');
    }

    #[Test]
    public function field_name_marker_is_omitted_when_no_name_prop(): void
    {
        $html = $this->renderField(['label' => 'No name']);

        self::assertStringNotContainsString('data-ui-field-name', $html);
    }

    #[Test]
    public function field_name_marker_rejects_unsafe_identifiers(): void
    {
        // Hostile name prop with a quote and angle bracket. The render-
        // time regex must drop the marker entirely rather than smuggle
        // anything into the root tag. (The input primitive's existing
        // attribute escaping handles the value-level case; this test
        // only pins the new marker's allow-list shape.)
        $html = $this->renderField([
            'label' => 'Bad',
            'name' => 'evil" onmouseover=alert(1) x="',
        ]);

        self::assertStringNotContainsString('data-ui-field-name=', $html);
    }

    #[Test]
    public function field_name_marker_accepts_identifier_characters_only(): void
    {
        $html = $this->renderField([
            'label' => 'Display',
            'name' => 'display_name',
        ]);

        self::assertStringContainsString('data-ui-field-name="display_name"', $html);
    }

    // ---------------------------------------------------------------
    // Explicit instanceId prop
    // ---------------------------------------------------------------

    #[Test]
    public function default_render_generates_uci_hex_instance_id(): void
    {
        $html = $this->renderField(['label' => 'X', 'name' => 'x']);
        self::assertMatchesRegularExpression(
            '/data-ui-component-instance-id="uci_[a-f0-9]{16}"/',
            $html,
        );
    }

    #[Test]
    public function explicit_safe_instance_id_lands_on_root(): void
    {
        $html = $this->renderField([
            'label' => 'Pinned',
            'name' => 'pinned',
            'instanceId' => 'uci_submit_pinned',
        ]);
        self::assertStringContainsString(
            'data-ui-component-instance-id="uci_submit_pinned"',
            $html,
        );
        // No coexisting fresh-generated id on the root.
        self::assertSame(
            1,
            substr_count($html, 'data-ui-component-instance-id="'),
        );
    }

    #[Test]
    public function explicit_safe_instance_id_lands_in_signed_event_manifest(): void
    {
        $html = $this->renderField([
            'label' => 'Pinned',
            'name' => 'pinned',
            'instanceId' => 'uci_submit_pinned',
        ]);
        // The manifest script's data-ui-event-manifest attribute also
        // carries the instance id; both root + manifest must pin to
        // the same id.
        self::assertStringContainsString(
            'data-ui-event-manifest="uci_submit_pinned"',
            $html,
        );

        // And the signed ctx claim `i` must equal the override. Decode
        // the manifest JSON and the base64url claim payload.
        $matched = preg_match(
            '/<script type="application\/json" data-ui-event-manifest="[^"]*"[^>]*>(.+?)<\/script>/',
            $html,
            $matches,
        );
        self::assertSame(1, $matched, 'manifest script must be emitted');
        $manifest = json_decode(str_replace('<\\/', '</', $matches[1]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('uci_submit_pinned', $manifest['i']);
        foreach ($manifest['events'] as $entry) {
            $b64 = explode('.', $entry['ctx'])[1];
            $b64 .= str_repeat('=', (4 - (strlen($b64) % 4)) % 4);
            $claims = json_decode(base64_decode(strtr($b64, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('uci_submit_pinned', $claims['i']);
        }
    }

    #[Test]
    public function unsafe_explicit_instance_id_is_rejected_at_render_time(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/safe instance-id shape/i');
        $this->renderField([
            'label' => 'Bad',
            'name' => 'bad',
            'instanceId' => 'evil" onerror=1',
        ]);
    }

    #[Test]
    public function unsafe_explicit_instance_id_with_angle_brackets_is_rejected(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderField([
            'label' => 'Bad',
            'name' => 'bad',
            'instanceId' => 'uci_<script>',
        ]);
    }

    // ----------------------------------------------------------------
    // Submit-definition marker emission (FormComponent autoFields path)
    // ----------------------------------------------------------------

    private function extractMarkerJson(string $html): ?array
    {
        if (preg_match('/<script type="application\/json" data-ui-field-submit-definition>(.*?)<\/script>/s', $html, $m) !== 1) {
            return null;
        }
        return json_decode(str_replace('<\\/', '</', $m[1]), true, 8, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function field_emits_submit_definition_marker_with_default_generated_instance_id(): void
    {
        $html = $this->renderField([
            'label' => 'Access code',
            'name' => 'access_code',
            'required' => true,
            'rules' => ['required', ['minLength', 4]],
        ]);
        $marker = $this->extractMarkerJson($html);
        self::assertIsArray($marker);
        self::assertSame('access_code', $marker['n']);
        self::assertSame('Access code', $marker['l']);
        self::assertTrue($marker['q']);
        self::assertSame([['n' => 'required'], ['n' => 'minLength', 'p' => [4]]], $marker['r']);
        // Same uci_ id is also stamped on the field root.
        self::assertMatchesRegularExpression(
            '/data-ui-component-instance-id="' . preg_quote($marker['i'], '/') . '"/',
            $html,
        );
    }

    #[Test]
    public function field_marker_uses_explicit_instance_id_when_caller_pins_it(): void
    {
        $html = $this->renderField([
            'label' => 'Pinned',
            'name' => 'pinned',
            'instanceId' => 'uci_pinned_explicit',
            'rules' => ['required'],
        ]);
        $marker = $this->extractMarkerJson($html);
        self::assertIsArray($marker);
        self::assertSame('uci_pinned_explicit', $marker['i']);
    }

    #[Test]
    public function field_emits_no_marker_when_name_is_unsafe(): void
    {
        $html = $this->renderField([
            'label' => 'Bad',
            'name' => 'has space',
            'rules' => ['required'],
        ]);
        self::assertStringNotContainsString('data-ui-field-submit-definition', $html);
    }

    #[Test]
    public function field_emits_no_marker_when_name_is_missing(): void
    {
        $html = $this->renderField([
            'label' => 'Anon',
            'rules' => ['required'],
        ]);
        self::assertStringNotContainsString('data-ui-field-submit-definition', $html);
    }

    #[Test]
    public function field_marker_payload_does_not_leak_class_or_method_names(): void
    {
        $html = $this->renderField([
            'label' => 'Safe',
            'name' => 'safe',
            'rules' => ['required'],
        ]);
        $marker = $this->extractMarkerJson($html);
        self::assertIsArray($marker);
        $json = json_encode($marker, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Semitexa\\\\', $json);
        self::assertStringNotContainsString('FieldComponent', $json);
        self::assertStringNotContainsString('Validator', $json);
    }
}

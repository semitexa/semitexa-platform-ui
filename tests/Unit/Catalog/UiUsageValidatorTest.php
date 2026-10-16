<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\CardComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\CollaborativeFormComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\FormComponent;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorCatalog;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCatalogProjector;
use Semitexa\PlatformUi\Application\Service\Catalog\UiUsageValidator;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveCatalog;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFunction;

/**
 * What a template passes to component() / primitive() is checked against the
 * contract, from the parsed template alone.
 */
final class UiUsageValidatorTest extends TestCase
{
    use BuildsContainerManagedObjects;

    #[Test]
    public function each_kind_of_mismatch_is_named_with_its_code_and_path(): void
    {
        $result = $this->validate(<<<'TWIG'
            {{ component('platform.card', {variant: 'shiny', titel: 'x'}, {sidebar: 'y'}) }}
            {{ primitive('button', {tone: 'brand'}) }}
            {{ primitive('input', {type: 'colour'}) }}
            TWIG);

        $found = array_map(static fn (array $i): string => "{$i['line']} {$i['entry']} {$i['code']} {$i['path']}", $result['issues']);
        self::assertSame([
            '1 platform.card invalid_value props.variant',
            '1 platform.card unknown_prop props.titel',
            '1 platform.card unknown_slot slots.sidebar',
            '2 platform.button missing_required props.text',
            '3 platform.input invalid_value props.type',
        ], $found);
        self::assertSame("one of: 'elevated', 'outlined', 'plain'", $result['issues'][0]['expected']);
        self::assertSame('one of: media, header, body, footer', $result['issues'][2]['expected']);
        self::assertSame(3, $result['checked']);
    }

    #[Test]
    public function what_is_built_at_render_time_is_counted_not_judged(): void
    {
        $result = $this->validate(<<<'TWIG'
            {{ component('platform.card', {variant: mode, title: 'T' ~ name}) }}
            {{ primitive('button', props) }}
            {{ primitive('button', {text: 'Go'}|merge(extra)) }}
            {{ component(dynamicName, {anything: 1}) }}
            {{ component('platform.collab-form', {whatever: true}) }}
            TWIG);

        self::assertSame([], $result['issues'], 'nothing literal is wrong here');
        self::assertSame(4, $result['unresolved'], 'two values, a props variable, a filtered map');
        self::assertSame(3, $result['checked'], 'an untyped component and a dynamic name are not checked');
    }

    #[Test]
    public function a_correct_call_is_clean_and_a_code_sample_in_verbatim_is_not_a_call(): void
    {
        $result = $this->validate(<<<'TWIG'
            {{ component('platform.card', {variant: 'outlined', title: 'Hello'}, {body: 'x'}) }}
            {{ primitive('button', {text: 'Save', type: 'submit', loading: false, size: 'sm'}) }}
            {% verbatim %}{{ component('platform.card', {nonsense: 1}) }}{% endverbatim %}
            TWIG);

        self::assertSame([], $result['issues']);
        self::assertSame(2, $result['checked']);
    }

    #[Test]
    public function a_contract_with_extra_props_accepts_any_key_but_still_checks_declared_ones(): void
    {
        // platform.form hands undeclared props to its submit action as signed context.
        $result = $this->validate(<<<'TWIG'
            {{ component('platform.form', {articleId: 7, crud: 'posts', showSubmit: true}) }}
            {{ component('platform.form', {showSubmit: 'yes', submitTone: 'loud'}) }}
            {{ component('platform.field', {label: 'Name', articleId: 7}) }}
            TWIG);

        $found = array_map(static fn (array $i): string => "{$i['line']} {$i['entry']} {$i['code']} {$i['path']}", $result['issues']);
        self::assertSame([
            '2 platform.form invalid_value props.showSubmit',
            '2 platform.form invalid_value props.submitTone',
            '3 platform.field unknown_prop props.articleId',
        ], $found);
        self::assertSame(3, $result['checked']);
    }

    #[Test]
    public function a_field_value_may_be_any_scalar_but_not_a_list(): void
    {
        $result = $this->validate(<<<'TWIG'
            {{ component('platform.field', {name: 'qty', type: 'number', value: 5}) }}
            {{ component('platform.field', {name: 'agree', control: 'checkbox', value: true}) }}
            {{ component('platform.field', {name: 'ids', control: 'select', multiple: true, values: [1, 2]}) }}
            {{ component('platform.field', {name: 'at', type: 'time', value: '09:30'}) }}
            {{ component('platform.field', {name: 'tags', value: ['a']}) }}
            TWIG);

        $found = array_map(static fn (array $i): string => "{$i['line']} {$i['code']} {$i['path']}", $result['issues']);
        self::assertSame(['5 invalid_value props.value'], $found);
    }

    #[Test]
    public function a_template_that_does_not_parse_is_unchecked_not_clean(): void
    {
        $result = $this->validate('{{ component(');

        self::assertTrue($result['unparsed']);
        self::assertSame(0, $result['checked']);
    }

    /** @return array{issues: list<array<string, mixed>>, checked: int, unresolved: int, unparsed: bool} */
    private function validate(string $template): array
    {
        $twig = new Environment(new ArrayLoader());
        foreach (['component', 'primitive'] as $function) {
            $twig->addFunction(new TwigFunction($function, static fn (): string => ''));
        }

        return (new UiUsageValidator($this->projector()))->validate($twig, new Source($template, 'fixture.html.twig', 'fixture.html.twig'));
    }

    private function projector(): UiCatalogProjector
    {
        $primitives = new UiPrimitiveCatalog();
        foreach ([InputPrimitive::class, ButtonPrimitive::class] as $class) {
            $primitives->register((new UiPrimitiveMetadataFactory())->fromClass($class));
        }
        $components = new UiComponentCatalog();
        foreach ([CardComponent::class, CollaborativeFormComponent::class, FieldComponent::class, FormComponent::class] as $class) {
            $components->register((new UiComponentMetadataFactory())->fromClass($class));
        }
        $behaviors = new UiBehaviorCatalog();

        return $this->createWithDependencies(UiCatalogProjector::class, compact('primitives', 'components', 'behaviors'));
    }
}

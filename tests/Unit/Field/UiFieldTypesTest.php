<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Field\Type\TextFieldType;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldTypes;
use Semitexa\PlatformUi\Attribute\AsFieldType;
use Semitexa\PlatformUi\Domain\Model\Field\Field;
use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * tk-rs-field-types: one declaration, five views — the form control, the
 * rules, the grid column, the filter and the inference from a stored column.
 */
final class UiFieldTypesTest extends TestCase
{
    /** The process-wide registry as the test found it (a test here discovers into it). */
    private mixed $typesBefore = null;

    protected function setUp(): void
    {
        $this->typesBefore = (new \ReflectionProperty(UiFieldTypes::class, 'types'))->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(UiFieldTypes::class, 'types'))->setValue(null, $this->typesBefore);
    }

    #[Test]
    public function every_field_constructor_names_a_registered_type(): void
    {
        foreach ((new \ReflectionClass(Field::class))->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($method->name === 'of') {
                continue;
            }
            /** @var UiField $field */
            $field = $method->invoke(null, 'value');
            self::assertSame($field->type, UiFieldTypes::get($field->type)->name(), $method->name);
        }
        self::assertCount(18, UiFieldTypes::all());
    }

    #[Test]
    public function a_field_is_an_immutable_declaration(): void
    {
        $base = Field::text('title');
        $required = $base->required()->set('max', 160)->label('Headline');

        self::assertFalse($base->required);
        self::assertSame('Title', $base->label);
        self::assertTrue($required->required);
        self::assertSame('Headline', $required->label);
        self::assertSame('Updated at', Field::datetime('updatedAt')->label);
        $this->expectException(\InvalidArgumentException::class);
        Field::text('bad name');
    }

    #[Test]
    public function text_says_the_same_limit_to_the_form_and_the_rules(): void
    {
        $field = Field::text('title')->required()->set('max', 160)->help('Shown in lists.');
        $props = UiFieldTypes::for($field)->formProps($field);

        self::assertSame('input', $props['control']);
        self::assertSame(['type' => 'text', 'maxlength' => 160], $props['inputProps']);
        self::assertSame(['required', ['maxLength', 160]], $props['rules']);
        self::assertTrue($props['required']);
        self::assertSame('Shown in lists.', $props['help']);
        self::assertSame(['contains', 'eq'], UiFieldTypes::for($field)->filter($field)['operators']);
    }

    #[Test]
    public function a_choice_is_a_badge_column_a_select_filter_and_an_in_rule(): void
    {
        $field = Field::choice('status', [
            'draft' => ['label' => 'Draft', 'tone' => 'warning'],
            'published' => ['label' => 'Published', 'tone' => 'success'],
            'archived' => 'Archived',
        ])->required();
        $type = UiFieldTypes::for($field);

        self::assertSame('segmented', $type->formProps($field)['control']); // 3 options
        self::assertSame(['required', ['in', 'draft', 'published', 'archived']], $type->rules($field));
        self::assertSame(['draft' => 'warning', 'published' => 'success', 'archived' => 'neutral'], $type->column($field)['variants']);
        self::assertSame('badge', $type->column($field)['format']);
        self::assertSame(['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'], $type->column($field)['labels'], 'the badge shows the label, not the stored value');
        self::assertSame([['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published'], ['value' => 'archived', 'label' => 'Archived']], $type->filter($field)['options']);
        self::assertSame('select', $type->formProps($field->set('control', 'select'))['control']);
    }

    #[Test]
    public function numeric_values_keep_their_labels(): void
    {
        // [1 => 'Bad', 2 => 'Good'] used to be read as the values "Bad" and "Good".
        self::assertSame([['value' => '1', 'label' => 'Bad'], ['value' => '2', 'label' => 'Good']], Field::choice('rating', [1 => 'Bad', 2 => 'Good'])->options);
        self::assertSame([['value' => 'low', 'label' => 'Low']], Field::choice('level', ['low'])->options, 'a list is values');
    }

    #[Test]
    public function every_type_advertises_only_filter_operators_the_collection_parser_accepts(): void
    {
        // A contract advertising an operator the parser refuses is a 400 for
        // every visitor who uses that filter (types once declared gte / lte).
        $supported = \Semitexa\Core\Resource\Filter\FilterOperator::wireForms();
        foreach (UiFieldTypes::all() as $name => $type) {
            $filter = $type->filter(new UiField('value', $name));
            if ($filter === null) {
                continue;
            }
            self::assertNotSame([], $filter['operators'], $name);
            self::assertSame([], array_values(array_diff($filter['operators'], $supported)), $name . ' advertises an operator the parser does not know');
        }
    }

    #[Test]
    public function a_switch_is_labelled_once_beside_it(): void
    {
        $props = UiFieldTypes::get('boolean')->formProps(Field::boolean('featured'));
        self::assertArrayNotHasKey('label', $props);
        self::assertSame('Featured', $props['checkboxLabel']);
    }

    #[Test]
    public function an_optional_list_can_be_cleared_and_a_required_one_cannot(): void
    {
        $category = Field::belongsTo('category')->options(['a' => 'A']);
        self::assertSame('— None —', UiFieldTypes::for($category)->formProps($category)['placeholder']);
        self::assertArrayNotHasKey('placeholder', UiFieldTypes::for($category)->formProps($category->required()));

        $status = Field::choice('status', ['a', 'b'])->set('control', 'select');
        self::assertSame('— None —', UiFieldTypes::for($status)->formProps($status)['placeholder']);
        self::assertArrayNotHasKey('placeholder', UiFieldTypes::for($status)->formProps($status->set('control', 'radio')));
    }

    #[Test]
    public function values_are_cast_to_what_is_stored(): void
    {
        self::assertNull(UiFieldTypes::for(Field::text('t'))->cast(Field::text('t'), '   '));
        self::assertSame('a', UiFieldTypes::for(Field::text('t'))->cast(Field::text('t'), ' a '));
        self::assertSame('hello-world', UiFieldTypes::for(Field::slug('s'))->cast(Field::slug('s'), 'Hello-World'));
        self::assertSame(-3, UiFieldTypes::for(Field::integer('n'))->cast(Field::integer('n'), '-3'));
        self::assertTrue(UiFieldTypes::for(Field::boolean('b'))->cast(Field::boolean('b'), '1'));
        self::assertFalse(UiFieldTypes::for(Field::boolean('b'))->cast(Field::boolean('b'), null));
        self::assertSame('2026-10-06', UiFieldTypes::for(Field::date('d'))->cast(Field::date('d'), '2026-10-06'));
        self::assertNull(UiFieldTypes::for(Field::date('d'))->cast(Field::date('d'), '2026-02-31'));
        $moment = UiFieldTypes::for(Field::datetime('m'))->cast(Field::datetime('m'), '2026-10-06T09:30');
        self::assertInstanceOf(\DateTimeImmutable::class, $moment);
        self::assertSame('2026-10-06 09:30 UTC', $moment->format('Y-m-d H:i T'));
        self::assertSame(['a', 'b'], UiFieldTypes::for(Field::multiChoice('m'))->cast(Field::multiChoice('m'), ['a', 'b', 'a', ['x']]));
        self::assertSame('12.50', UiFieldTypes::for(Field::decimal('p'))->cast(Field::decimal('p'), '12.50')); // a string: no float rounding
    }

    #[Test]
    public function a_moment_the_cast_cannot_read_is_refused_by_the_rules_not_stored_as_null(): void
    {
        // Without a rule, "tomorrow" passed `required` and cast() turned it
        // into null: a required date saved empty, an edit wiped the old one.
        $date = Field::date('d')->required();
        $moment = Field::datetime('m')->required();
        self::assertSame(['required', 'date'], UiFieldTypes::for($date)->rules($date));
        self::assertSame(['required', 'datetime'], UiFieldTypes::for($moment)->rules($moment));
    }

    #[Test]
    public function a_stored_column_suggests_its_field(): void
    {
        $infer = static fn (string $name, string $type, bool $nullable = false, ?int $length = null, bool $pk = false): UiField
            => UiFieldTypes::infer(new UiColumnShape($name, $type, $nullable, $length, null, $pk));

        self::assertSame('id', $infer('id', 'varchar', pk: true)->type);
        self::assertTrue($infer('id', 'varchar', pk: true)->readOnly);
        $title = $infer('title', 'varchar', length: 160);
        self::assertSame(['text', 160, true], [$title->type, $title->setting('max'), $title->required]);
        self::assertFalse($infer('subtitle', 'varchar', nullable: true)->required);
        self::assertSame('slug', $infer('slug', 'varchar')->type);
        self::assertSame('email', $infer('contactEmail', 'varchar')->type);
        self::assertSame('url', $infer('website', 'varchar')->type);
        self::assertSame('textarea', $infer('body', 'text')->type);
        self::assertSame('integer', $infer('views', 'int')->type);
        self::assertSame('decimal', $infer('price', 'decimal')->type);
        self::assertSame('boolean', $infer('published', 'boolean')->type);
        self::assertSame('date', $infer('birthday', 'date')->type);
        self::assertTrue($infer('updated_at', 'datetime')->readOnly);
        self::assertFalse($infer('publish_at', 'datetime')->readOnly);
        self::assertSame('json', $infer('meta', 'json')->type);
        self::assertSame('text', $infer('category_id', 'varchar')->type, 'a relation is never guessed from a name');
        self::assertSame('text', $infer('blob', 'mystery')->type, 'nothing claims it: plain text');
    }

    #[Test]
    public function the_field_set_is_the_contracts_ui_block(): void
    {
        $set = new UiFieldSet([
            Field::id(),
            Field::text('title')->sortable()->searchable(),
            Field::choice('status', ['draft' => ['label' => 'Draft', 'tone' => 'warning']])->filterable(),
            Field::textarea('body')->hideOnList(),
            Field::datetime('updatedAt')->sortable()->readOnly(),
        ]);
        $ui = $set->contractUi();

        self::assertSame(['id', 'title', 'status', 'updatedAt'], array_column($ui['columns'], 'field'));
        self::assertSame(['mono', 'text', 'badge', 'datetime'], array_column($ui['columns'], 'format'));
        self::assertSame(['status'], array_keys($ui['filters']));
        self::assertSame(['title', 'updatedAt'], $set->sortable());
        self::assertSame(['title'], $set->searchable());
        self::assertSame(['title', 'status', 'body'], array_map(static fn (UiField $f): string => $f->name, $set->onForm()));

        $this->expectException(\InvalidArgumentException::class);
        new UiFieldSet([Field::of('x', 'nonexistent')]);
    }

    #[Test]
    public function a_project_type_registers_and_a_duplicate_name_fails_boot(): void
    {
        UiFieldTypes::discover([StarsFieldTypeFixture::class]);
        self::assertSame('stars', UiFieldTypes::get('stars')->name());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declared twice');
        UiFieldTypes::discover([StarsFieldTypeFixture::class, ShadowTextFieldTypeFixture::class]);
    }
}

#[AsFieldType]
final class StarsFieldTypeFixture extends \Semitexa\PlatformUi\Application\Service\Field\Type\AbstractUiFieldType
{
    public function name(): string { return 'stars'; }
    public function description(): string { return 'A 1–5 rating.'; }
}

/** Claims a built-in's name: must be refused, not allowed to shadow it. */
#[AsFieldType]
final class ShadowTextFieldTypeFixture extends \Semitexa\PlatformUi\Application\Service\Field\Type\AbstractUiFieldType
{
    public function name(): string { return (new TextFieldType())->name(); }
    public function description(): string { return 'shadow'; }
}

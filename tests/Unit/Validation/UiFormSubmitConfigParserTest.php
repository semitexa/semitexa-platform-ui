<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitConfigParser;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;

final class UiFormSubmitConfigParserTest extends TestCase
{
    #[Test]
    public function parses_a_well_formed_two_field_definition(): void
    {
        $config = (new UiFormSubmitConfigParser())->parse([
            [
                'name' => 'access_code',
                'label' => 'Access code',
                'required' => true,
                'rules' => ['required', ['minLength', 4]],
            ],
            [
                'name' => 'confirm_access_code',
                'rules' => ['required', ['sameAsField', 'access_code', 'Codes must match.']],
            ],
        ]);

        self::assertCount(2, $config->fields);
        self::assertSame('access_code', $config->fields[0]->name);
        self::assertSame('Access code', $config->fields[0]->label);
        self::assertTrue($config->fields[0]->required);
        self::assertSame([
            ['n' => 'required'],
            ['n' => 'minLength', 'p' => [4]],
        ], $config->fields[0]->rules);

        self::assertSame('confirm_access_code', $config->fields[1]->name);
        self::assertNull($config->fields[1]->label);
        self::assertFalse($config->fields[1]->required);
        self::assertSame([
            ['n' => 'required'],
            ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
        ], $config->fields[1]->rules);
    }

    #[Test]
    public function produces_wire_shape_with_optional_keys_omitted(): void
    {
        $config = (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'rules' => ['required']],
            ['name' => 'b', 'rules' => [], 'label' => '', 'required' => false],
        ]);
        self::assertSame([
            ['n' => 'a', 'r' => [['n' => 'required']]],
            ['n' => 'b', 'r' => []],
        ], $config->toWireShape());
    }

    #[Test]
    public function rejects_non_list_root(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('plain list');
        (new UiFormSubmitConfigParser())->parse(['name' => 'oops']);
    }

    #[Test]
    public function rejects_unsafe_field_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('must match [A-Za-z_]');
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'evil name', 'rules' => ['required']],
        ]);
    }

    #[Test]
    public function rejects_missing_field_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFormSubmitConfigParser())->parse([
            ['rules' => ['required']],
        ]);
    }

    #[Test]
    public function rejects_unknown_rule_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('Unknown rule "nope"');
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'rules' => ['nope']],
        ]);
    }

    #[Test]
    public function rejects_invalid_rule_param_count(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('expects 1 parameter');
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'rules' => [['minLength']]],
        ]);
    }

    #[Test]
    public function rejects_non_string_label(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('label` must be a string');
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'rules' => [], 'label' => 123],
        ]);
    }

    #[Test]
    public function rejects_overlong_label(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('exceeds');
        (new UiFormSubmitConfigParser())->parse([
            [
                'name' => 'a',
                'rules' => [],
                'label' => str_repeat('a', UiFormSubmitConfigParser::MAX_LABEL_LENGTH + 1),
            ],
        ]);
    }

    #[Test]
    public function rejects_duplicate_field_names(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('duplicate');
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'rules' => []],
            ['name' => 'a', 'rules' => []],
        ]);
    }

    #[Test]
    public function rejects_too_many_fields(): void
    {
        $tooMany = [];
        for ($i = 0; $i <= UiFormSubmitConfigParser::MAX_FIELDS; $i++) {
            $tooMany[] = ['name' => 'f_' . $i, 'rules' => []];
        }
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('may not exceed');
        (new UiFormSubmitConfigParser())->parse($tooMany);
    }

    #[Test]
    public function parses_signed_wire_round_trip(): void
    {
        $original = (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'label' => 'A', 'required' => true, 'rules' => ['required']],
            ['name' => 'b', 'rules' => [['minLength', 3]]],
        ]);
        $wire = $original->toWireShape();
        $roundTripped = (new UiFormSubmitConfigParser())->parseSignedWire($wire);
        self::assertSame(2, count($roundTripped->fields));
        self::assertSame('a', $roundTripped->fields[0]->name);
        self::assertSame('A', $roundTripped->fields[0]->label);
        self::assertTrue($roundTripped->fields[0]->required);
        self::assertSame('b', $roundTripped->fields[1]->name);
        self::assertFalse($roundTripped->fields[1]->required);
    }

    #[Test]
    public function parse_signed_wire_rejects_unsafe_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFormSubmitConfigParser())->parseSignedWire([
            ['n' => 'evil name', 'r' => []],
        ]);
    }

    #[Test]
    public function parse_signed_wire_rejects_duplicate_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('duplicates');
        (new UiFormSubmitConfigParser())->parseSignedWire([
            ['n' => 'a', 'r' => []],
            ['n' => 'a', 'r' => []],
        ]);
    }

    #[Test]
    public function parse_signed_wire_rejects_malformed_rule(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('is malformed');
        (new UiFormSubmitConfigParser())->parseSignedWire([
            ['n' => 'a', 'r' => [['x' => 'y']]],   // missing "n"
        ]);
    }

    // ---------------------------------------------------------------
    // Per-field instance id (cfg.f.i)
    // ---------------------------------------------------------------

    #[Test]
    public function parses_field_with_safe_instance_id(): void
    {
        $config = (new UiFormSubmitConfigParser())->parse([
            [
                'name' => 'access_code',
                'instanceId' => 'uci_submit_access_code',
                'rules' => ['required'],
            ],
        ]);
        self::assertSame('uci_submit_access_code', $config->fields[0]->instanceId);
    }

    #[Test]
    public function parses_mixed_fields_some_with_instance_id_some_without(): void
    {
        $config = (new UiFormSubmitConfigParser())->parse([
            ['name' => 'with_id', 'instanceId' => 'uci_with_id', 'rules' => []],
            ['name' => 'without_id', 'rules' => []],
        ]);
        self::assertSame('uci_with_id', $config->fields[0]->instanceId);
        self::assertNull($config->fields[1]->instanceId);
    }

    #[Test]
    public function rejects_unsafe_instance_id_at_parse_time(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('safe instance-id shape');
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'instanceId' => 'oops without prefix', 'rules' => []],
        ]);
    }

    #[Test]
    public function rejects_unsafe_instance_id_with_angle_brackets(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'instanceId' => 'uci_<script>', 'rules' => []],
        ]);
    }

    #[Test]
    public function rejects_duplicate_instance_ids(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('duplicate instanceId');
        (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'instanceId' => 'uci_same', 'rules' => []],
            ['name' => 'b', 'instanceId' => 'uci_same', 'rules' => []],
        ]);
    }

    #[Test]
    public function wire_shape_includes_i_when_instance_id_set(): void
    {
        $config = (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'instanceId' => 'uci_a', 'rules' => ['required'], 'label' => 'A', 'required' => true],
        ]);
        self::assertSame([
            ['n' => 'a', 'i' => 'uci_a', 'r' => [['n' => 'required']], 'l' => 'A', 'q' => true],
        ], $config->toWireShape());
    }

    #[Test]
    public function wire_shape_omits_i_when_instance_id_absent(): void
    {
        $config = (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'rules' => ['required']],
        ]);
        self::assertSame([
            ['n' => 'a', 'r' => [['n' => 'required']]],
        ], $config->toWireShape());
    }

    #[Test]
    public function parse_signed_wire_round_trips_instance_id(): void
    {
        $original = (new UiFormSubmitConfigParser())->parse([
            ['name' => 'a', 'instanceId' => 'uci_one', 'rules' => ['required']],
            ['name' => 'b', 'instanceId' => 'uci_two', 'rules' => []],
        ]);
        $roundTripped = (new UiFormSubmitConfigParser())->parseSignedWire($original->toWireShape());
        self::assertSame('uci_one', $roundTripped->fields[0]->instanceId);
        self::assertSame('uci_two', $roundTripped->fields[1]->instanceId);
    }

    #[Test]
    public function parse_signed_wire_rejects_unsafe_i(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('not a safe instance id');
        (new UiFormSubmitConfigParser())->parseSignedWire([
            ['n' => 'a', 'i' => 'evil id', 'r' => []],
        ]);
    }

    #[Test]
    public function parse_signed_wire_rejects_duplicate_i(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('duplicates instanceId');
        (new UiFormSubmitConfigParser())->parseSignedWire([
            ['n' => 'a', 'i' => 'uci_same', 'r' => []],
            ['n' => 'b', 'i' => 'uci_same', 'r' => []],
        ]);
    }

    #[Test]
    public function parse_signed_wire_backward_compatible_with_missing_i(): void
    {
        // Old submit ctxs (pre-this-slice) have no `i` key. Parser
        // must continue accepting them; the FormComponent handler
        // skips per-field projection for fields without instanceId.
        $config = (new UiFormSubmitConfigParser())->parseSignedWire([
            ['n' => 'a', 'r' => [['n' => 'required']]],
            ['n' => 'b', 'r' => []],
        ]);
        self::assertNull($config->fields[0]->instanceId);
        self::assertNull($config->fields[1]->instanceId);
    }
}

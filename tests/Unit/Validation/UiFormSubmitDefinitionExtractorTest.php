<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitDefinitionExtractor;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;

/**
 * Marker extraction contract for FormComponent autoFields.
 *
 * Pins:
 *   - empty HTML yields an empty config without throwing;
 *   - in-order capture of multiple markers;
 *   - safe-name + safe-instance-id enforced;
 *   - duplicate names / duplicate instance ids rejected;
 *   - unrelated `<script>` blocks are NOT consumed;
 *   - extract output strips markers cleanly;
 *   - `<\/` escape inside payload is reversed before json_decode.
 */
final class UiFormSubmitDefinitionExtractorTest extends TestCase
{
    private function marker(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $json = str_replace('</', '<\\/', $json);
        return '<script type="application/json" data-ui-field-submit-definition>' . $json . '</script>';
    }

    #[Test]
    public function html_without_markers_yields_empty_config(): void
    {
        $html = '<div>nothing relevant here</div>';
        $result = (new UiFormSubmitDefinitionExtractor())->extract($html);
        self::assertSame([], $result['config']->toWireShape());
        self::assertSame($html, $result['html']);
    }

    #[Test]
    public function single_marker_extracts_into_one_definition(): void
    {
        $html = '<div>' . $this->marker([
            'n' => 'access_code',
            'i' => 'uci_access_x',
            'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [4]]],
            'l' => 'Access code',
            'q' => true,
        ]) . '</div>';
        $result = (new UiFormSubmitDefinitionExtractor())->extract($html);
        $wire = $result['config']->toWireShape();
        self::assertCount(1, $wire);
        self::assertSame('access_code', $wire[0]['n']);
        self::assertSame('uci_access_x', $wire[0]['i']);
        self::assertTrue($wire[0]['q']);
        self::assertSame('Access code', $wire[0]['l']);
    }

    #[Test]
    public function multiple_markers_extract_in_render_order(): void
    {
        $html =
            $this->marker(['n' => 'alpha', 'i' => 'uci_a', 'r' => [['n' => 'required']]]) .
            $this->marker(['n' => 'beta',  'i' => 'uci_b', 'r' => [['n' => 'required']]]) .
            $this->marker(['n' => 'gamma', 'i' => 'uci_c', 'r' => [['n' => 'required']]]);
        $wire = (new UiFormSubmitDefinitionExtractor())->extract($html)['config']->toWireShape();
        self::assertSame(['alpha', 'beta', 'gamma'], array_column($wire, 'n'));
        self::assertSame(['uci_a', 'uci_b', 'uci_c'], array_column($wire, 'i'));
    }

    #[Test]
    public function extract_strips_markers_from_returned_html(): void
    {
        $html = '<div data-keep="1">'
            . $this->marker(['n' => 'a', 'i' => 'uci_a', 'r' => [['n' => 'required']]])
            . '</div>';
        $result = (new UiFormSubmitDefinitionExtractor())->extract($html);
        self::assertStringNotContainsString('data-ui-field-submit-definition', $result['html']);
        self::assertStringContainsString('data-keep="1"', $result['html']);
    }

    #[Test]
    public function strip_markers_is_idempotent_on_clean_html(): void
    {
        $html = '<div>no markers here</div>';
        self::assertSame($html, (new UiFormSubmitDefinitionExtractor())->stripMarkers($html));
    }

    #[Test]
    public function unrelated_script_blocks_are_not_consumed(): void
    {
        // Event manifest scripts use a different data attribute.
        // The extractor must leave them alone.
        $manifest = '<script type="application/json" data-ui-event-manifest="uci_form_x" data-ui-component="platform.form">{"v":1}</script>';
        $html = $manifest . $this->marker(['n' => 'a', 'i' => 'uci_a', 'r' => []]);
        $result = (new UiFormSubmitDefinitionExtractor())->extract($html);
        self::assertCount(1, $result['config']->toWireShape());
        self::assertStringContainsString('data-ui-event-manifest', $result['html']);
    }

    #[Test]
    public function unsafe_name_in_marker_is_rejected(): void
    {
        $html = $this->marker(['n' => 'has space', 'i' => 'uci_a', 'r' => []]);
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFormSubmitDefinitionExtractor())->extract($html);
    }

    #[Test]
    public function unsafe_instance_id_in_marker_is_rejected(): void
    {
        $html = $this->marker(['n' => 'safe', 'i' => 'evil"id', 'r' => []]);
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFormSubmitDefinitionExtractor())->extract($html);
    }

    #[Test]
    public function duplicate_field_name_is_rejected(): void
    {
        $html =
            $this->marker(['n' => 'dup', 'i' => 'uci_a', 'r' => [['n' => 'required']]]) .
            $this->marker(['n' => 'dup', 'i' => 'uci_b', 'r' => [['n' => 'required']]]);
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/duplicates field name/i');
        (new UiFormSubmitDefinitionExtractor())->extract($html);
    }

    #[Test]
    public function duplicate_instance_id_is_rejected(): void
    {
        $html =
            $this->marker(['n' => 'alpha', 'i' => 'uci_same', 'r' => [['n' => 'required']]]) .
            $this->marker(['n' => 'beta',  'i' => 'uci_same', 'r' => [['n' => 'required']]]);
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/duplicates instanceId/i');
        (new UiFormSubmitDefinitionExtractor())->extract($html);
    }

    #[Test]
    public function malformed_marker_json_is_rejected(): void
    {
        $html = '<script type="application/json" data-ui-field-submit-definition>not-json{</script>';
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/malformed JSON/i');
        (new UiFormSubmitDefinitionExtractor())->extract($html);
    }

    #[Test]
    public function marker_payload_carries_no_raw_values_or_class_names(): void
    {
        $html = $this->marker([
            'n' => 'token',
            'i' => 'uci_token_x',
            'r' => [['n' => 'required']],
            'l' => 'Token',
            'q' => true,
        ]);
        $wire = (new UiFormSubmitDefinitionExtractor())->extract($html)['config']->toWireShape();
        $serialized = json_encode($wire, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Semitexa\\\\', $serialized);
        self::assertStringNotContainsString('FieldComponent', $serialized);
        self::assertStringNotContainsString('Validator', $serialized);
    }
}

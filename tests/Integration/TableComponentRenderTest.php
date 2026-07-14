<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\TableComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class TableComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(TableComponent::class),
        );

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->twig = new TwigEnvironment($loader, [
            'cache' => false,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addFunction(new TwigFunction(
            'slot',
            static function (array $context, string $name): Markup {
                $slots = $context['_slots'] ?? [];
                $value = is_array($slots) ? ($slots[$name] ?? null) : null;
                return new Markup($value === null ? '' : (string) $value, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $slots
     */
    private function render(array $props = [], array $slots = []): string
    {
        return $this->twig->render(
            '@platform-ui/components/runtime/table.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.table', 'class' => TableComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleProps(): array
    {
        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'total', 'label' => 'Total', 'align' => 'end'],
            ],
            'rows' => [
                ['name' => 'Alice', 'total' => '12'],
                ['name' => 'Bob', 'total' => '7'],
            ],
        ];
    }

    #[Test]
    public function registers_toolbar_and_empty_slots(): void
    {
        $meta = UiComponentRegistry::get('platform.table');
        self::assertNotNull($meta);
        $slots = array_keys($meta->slots);
        sort($slots);
        self::assertSame(['empty', 'toolbar'], $slots);
    }

    #[Test]
    public function renders_headers_from_columns_and_cells_by_key(): void
    {
        $html = $this->render($this->sampleProps());

        self::assertStringContainsString('data-ui-component="platform.table"', $html);
        self::assertStringContainsString('<th scope="col">Name</th>', $html);
        self::assertStringContainsString('<th scope="col" ui-align="end">Total</th>', $html);
        self::assertStringContainsString('<td>Alice</td>', $html);
        self::assertStringContainsString('<td ui-align="end">12</td>', $html);
        self::assertStringContainsString('<td>Bob</td>', $html);
    }

    #[Test]
    public function header_falls_back_to_key_when_no_label(): void
    {
        $html = $this->render([
            'columns' => [['key' => 'sku']],
            'rows' => [['sku' => 'X-1']],
        ]);
        self::assertStringContainsString('<th scope="col">sku</th>', $html);
    }

    #[Test]
    public function caption_renders_when_provided(): void
    {
        $html = $this->render($this->sampleProps() + ['caption' => 'Q1 totals']);
        self::assertStringContainsString('<caption>Q1 totals</caption>', $html);
    }

    #[Test]
    public function empty_slot_shows_when_no_rows_and_spans_all_columns(): void
    {
        $html = $this->render(
            ['columns' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']], 'rows' => []],
            ['empty' => '<em data-test="none">Nothing here</em>'],
        );

        self::assertStringContainsString('ui-table="empty" colspan="2"', $html);
        self::assertStringContainsString('data-test="none"', $html);
    }

    #[Test]
    public function missing_cell_value_renders_empty_not_error(): void
    {
        $html = $this->render([
            'columns' => [['key' => 'a', 'label' => 'A'], ['key' => 'missing', 'label' => 'M']],
            'rows' => [['a' => 'x']],
        ]);
        self::assertStringContainsString('<td>x</td>', $html);
        self::assertStringContainsString('<td></td>', $html);
    }

    #[Test]
    public function toolbar_slot_renders_above_the_table(): void
    {
        $html = $this->render(
            $this->sampleProps(),
            ['toolbar' => '<input data-test="search">'],
        );
        $toolbar = strpos($html, 'data-test="search"');
        $table = strpos($html, '<table');
        self::assertNotFalse($toolbar);
        self::assertNotFalse($table);
        self::assertLessThan($table, $toolbar, 'toolbar renders before the table');
    }
}

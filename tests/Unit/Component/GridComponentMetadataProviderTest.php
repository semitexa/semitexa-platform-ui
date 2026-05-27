<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\PlatformUi\Application\Service\Component\GridComponentMetadataProvider;
use Semitexa\PlatformUi\Attribute\AsColumn;
use Semitexa\PlatformUi\Attribute\AsFilter;
use Semitexa\PlatformUi\Attribute\WithPagination;

final class GridComponentMetadataProviderTest extends TestCase
{
    #[Test]
    public function supports_returns_true_when_property_has_as_column(): void
    {
        $provider = new GridComponentMetadataProvider();

        self::assertTrue($provider->supports(new ReflectionClass(GridWithColumnsFixture::class)));
    }

    #[Test]
    public function supports_returns_true_when_class_has_with_pagination(): void
    {
        $provider = new GridComponentMetadataProvider();

        self::assertTrue($provider->supports(new ReflectionClass(GridWithPaginationOnlyFixture::class)));
    }

    #[Test]
    public function supports_returns_false_for_plain_class(): void
    {
        $provider = new GridComponentMetadataProvider();

        self::assertFalse($provider->supports(new ReflectionClass(PlainComponentFixture::class)));
    }

    #[Test]
    public function resolves_columns_in_declaration_order(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithColumnsFixture::class));

        self::assertSame(
            [
                ['key' => 'submittedAt', 'label' => 'Submitted', 'sortable' => true, 'type' => 'datetime'],
                ['key' => 'email', 'label' => 'Email', 'sortable' => false, 'type' => 'text'],
            ],
            $props['columns'],
        );
    }

    #[Test]
    public function resolves_filters_in_declaration_order(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithFiltersFixture::class));

        self::assertSame(
            [
                ['field' => 'q', 'type' => 'search', 'placeholder' => 'Search…', 'label' => 'Search'],
                ['field' => 'action', 'type' => 'select', 'placeholder' => '', 'label' => 'Action'],
            ],
            $props['filters'],
        );
    }

    #[Test]
    public function pagination_falls_back_to_defaults_when_attribute_absent(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithColumnsFixture::class));

        self::assertSame(
            [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50],
                'mode'               => 'cursor',
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ],
            $props['pagination'],
        );
    }

    #[Test]
    public function pagination_reads_attribute_when_present(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithPaginationOnlyFixture::class));

        // No mode declared → cursor defaults preserved (backward compat).
        self::assertSame(
            [
                'defaultLimit'       => 50,
                'limitOptions'       => [25, 50, 100],
                'mode'               => 'cursor',
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ],
            $props['pagination'],
        );
    }

    #[Test]
    public function pagination_surfaces_mode_window_size_and_threshold(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithAutoPaginationFixture::class));

        self::assertSame(
            [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50],
                'mode'               => 'auto',
                'windowSize'         => 7,
                'autoCountThreshold' => 500,
            ],
            $props['pagination'],
        );
    }

    #[Test]
    public function pagination_throws_when_default_limit_is_not_in_limit_options(): void
    {
        $provider = new GridComponentMetadataProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/defaultLimit 30 must be in limitOptions/');

        $provider->getProps(new ReflectionClass(BadPaginationFixture::class));
    }

    #[Test]
    public function full_grid_returns_merged_columns_filters_and_pagination(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(FullGridFixture::class));

        self::assertSame(
            [
                ['key' => 'submittedAt', 'label' => 'Submitted', 'sortable' => true, 'type' => 'datetime'],
                ['key' => 'email', 'label' => 'Email', 'sortable' => false, 'type' => 'text'],
            ],
            $props['columns'],
        );
        self::assertSame(
            [
                ['field' => 'q', 'type' => 'search', 'placeholder' => 'Search…', 'label' => ''],
            ],
            $props['filters'],
        );
        self::assertSame(
            [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50, 100],
                'mode'               => 'cursor',
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ],
            $props['pagination'],
        );
    }
}

final class PlainComponentFixture
{
    public string $title = '';
}

final class GridWithColumnsFixture
{
    #[AsColumn(label: 'Submitted', sortable: true, type: 'datetime')]
    public string $submittedAt = '';

    #[AsColumn(label: 'Email')]
    public string $email = '';
}

final class GridWithFiltersFixture
{
    #[AsFilter(type: 'search', placeholder: 'Search…', label: 'Search')]
    public string $q = '';

    #[AsFilter(type: 'select', label: 'Action')]
    public string $action = '';
}

#[WithPagination(defaultLimit: 50, limitOptions: [25, 50, 100])]
final class GridWithPaginationOnlyFixture
{
}

#[WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50], mode: 'auto', windowSize: 7, autoCountThreshold: 500)]
final class GridWithAutoPaginationFixture
{
}

#[WithPagination(defaultLimit: 30, limitOptions: [10, 25, 50])]
final class BadPaginationFixture
{
}

#[WithPagination]
final class FullGridFixture
{
    #[AsColumn(label: 'Submitted', sortable: true, type: 'datetime')]
    public string $submittedAt = '';

    #[AsColumn(label: 'Email')]
    public string $email = '';

    #[AsFilter(type: 'search', placeholder: 'Search…')]
    public string $q = '';
}

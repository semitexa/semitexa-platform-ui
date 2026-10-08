<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Field;

/**
 * Short constructors for the built-in field types: `Field::text('title')`.
 * A project type (#[AsFieldType]) is `Field::of('rating', 'stars')`.
 */
final class Field
{
    public static function of(string $name, string $type): UiField { return new UiField($name, $type); }

    public static function text(string $name): UiField { return new UiField($name, 'text'); }
    /** Hidden from grid lists by default: long text does not belong in a column. */
    public static function textarea(string $name): UiField { return (new UiField($name, 'textarea'))->hideOnList(); }
    public static function slug(string $name): UiField { return new UiField($name, 'slug'); }
    public static function email(string $name): UiField { return new UiField($name, 'email'); }
    public static function url(string $name): UiField { return new UiField($name, 'url'); }
    public static function integer(string $name): UiField { return new UiField($name, 'integer'); }
    public static function decimal(string $name, int $scale = 2): UiField { return (new UiField($name, 'decimal'))->set('scale', $scale); }
    public static function boolean(string $name): UiField { return new UiField($name, 'boolean'); }

    /** @param array<string|int, string|array{label?: string, tone?: string}> $options */
    public static function choice(string $name, array $options = []): UiField { return (new UiField($name, 'choice'))->options($options); }

    /** @param array<string|int, string|array{label?: string, tone?: string}> $options */
    public static function multiChoice(string $name, array $options = []): UiField { return (new UiField($name, 'multiChoice'))->options($options); }

    public static function date(string $name): UiField { return new UiField($name, 'date'); }
    public static function datetime(string $name): UiField { return new UiField($name, 'datetime'); }
    public static function belongsTo(string $name): UiField { return new UiField($name, 'belongsTo'); }
    public static function belongsToMany(string $name): UiField { return new UiField($name, 'belongsToMany'); }
    public static function file(string $name): UiField { return new UiField($name, 'file'); }
    public static function image(string $name): UiField { return new UiField($name, 'image'); }
    public static function json(string $name): UiField { return (new UiField($name, 'json'))->readOnly(); }
    public static function id(string $name = 'id'): UiField { return (new UiField($name, 'id'))->label($name === 'id' ? 'ID' : UiField::humanize($name))->readOnly(); }
}

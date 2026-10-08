<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\PlatformUi\Application\Service\Field\Type;
use Semitexa\PlatformUi\Attribute\AsFieldType;
use Semitexa\PlatformUi\Domain\Contract\UiFieldTypeInterface;
use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * The field-type registry: the built-in types, plus every #[AsFieldType]
 * class discovered at boot. Types are stateless, so one instance per worker
 * serves every request.
 */
final class UiFieldTypes
{
    /** @var array<string, UiFieldTypeInterface>|null */
    #[WorkerState('Stateless field types, registered once at boot.')]
    private static ?array $types = null;

    /** @return list<UiFieldTypeInterface> */
    public static function builtin(): array
    {
        return [
            new Type\IdFieldType(), new Type\TextFieldType(), new Type\TextareaFieldType(), new Type\SlugFieldType(),
            new Type\EmailFieldType(), new Type\UrlFieldType(), new Type\IntegerFieldType(), new Type\DecimalFieldType(),
            new Type\BooleanFieldType(), new Type\ChoiceFieldType(), new Type\MultiChoiceFieldType(), new Type\DateFieldType(),
            new Type\DatetimeFieldType(), new Type\BelongsToFieldType(), new Type\BelongsToManyFieldType(),
            new Type\FileFieldType(), new Type\ImageFieldType(), new Type\JsonFieldType(),
        ];
    }

    /**
     * Register the built-ins and the project's #[AsFieldType] classes.
     *
     * @param iterable<class-string> $classes
     */
    public static function discover(iterable $classes): void
    {
        $types = [];
        foreach (self::builtin() as $type) {
            $types[$type->name()] = $type;
        }
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->getAttributes(AsFieldType::class) === []) {
                continue;
            }
            if (!$reflection->implementsInterface(UiFieldTypeInterface::class)) {
                throw new \LogicException(sprintf('#[AsFieldType] class %s must implement %s.', $class, UiFieldTypeInterface::class));
            }
            /** @var UiFieldTypeInterface $type */
            $type = $reflection->newInstance();
            if (isset($types[$type->name()])) {
                throw new \LogicException(sprintf('Field type "%s" is declared twice (%s and %s).', $type->name(), $types[$type->name()]::class, $class));
            }
            $types[$type->name()] = $type;
        }
        self::$types = $types;
    }

    public static function get(string $name): UiFieldTypeInterface
    {
        $types = self::all();
        if (!isset($types[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown field type "%s". Known: %s.', $name, implode(', ', array_keys($types))));
        }

        return $types[$name];
    }

    public static function for(UiField $field): UiFieldTypeInterface
    {
        return self::get($field->type);
    }

    /** @return array<string, UiFieldTypeInterface> */
    public static function all(): array
    {
        if (self::$types === null) {
            self::discover([]);
        }

        return self::$types ?? [];
    }

    /**
     * The field a stored column suggests: the type most sure of it wins; plain
     * text when none claims it. A tie goes to the earlier registration, so the
     * built-ins are stable and a project type has to be surer to win.
     */
    public static function infer(UiColumnShape $column): UiField
    {
        $best = null;
        $score = 0;
        foreach (self::all() as $type) {
            $mine = $type->infers($column);
            if ($mine > $score) {
                [$best, $score] = [$type, $mine];
            }
        }

        return ($best ?? self::get('text'))->fromColumn($column);
    }

    /** Test seam: forget discovered types (the built-ins return on next use). */
    public static function reset(): void
    {
        self::$types = null;
    }
}

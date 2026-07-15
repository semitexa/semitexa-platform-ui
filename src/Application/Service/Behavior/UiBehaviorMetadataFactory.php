<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior;

use ReflectionClass;
use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Exception\BehaviorRegistryException;
use Semitexa\PlatformUi\Domain\Model\Behavior\BehaviorMetadata;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * Reads #[AsUiBehavior] off a class and produces an immutable BehaviorMetadata.
 *
 * Validates: non-empty name/ui matching the shared identity patterns, alias
 * derivation from $name's last dot-segment when omitted, option-name uniqueness
 * and shape (Enum options must declare a non-empty value set), and a11y/requires
 * list hygiene. Mirrors UiPrimitiveMetadataFactory.
 */
final class UiBehaviorMetadataFactory
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_.\-]*$/i';
    private const UI_PATTERN = '/^[a-z][a-z0-9_\-]*$/i';
    private const OPTION_PATTERN = '/^[a-z][a-z0-9_\-]*$/i';

    /**
     * @param class-string $class
     */
    public function fromClass(string $class): BehaviorMetadata
    {
        $reflection = new ReflectionClass($class);
        $attrs = $reflection->getAttributes(AsUiBehavior::class);

        if ($attrs === []) {
            throw new BehaviorRegistryException(sprintf(
                'Class %s is not marked with #[AsUiBehavior].',
                $class,
            ));
        }

        if (count($attrs) > 1) {
            throw new BehaviorRegistryException(sprintf(
                'Class %s declares #[AsUiBehavior] more than once.',
                $class,
            ));
        }

        /** @var AsUiBehavior $attr */
        $attr = $attrs[0]->newInstance();

        return $this->fromAttribute($class, $attr);
    }

    public function fromAttribute(string $class, AsUiBehavior $attr): BehaviorMetadata
    {
        $name = trim($attr->name);
        if ($name === '') {
            throw new BehaviorRegistryException(sprintf('Behavior %s declares an empty name.', $class));
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new BehaviorRegistryException(sprintf(
                'Behavior %s declares invalid name "%s" — must match %s.',
                $class,
                $name,
                self::NAME_PATTERN,
            ));
        }

        $ui = $attr->ui !== null ? trim($attr->ui) : null;
        if ($ui === '' || $ui === null) {
            $ui = self::deriveUiFromName($name);
        }

        if (preg_match(self::UI_PATTERN, $ui) !== 1) {
            throw new BehaviorRegistryException(sprintf(
                'Behavior %s declares invalid ui alias "%s" — must match %s.',
                $class,
                $ui,
                self::UI_PATTERN,
            ));
        }

        $script = self::stringOrNull($attr->script);

        $options = [];
        $seenOptionNames = [];
        foreach ($attr->options as $index => $option) {
            if (!$option instanceof UiBehaviorOption) {
                throw new BehaviorRegistryException(sprintf(
                    'Behavior %s option #%d must be an instance of %s.',
                    $class,
                    $index,
                    UiBehaviorOption::class,
                ));
            }

            $optionName = trim($option->name);
            if ($optionName === '' || preg_match(self::OPTION_PATTERN, $optionName) !== 1) {
                throw new BehaviorRegistryException(sprintf(
                    'Behavior %s declares invalid option name "%s" — must match %s.',
                    $class,
                    $option->name,
                    self::OPTION_PATTERN,
                ));
            }

            if (isset($seenOptionNames[$optionName])) {
                throw new BehaviorRegistryException(sprintf(
                    'Behavior %s declares option "%s" more than once.',
                    $class,
                    $optionName,
                ));
            }
            $seenOptionNames[$optionName] = true;

            if ($option->type === UiOptionType::Enum && $option->values === []) {
                throw new BehaviorRegistryException(sprintf(
                    'Behavior %s option "%s" is an Enum but declares no allowed values.',
                    $class,
                    $optionName,
                ));
            }

            // Store with the validated (trimmed) name so the DSL attribute the
            // client coerces matches exactly what toDescriptor() ships — never
            // the raw, possibly-padded, attribute value.
            $options[] = $option->name === $optionName
                ? $option
                : new UiBehaviorOption(
                    $optionName,
                    $option->type,
                    $option->default,
                    $option->values,
                    $option->description,
                );
        }

        return new BehaviorMetadata(
            class: $class,
            name: $name,
            ui: $ui,
            script: $script,
            options: $options,
            a11y: self::stringList($attr->a11y),
            requires: self::stringList($attr->requires),
        );
    }

    public static function deriveUiFromName(string $name): string
    {
        $pos = strrpos($name, '.');
        $tail = $pos === false ? $name : substr($name, $pos + 1);

        return $tail === '' ? $name : $tail;
    }

    private static function stringOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed !== '' && !in_array($trimmed, $out, true)) {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}

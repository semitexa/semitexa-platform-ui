<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use ReflectionClass;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Throwable;

/**
 * Deterministic part-prop resolver.
 *
 * Resolution order (composition + bind slice — no events, no live data):
 *
 *   1. Part defaults from #[UiPart(defaults: [...])].
 *   2. The #[ProvidesUiPart] provider method's return value, if a provider
 *      is declared. The provider receives the unmodified caller component
 *      props as its argument.
 *   3. Bind-derived value from #[UiPart(bind: '<path>')], if a bind path
 *      is declared AND the path resolves to a non-null value inside the
 *      caller props. Only the `value` key is projected by this step — bind
 *      is value-only for now (no checked / selected / etc.).
 *   4. Caller overrides — the $overrides argument supplied by the
 *      component template (typically the caller's `<part>Props` map,
 *      e.g. `inputProps`).
 *
 * Each step's keys overwrite the previous step. `null` values from a later
 * step still overwrite earlier non-null values — pruning happens inside
 * the primitive template, not here. A bind path that resolves to `null`
 * (missing segment / intermediate non-array) leaves the provider-supplied
 * value untouched; explicit-null caller overrides still win.
 *
 * The resolver is stateless and pure. Providers are expected to be pure
 * in this slice; the resolver does not catch their throwables.
 */
final class UiPartPropResolver
{
    /**
     * @param array<string, mixed> $componentProps
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function resolve(
        UiComponentMetadata $metadata,
        string $partName,
        array $componentProps,
        array $overrides = [],
        ?object $componentInstance = null,
    ): array {
        $part = $metadata->part($partName);
        if ($part === null) {
            throw new UiComponentRegistryException(sprintf(
                'Component "%s" has no part named "%s".',
                $metadata->name,
                $partName,
            ));
        }

        // 1. Part defaults.
        $resolved = $part->defaults;

        // 2. Provider result.
        $providerMeta = $metadata->provider($partName);
        if ($providerMeta !== null) {
            $instance = $componentInstance ?? self::instantiate($providerMeta->class);

            try {
                /** @var mixed $providerResult */
                $providerResult = $instance->{$providerMeta->method}($componentProps);
            } catch (Throwable $e) {
                throw new UiComponentRegistryException(sprintf(
                    'Provider %s::%s for part "%s" of component "%s" threw: %s',
                    $providerMeta->class,
                    $providerMeta->method,
                    $partName,
                    $metadata->name,
                    $e->getMessage(),
                ), 0, $e);
            }

            if (!is_array($providerResult)) {
                throw new UiComponentRegistryException(sprintf(
                    'Provider %s::%s for part "%s" of component "%s" must return array, got %s.',
                    $providerMeta->class,
                    $providerMeta->method,
                    $partName,
                    $metadata->name,
                    get_debug_type($providerResult),
                ));
            }

            $resolved = array_replace($resolved, $providerResult);
        }

        // 3. Bind-derived value. Bind owns the `value` key when the path
        //    resolves to a non-null value. Missing/null bind values do
        //    NOT clobber whatever the provider produced.
        if ($part->bind !== null) {
            /** @var mixed $bound */
            $bound = $part->bind->resolve($componentProps);
            if ($bound !== null) {
                $resolved['value'] = $bound;
            }
        }

        // 4. Caller overrides — last word.
        if ($overrides !== []) {
            $resolved = array_replace($resolved, $overrides);
        }

        return $resolved;
    }

    /** @return object */
    private static function instantiate(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $ctor = $reflection->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            throw new UiComponentRegistryException(sprintf(
                'Cannot instantiate component %s for provider invocation: constructor requires %d argument(s). In this slice components must have a no-arg or default-only constructor.',
                $class,
                $ctor->getNumberOfRequiredParameters(),
            ));
        }
        return $reflection->newInstance();
    }
}

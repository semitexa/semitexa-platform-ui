<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

/**
 * Immutable discovered metadata for one #[ProvidesUiPart] method.
 *
 * The pair (class, method) is enough to invoke the provider — the resolver
 * instantiates the component (or uses an injected instance) and calls
 * `$instance->{$method}($props)`.
 */
final readonly class UiPartProviderMetadata
{
    public function __construct(
        public string $part,
        /** @var class-string */
        public string $class,
        public string $method,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Extractor;

final readonly class UsageManifest
{
    /**
     * @param list<array{attr: string, value: string}> $attrs Resolved sx/ui attribute usage
     * @param list<string> $primitives Primitive ids used via ui="<id>"
     * @param list<string> $unresolved Dynamic or invalid values that could not be matched
     */
    public function __construct(
        public array $attrs,
        public array $primitives,
        public array $unresolved = [],
    ) {
    }

    public function applyTo(\Semitexa\PlatformUi\Application\Service\Asset\SliceRegistry $registry): void
    {
        foreach ($this->attrs as $pair) {
            $registry->registerGrammar($pair['attr'], $pair['value']);
        }
        foreach ($this->primitives as $id) {
            $registry->registerPrimitive($id);
        }
    }
}

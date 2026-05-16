<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Css\Slice;

use Semitexa\PlatformUi\Contract\SliceEmitter;
use Semitexa\PlatformUi\Css\Emitter\AlignEmitter;
use Semitexa\PlatformUi\Css\Emitter\GapEmitter;
use Semitexa\PlatformUi\Css\Emitter\JustifyEmitter;
use Semitexa\PlatformUi\Css\Emitter\LayoutEmitter;
use Semitexa\PlatformUi\Css\Emitter\PaddingEmitter;
use Semitexa\PlatformUi\Css\Emitter\RadiusEmitter;
use Semitexa\PlatformUi\Css\Emitter\SurfaceEmitter;
use Semitexa\PlatformUi\Css\Emitter\TextEmitter;
use Semitexa\PlatformUi\Css\Emitter\ToneEmitter;

final class SliceCatalog
{
    /** @var array<string, SliceEmitter> */
    private array $emitters = [];

    public static function withDefaults(): self
    {
        $catalog = new self();
        $catalog->register(new LayoutEmitter());
        $catalog->register(new GapEmitter());
        $catalog->register(new PaddingEmitter());
        $catalog->register(new RadiusEmitter());
        $catalog->register(new SurfaceEmitter());
        $catalog->register(new ToneEmitter());
        $catalog->register(new TextEmitter());
        $catalog->register(new AlignEmitter());
        $catalog->register(new JustifyEmitter());
        return $catalog;
    }

    public function register(SliceEmitter $emitter): void
    {
        $this->emitters[$emitter->attribute()] = $emitter;
    }

    public function emit(string $attribute, string $value): Slice
    {
        $emitter = $this->emitters[$attribute]
            ?? throw new \OutOfBoundsException("Unknown attribute: {$attribute}");
        return $emitter->emit($value);
    }

    public function emitter(string $attribute): ?SliceEmitter
    {
        return $this->emitters[$attribute] ?? null;
    }

    /** @return list<string> */
    public function attributes(): array
    {
        return array_keys($this->emitters);
    }

    /** @return iterable<Slice> All possible slices across registered emitters. */
    public function emitAll(): iterable
    {
        foreach ($this->emitters as $emitter) {
            foreach ($emitter->allowedValues() as $value) {
                yield $emitter->emit($value);
            }
        }
    }
}

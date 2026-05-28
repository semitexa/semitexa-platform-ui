<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css;

final class PrimitiveRegistry
{
    /** @var array<string, Primitive> */
    private array $primitives;

    public function __construct()
    {
        $this->primitives = [];
        foreach (self::defaults() as $primitive) {
            $this->primitives[$primitive->id] = $primitive;
        }
    }

    public function get(string $id): ?Primitive
    {
        return $this->primitives[$id] ?? null;
    }

    /** @return list<Primitive> */
    public function all(): array
    {
        return array_values($this->primitives);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->primitives);
    }

    /** @return list<Primitive> */
    private static function defaults(): array
    {
        $base = dirname(__DIR__, 4) . '/resources';
        return [
            new Primitive(
                id: 'button',
                cssPath: $base . '/primitives/button.css',
                twigPath: $base . '/twig/primitives/button.twig',
                variants: ['solid', 'soft', 'ghost'],
                tones: ['neutral', 'brand', 'success', 'warning', 'danger'],
                sizes: ['sm', 'md', 'lg'],
            ),
            new Primitive(
                id: 'input',
                cssPath: $base . '/primitives/input.css',
                twigPath: $base . '/twig/primitives/input.twig',
                states: ['default', 'invalid'],
                sizes: ['sm', 'md', 'lg'],
            ),
            new Primitive(
                id: 'label',
                cssPath: $base . '/primitives/label.css',
                twigPath: $base . '/twig/primitives/label.twig',
                sizes: ['sm', 'md', 'lg'],
            ),
            new Primitive(
                id: 'field-shell',
                cssPath: $base . '/primitives/field-shell.css',
                twigPath: $base . '/twig/primitives/field-shell.twig',
                states: ['default', 'invalid'],
                sizes: ['sm', 'md', 'lg'],
            ),
            new Primitive(
                id: 'surface',
                cssPath: $base . '/primitives/surface.css',
                twigPath: $base . '/twig/primitives/surface.twig',
            ),
            new Primitive(
                id: 'badge',
                cssPath: $base . '/primitives/badge.css',
                twigPath: $base . '/twig/primitives/badge.twig',
                variants: ['solid', 'soft'],
                tones: ['neutral', 'brand', 'success', 'warning', 'danger'],
            ),
        ];
    }
}

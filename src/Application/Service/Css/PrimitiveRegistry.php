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
                variants: ['solid', 'soft', 'outline', 'ghost', 'link'],
                tones: ['neutral', 'brand', 'info', 'success', 'warning', 'danger'],
                sizes: ['sm', 'md', 'lg'],
                states: ['default', 'loading'],
            ),
            new Primitive(
                id: 'input',
                cssPath: $base . '/primitives/input.css',
                twigPath: $base . '/twig/primitives/input.twig',
                states: ['default', 'invalid'],
                sizes: ['sm', 'md', 'lg'],
            ),
            new Primitive(
                id: 'select',
                cssPath: $base . '/primitives/select.css',
                twigPath: $base . '/twig/primitives/runtime/select.html.twig',
                states: ['default', 'invalid'],
                sizes: ['sm', 'md', 'lg'],
            ),
            new Primitive(
                id: 'textarea',
                cssPath: $base . '/primitives/textarea.css',
                twigPath: $base . '/twig/primitives/runtime/textarea.html.twig',
                states: ['default', 'invalid'],
                sizes: ['sm', 'md', 'lg'],
            ),
            // checkbox · radio · switch share one stylesheet.
            new Primitive(
                id: 'choice',
                cssPath: $base . '/primitives/choice.css',
                twigPath: $base . '/twig/primitives/runtime/checkbox.html.twig',
                states: ['default', 'invalid'],
            ),
            // progress.css also styles platform.meter.
            new Primitive(
                id: 'progress',
                cssPath: $base . '/primitives/progress.css',
                twigPath: $base . '/twig/primitives/runtime/progress.html.twig',
                tones: ['neutral', 'brand', 'info', 'success', 'warning', 'danger'],
                sizes: ['sm', 'md', 'lg'],
            ),
            new Primitive(
                id: 'skeleton',
                cssPath: $base . '/primitives/skeleton.css',
                twigPath: $base . '/twig/primitives/runtime/skeleton.html.twig',
            ),
            new Primitive(
                id: 'divider',
                cssPath: $base . '/primitives/divider.css',
                twigPath: $base . '/twig/primitives/runtime/divider.html.twig',
            ),
            new Primitive(
                id: 'description-list',
                cssPath: $base . '/primitives/description-list.css',
                twigPath: $base . '/twig/primitives/runtime/description-list.html.twig',
            ),
            new Primitive(
                id: 'tag',
                cssPath: $base . '/primitives/tag.css',
                twigPath: $base . '/twig/primitives/runtime/tag.html.twig',
                tones: ['neutral', 'brand', 'info', 'success', 'warning', 'danger'],
            ),
            new Primitive(
                id: 'segmented',
                cssPath: $base . '/primitives/segmented.css',
                twigPath: $base . '/twig/primitives/runtime/segmented.html.twig',
                sizes: ['sm', 'md'],
            ),
            new Primitive(
                id: 'kbd',
                cssPath: $base . '/primitives/kbd.css',
                twigPath: $base . '/twig/primitives/runtime/kbd.html.twig',
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
                variants: ['solid', 'soft', 'outline'],
                tones: ['neutral', 'brand', 'info', 'success', 'warning', 'danger'],
                sizes: ['sm', 'md'],
            ),
            new Primitive(
                id: 'alert',
                cssPath: $base . '/primitives/alert.css',
                twigPath: $base . '/twig/primitives/alert.twig',
                variants: ['soft', 'outline', 'solid'],
                tones: ['neutral', 'info', 'success', 'warning', 'danger'],
            ),
            new Primitive(
                id: 'avatar',
                cssPath: $base . '/primitives/avatar.css',
                twigPath: $base . '/twig/primitives/avatar.twig',
                sizes: ['sm', 'md', 'lg', 'xl'],
            ),
            new Primitive(
                id: 'spinner',
                cssPath: $base . '/primitives/spinner.css',
                twigPath: $base . '/twig/primitives/spinner.twig',
                tones: ['brand', 'neutral'],
                sizes: ['sm', 'md', 'lg'],
            ),
        ];
    }
}

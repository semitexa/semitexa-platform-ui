<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive;

use Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Throwable;
use Twig\Environment as TwigEnvironment;

/**
 * Foundation-level renderer for UI primitives.
 *
 * Resolves a primitive by canonical $name OR $ui alias, renders its declared
 * template through ModuleTemplateRegistry, collects declared style/script
 * asset keys through AssetCollectorStore, and emits a stable root marker
 * (`ui="<alias>" data-ui-primitive="<name>"`) that the future SemitexaUi
 * frontend runtime will scan for.
 *
 * The renderer falls back to a minimal `<span ui=... data-ui-primitive=...>`
 * envelope when no template is declared — useful for tests and the
 * dependency-free, no-Twig path.
 */
final class PrimitiveRenderer
{
    public const ROOT_ATTR_PRIMITIVE = 'data-ui-primitive';
    public const ROOT_ATTR_UI = 'ui';

    public function __construct(
        private readonly ?TwigEnvironment $twig = null,
    ) {}

    /**
     * @param array<string, mixed> $props
     * @return array{
     *     primitive: PrimitiveMetadata,
     *     props: array<string, mixed>,
     *     rootAttributes: array<string, string>,
     * }
     */
    public function resolve(string $nameOrAlias, array $props = []): array
    {
        $metadata = UiPrimitiveRegistry::get($nameOrAlias);
        if ($metadata === null) {
            throw new PrimitiveRegistryException(sprintf(
                'Unknown UI primitive "%s" — not registered by name or ui alias.',
                $nameOrAlias,
            ));
        }

        return [
            'primitive' => $metadata,
            'props' => $props,
            'rootAttributes' => self::rootAttributesFor($metadata),
        ];
    }

    /**
     * @param array<string, mixed> $props
     */
    public function render(string $nameOrAlias, array $props = []): string
    {
        $resolved = $this->resolve($nameOrAlias, $props);
        $metadata = $resolved['primitive'];

        $this->collectAssets($metadata);

        if ($metadata->template !== null) {
            return $this->renderTemplate($metadata, $props);
        }

        return $this->renderFallback($metadata, $props);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderTemplate(PrimitiveMetadata $metadata, array $props): string
    {
        $template = (string) $metadata->template;
        $context = array_merge($props, [
            '_primitive' => [
                'name' => $metadata->name,
                'ui' => $metadata->ui,
            ],
        ]);

        $twig = $this->twig ?? ModuleTemplateRegistry::getTwig();
        try {
            return $twig->render($template, $context);
        } catch (Throwable $e) {
            throw new PrimitiveRegistryException(sprintf(
                'Primitive "%s" template "%s" failed to render: %s',
                $metadata->name,
                $template,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderFallback(PrimitiveMetadata $metadata, array $props): string
    {
        $tag = self::elementForUi($metadata->ui);
        $attrs = self::renderAttributes(self::rootAttributesFor($metadata));

        $content = '';
        if (isset($props['text']) && is_scalar($props['text'])) {
            $content = htmlspecialchars((string) $props['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } elseif (isset($props['label']) && is_scalar($props['label'])) {
            $content = htmlspecialchars((string) $props['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return sprintf('<%s %s>%s</%s>', $tag, $attrs, $content, $tag);
    }

    private function collectAssets(PrimitiveMetadata $metadata): void
    {
        if ($metadata->style === null && $metadata->script === null) {
            return;
        }

        $collector = self::activeCollector();
        if ($collector === null) {
            return;
        }

        if ($metadata->style !== null) {
            $collector->require($metadata->style);
        }
        if ($metadata->script !== null) {
            $collector->require($metadata->script);
        }
    }

    private static function activeCollector(): ?AssetCollector
    {
        if (!class_exists(AssetCollectorStore::class, true)) {
            return null;
        }
        try {
            return AssetCollectorStore::get();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public static function rootAttributesFor(PrimitiveMetadata $metadata): array
    {
        return [
            self::ROOT_ATTR_UI => $metadata->ui,
            self::ROOT_ATTR_PRIMITIVE => $metadata->name,
        ];
    }

    /**
     * @param array<string, string> $attrs
     */
    private static function renderAttributes(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            $parts[] = sprintf(
                '%s="%s"',
                $key,
                htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return implode(' ', $parts);
    }

    private static function elementForUi(string $ui): string
    {
        return match ($ui) {
            'input' => 'input',
            'label' => 'label',
            default => 'span',
        };
    }
}

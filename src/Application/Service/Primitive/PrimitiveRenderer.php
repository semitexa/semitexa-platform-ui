<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive;

use Semitexa\Core\Environment;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry as PrimitiveVocabulary;
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

    /** Props whose values must come from the primitive's declared vocabulary. */
    private const VOCABULARY_PROPS = ['variant' => 'variants', 'tone' => 'tones', 'size' => 'sizes'];

    private static ?PrimitiveVocabulary $vocabulary = null;
    private static ?bool $devStrict = null;

    /**
     * @param bool|null $strictVocabulary true: an unknown variant/tone/size throws;
     *                                    false: it is dropped and the default renders;
     *                                    null: strict when APP_ENV=dev.
     */
    public function __construct(
        private readonly ?TwigEnvironment $twig = null,
        private readonly ?bool $strictVocabulary = null,
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
        $props = $this->checkVocabulary($metadata, $props);

        $this->collectAssets($metadata);

        if ($metadata->template !== null) {
            return $this->renderTemplate($metadata, $props);
        }

        return $this->renderFallback($metadata, $props);
    }

    /**
     * @param array<string, mixed> $props
     */
    /**
     * `variant="primary"` on a button used to render a transparent button —
     * quieter than the default — with nothing to say why. An unknown value now
     * fails loudly in development, naming what is allowed; elsewhere it is
     * dropped so the primitive falls back to its default look instead of an
     * unstyled one. The vocabulary is the one platform-ui:css:explain prints.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function checkVocabulary(PrimitiveMetadata $metadata, array $props): array
    {
        self::$vocabulary ??= new PrimitiveVocabulary();
        $declared = self::$vocabulary->get($metadata->ui);
        if ($declared === null) {
            return $props;
        }

        foreach (self::VOCABULARY_PROPS as $prop => $list) {
            $value = $props[$prop] ?? null;
            if ($value === null || $value === '' || !is_string($value)) {
                continue;
            }
            /** @var list<string> $allowed */
            $allowed = $declared->{$list};
            if ($allowed === [] || in_array($value, $allowed, true)) {
                continue;
            }
            if ($this->isStrict()) {
                throw new PrimitiveRegistryException(sprintf(
                    'Primitive "%s" has no %s "%s". Allowed: %s.',
                    $metadata->name,
                    $prop,
                    $value,
                    implode(', ', $allowed),
                ));
            }
            unset($props[$prop]);
        }

        return $props;
    }

    private function isStrict(): bool
    {
        if ($this->strictVocabulary !== null) {
            return $this->strictVocabulary;
        }
        return self::$devStrict ??= Environment::create()->isDev();
    }

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
        // semitexa/ssr is a hard requirement of this package; no existence probe.
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

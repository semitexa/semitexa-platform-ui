<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Icon\IconRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\AlertPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\AvatarPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\BadgePrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\SpinnerPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;
use Semitexa\PlatformUi\Application\Service\Link\UiSafeHref;

/**
 * Drives PrimitiveRenderer through a real Twig environment loaded against
 * this package's resources/twig directory under the `@platform-ui` namespace.
 * The renderer accepts an explicit Twig instance so this test does not need
 * the full SSR module-discovery boot.
 */
final class PrimitiveTwigRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->twig = new TwigEnvironment($loader, [
            'cache' => false,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        // Mirror the production wiring: the same extension that registers
        // primitive() also registers icon(), which the alert runtime template uses.
        $this->twig->addFunction(new TwigFunction(
            'icon',
            static fn (string $name, array $opts = []): Markup => new Markup(IconRegistry::render($name, $opts), 'UTF-8'),
            ['is_safe' => ['html']],
        ));
        // …and ui_href(), which the button template passes its href through.
        $this->twig->addFunction(new TwigFunction('ui_href', static fn (mixed $href): string => UiSafeHref::filter($href)));
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();
    }

    private function renderer(): PrimitiveRenderer
    {
        return new PrimitiveRenderer($this->twig);
    }

    #[Test]
    public function button_template_renders_with_props_and_root_markers(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(ButtonPrimitive::class));

        $html = $this->renderer()->render('button', [
            'text' => 'Save',
            'tone' => 'brand',
            'variant' => 'solid',
            'size' => 'md',
        ]);

        self::assertStringContainsString('<button', $html);
        self::assertStringContainsString('ui="button"', $html);
        self::assertStringContainsString('data-ui-primitive="platform.button"', $html);
        self::assertStringContainsString('ui-tone="brand"', $html);
        self::assertStringContainsString('ui-variant="solid"', $html);
        self::assertStringContainsString('ui-size="md"', $html);
        self::assertStringContainsString('Save', $html);
        self::assertStringContainsString('type="button"', $html);
    }

    #[Test]
    public function button_template_renders_anchor_when_href_provided(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(ButtonPrimitive::class));

        $html = $this->renderer()->render('button', [
            'text' => 'Docs',
            'href' => '/docs',
        ]);

        self::assertStringContainsString('<a ', $html);
        self::assertStringContainsString('href="/docs"', $html);
        self::assertStringContainsString('data-ui-primitive="platform.button"', $html);
        self::assertStringNotContainsString('type="button"', $html);
    }

    #[Test]
    public function button_template_drops_a_script_href_and_renders_a_button(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(ButtonPrimitive::class));

        $html = $this->renderer()->render('button', ['text' => 'Go', 'href' => 'javascript:alert(1)']);

        self::assertStringStartsWith('<button ', ltrim($html));
        self::assertStringNotContainsString('href', $html);
        self::assertStringNotContainsString('javascript', $html);
        self::assertStringContainsString('type="button"', $html);
    }

    #[Test]
    public function input_template_renders_with_name_and_placeholder(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(InputPrimitive::class));

        $html = $this->renderer()->render('input', [
            'name' => 'email',
            'placeholder' => 'Email address',
            'required' => true,
        ]);

        self::assertStringContainsString('<input', $html);
        self::assertStringContainsString('ui="input"', $html);
        self::assertStringContainsString('data-ui-primitive="platform.input"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('id="email"', $html);
        self::assertStringContainsString('placeholder="Email address"', $html);
        self::assertStringContainsString('required', $html);
    }

    #[Test]
    public function input_template_renders_bare_when_no_label_or_help_or_error(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(InputPrimitive::class));

        $html = trim($this->renderer()->render('input', ['name' => 'plain']));

        self::assertStringStartsWith('<input', $html);
        self::assertStringNotContainsString('<div', $html);
        self::assertStringNotContainsString('<span', $html);
    }

    #[Test]
    public function input_template_renders_help_text_when_help_is_provided(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(InputPrimitive::class));

        $html = $this->renderer()->render('input', [
            'name' => 'email',
            'help' => 'We never share email addresses.',
        ]);

        self::assertStringContainsString('<div', $html);
        self::assertStringContainsString('We never share email addresses.', $html);
        self::assertStringContainsString('id="email-help"', $html);
        self::assertStringContainsString('aria-describedby="email-help"', $html);
        self::assertStringNotContainsString('ui-state="invalid"', $html);
        self::assertStringNotContainsString('aria-invalid', $html);
    }

    #[Test]
    public function input_template_sets_invalid_state_and_aria_when_error_is_provided(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(InputPrimitive::class));

        $html = $this->renderer()->render('input', [
            'name' => 'username',
            'value' => 'me!',
            'error' => 'Letters and digits only.',
        ]);

        self::assertStringContainsString('ui-state="invalid"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="username-error"', $html);
        self::assertStringContainsString('id="username-error"', $html);
        self::assertStringContainsString('Letters and digits only.', $html);
    }

    #[Test]
    public function input_template_error_takes_precedence_over_help(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(InputPrimitive::class));

        $html = $this->renderer()->render('input', [
            'name' => 'mixed',
            'help' => 'Should not appear.',
            'error' => 'This wins.',
        ]);

        self::assertStringContainsString('This wins.', $html);
        self::assertStringNotContainsString('Should not appear.', $html);
        self::assertStringNotContainsString('aria-describedby="mixed-help"', $html);
    }

    #[Test]
    public function badge_template_renders_with_tone(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(BadgePrimitive::class));

        $html = $this->renderer()->render('badge', [
            'text' => 'Active',
            'tone' => 'success',
            'variant' => 'soft',
        ]);

        self::assertStringContainsString('<span', $html);
        self::assertStringContainsString('ui="badge"', $html);
        self::assertStringContainsString('data-ui-primitive="platform.badge"', $html);
        self::assertStringContainsString('ui-tone="success"', $html);
        self::assertStringContainsString('ui-variant="soft"', $html);
        self::assertStringContainsString('Active', $html);
    }

    #[Test]
    public function alert_template_renders_tone_role_and_auto_icon(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AlertPrimitive::class));

        $html = $this->renderer()->render('alert', [
            'tone' => 'danger',
            'title' => 'Payment failed',
            'text' => 'Your card was declined.',
        ]);

        self::assertStringContainsString('ui="alert"', $html);
        self::assertStringContainsString('data-ui-primitive="platform.alert"', $html);
        self::assertStringContainsString('ui-tone="danger"', $html);
        self::assertStringContainsString('role="alert"', $html); // danger => assertive
        self::assertStringContainsString('<div data-alert-title>Payment failed</div>', $html);
        self::assertStringContainsString('Your card was declined.', $html);
        // auto-icon for danger is alert-circle, rendered inline as an sx-icon
        self::assertStringContainsString('class="sx-icon"', $html);
    }

    #[Test]
    public function alert_template_info_tone_is_polite_status(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AlertPrimitive::class));

        $html = $this->renderer()->render('alert', ['text' => 'Heads up.']);

        self::assertStringContainsString('role="status"', $html); // info default => polite
        self::assertStringNotContainsString('ui-tone=', $html); // no tone attr when default
    }

    #[Test]
    public function avatar_template_renders_initials_or_image(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AvatarPrimitive::class));

        $initials = $this->renderer()->render('avatar', [
            'initials' => 'TG',
            'size' => 'lg',
            'label' => 'Taras G',
        ]);
        self::assertStringContainsString('ui="avatar"', $initials);
        self::assertStringContainsString('data-ui-primitive="platform.avatar"', $initials);
        self::assertStringContainsString('ui-size="lg"', $initials);
        self::assertStringContainsString('role="img"', $initials);
        self::assertStringContainsString('aria-label="Taras G"', $initials);
        self::assertStringContainsString('TG', $initials);
        self::assertStringNotContainsString('<img', $initials);

        $image = $this->renderer()->render('avatar', ['src' => '/u/1.png', 'alt' => 'Photo']);
        self::assertStringContainsString('<img src="/u/1.png" alt="Photo">', $image);
        self::assertStringContainsString('aria-hidden="true"', $image);
    }

    #[Test]
    public function spinner_template_renders_status_role_and_size(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(SpinnerPrimitive::class));

        $html = $this->renderer()->render('spinner', ['size' => 'lg', 'tone' => 'neutral']);

        self::assertStringContainsString('ui="spinner"', $html);
        self::assertStringContainsString('data-ui-primitive="platform.spinner"', $html);
        self::assertStringContainsString('ui-size="lg"', $html);
        self::assertStringContainsString('ui-tone="neutral"', $html);
        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('aria-label="Loading"', $html);
    }

    #[Test]
    public function rendering_collects_declared_style_asset_through_collector(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(BadgePrimitive::class));

        $this->renderer()->render('badge', ['text' => 'X']);

        self::assertTrue(AssetCollectorStore::get()->has('platform-ui:css:full'));
    }

    #[Test]
    public function alias_and_canonical_name_resolve_the_same_primitive(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(ButtonPrimitive::class));

        $renderer = $this->renderer();
        $byAlias = $renderer->render('button', ['text' => 'A']);
        $byCanonical = $renderer->render('platform.button', ['text' => 'A']);

        self::assertSame($byAlias, $byCanonical);
    }

    #[Test]
    public function unknown_primitive_template_path_raises_clear_error(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromAttribute(
            BrokenTemplateFixture::class,
            new \Semitexa\PlatformUi\Attribute\AsUiPrimitive(
                name: 'platform.broken',
                ui: 'broken',
                template: '@platform-ui/primitives/runtime/__does_not_exist__.html.twig',
            ),
        ));

        $this->expectException(\Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException::class);
        $this->expectExceptionMessageMatches('/template .* failed to render/');
        $this->renderer()->render('broken');
    }


    #[Test]
    public function an_unknown_variant_fails_loudly_and_names_the_allowed_ones(): void
    {
        UiPrimitiveRegistry::register((new UiPrimitiveMetadataFactory())->fromClass(ButtonPrimitive::class));

        try {
            (new PrimitiveRenderer($this->twig, strictVocabulary: true))->render('button', ['text' => 'Go', 'variant' => 'primary']);
            self::fail('variant="primary" must not render silently.');
        } catch (\Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException $e) {
            self::assertStringContainsString('no variant "primary"', $e->getMessage());
            self::assertStringContainsString('solid, soft, outline, ghost, link', $e->getMessage());
        }
    }

    #[Test]
    public function outside_strict_mode_an_unknown_value_falls_back_to_the_default(): void
    {
        UiPrimitiveRegistry::register((new UiPrimitiveMetadataFactory())->fromClass(BadgePrimitive::class));

        $html = (new PrimitiveRenderer($this->twig, strictVocabulary: false))
            ->render('badge', ['text' => 'New', 'tone' => 'purple', 'variant' => 'solid']);

        self::assertStringNotContainsString('ui-tone=', $html);
        self::assertStringContainsString('ui-variant="solid"', $html);
    }

    #[Test]
    public function a_tone_is_rendered_without_requiring_a_variant(): void
    {
        UiPrimitiveRegistry::register((new UiPrimitiveMetadataFactory())->fromClass(ButtonPrimitive::class));

        $html = (new PrimitiveRenderer($this->twig, strictVocabulary: true))->render('button', ['text' => 'Delete', 'tone' => 'danger']);

        self::assertStringContainsString('ui-tone="danger"', $html);
        self::assertStringNotContainsString('ui-variant', $html);
    }

    #[Test]
    public function button_states_render_their_accessibility_attributes(): void
    {
        UiPrimitiveRegistry::register((new UiPrimitiveMetadataFactory())->fromClass(ButtonPrimitive::class));
        $r = new PrimitiveRenderer($this->twig, strictVocabulary: true);

        $loading = $r->render('button', ['text' => 'Saving', 'loading' => true]);
        self::assertStringContainsString('ui-state="loading"', $loading);
        self::assertStringContainsString('aria-busy="true"', $loading);

        // An icon-only button keeps an accessible name and drops the visible label.
        $square = $r->render('button', ['text' => 'Settings', 'shape' => 'square', 'icon' => 'settings']);
        self::assertStringContainsString('aria-label="Settings"', $square);
        self::assertStringContainsString('<svg', $square);
        self::assertStringNotContainsString('>Settings<', $square);

        // A disabled link cannot use the disabled attribute; it is announced and taken out of the tab order.
        $link = $r->render('button', ['text' => 'Open', 'href' => '/x', 'disabled' => true]);
        self::assertStringContainsString('aria-disabled="true"', $link);
        self::assertStringContainsString('tabindex="-1"', $link);
        self::assertStringNotContainsString(' disabled', $link);
    }

    #[Test]
    public function badge_dot_and_alert_variant_render(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(BadgePrimitive::class));
        UiPrimitiveRegistry::register($factory->fromClass(AlertPrimitive::class));
        $r = new PrimitiveRenderer($this->twig, strictVocabulary: true);

        self::assertStringContainsString(' ui-dot', $r->render('badge', ['text' => 'Live', 'tone' => 'success', 'dot' => true]));
        self::assertStringContainsString('ui-variant="solid"', $r->render('alert', ['text' => 'Saved', 'tone' => 'success', 'variant' => 'solid']));
    }
}

final class BrokenTemplateFixture {}

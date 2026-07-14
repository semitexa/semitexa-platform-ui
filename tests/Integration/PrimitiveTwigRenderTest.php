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
}

final class BrokenTemplateFixture {}

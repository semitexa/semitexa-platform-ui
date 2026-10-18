<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Refresh;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Refresh\UiRefreshFinisher;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * A component with events carries a token that can only draw it again: the
 * same component, instance and props, for the same subject and session. A
 * wrapper, a stateful component, or one drawn outside a session gets none.
 * The dispatcher side is pinned in UiEffectVocabularyTest.
 */
final class UiRefreshFinisherTest extends TestCase
{
    private const INTERACTIVE = '<div class="ui-x" data-ui-component-instance-id="uci_1"><button data-ui-part="go">Go</button>'
        . '<script type="application/json" data-ui-event-manifest="uci_1">{}</script></div>';

    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    /** @var array{session: mixed, t: string}|null */
    private ?array $previousBinding = null;
    private mixed $previousCatalog = null;

    protected function setUp(): void
    {
        $secret = getenv('APP_SECRET');
        $this->previousSecret = $secret === false ? null : $secret;
        $env = getenv('APP_ENV');
        $this->previousEnv = $env === false ? null : $env;
        putenv('APP_SECRET=platform-ui-test-secret');
        putenv('APP_ENV=dev');
        $this->previousBinding = SignedContextBinding::snapshot();
        SignedContextBinding::bind('session-A', 'default');

        // A fresh registry, not reset(): reset() empties the shared one in place.
        $catalog = new \ReflectionProperty(UiComponentRegistry::class, 'catalog');
        $this->previousCatalog = $catalog->getValue();
        $catalog->setValue(null, null);
        $factory = new UiComponentMetadataFactory();
        UiComponentRegistry::register($factory->fromClass(RefreshButtonFixture::class));
        UiComponentRegistry::register($factory->fromClass(RefreshStatefulFixture::class));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(UiComponentRegistry::class, 'catalog'))->setValue(null, $this->previousCatalog);
        SignedContextBinding::restore($this->previousBinding);
        putenv($this->previousSecret === null ? 'APP_SECRET' : 'APP_SECRET=' . $this->previousSecret);
        putenv($this->previousEnv === null ? 'APP_ENV' : 'APP_ENV=' . $this->previousEnv);
    }

    #[Test]
    public function an_interactive_root_carries_a_token_naming_what_it_was_drawn_from(): void
    {
        $html = (new UiRefreshFinisher())->finish('refresh.button', RefreshButtonFixture::class, 'uci_1', ['instanceId' => 'uci_1', 'label' => 'Go'], self::INTERACTIVE);

        self::assertSame(1, preg_match('/^<div class="ui-x"[^>]* data-ui-refresh="([^"]+)"/', $html, $m), $html);
        $token = html_entity_decode($m[1]);
        $claims = SignedContext::verify($token);
        self::assertIsArray($claims);
        self::assertEquals(['rf' => 'refresh.button', 'i' => 'uci_1', 'pr' => ['label' => 'Go'], 'who' => ''], array_intersect_key($claims, ['rf' => 1, 'i' => 1, 'pr' => 1, 'who' => 1, 'sub' => 1]));
        self::assertArrayNotHasKey('p', $claims, 'it names no part, so it is never an event context');
        self::assertArrayNotHasKey('e', $claims);
        self::assertGreaterThan(time() + SignedContext::DEFAULT_TTL_SECONDS, $claims['exp']);

        SignedContextBinding::bind('session-B', 'default');
        self::assertNull(SignedContext::verify($token), 'another session cannot use it');
    }

    #[Test]
    public function what_cannot_or_must_not_be_redrawn_gets_no_token(): void
    {
        $finisher = new UiRefreshFinisher();
        $plain = '<div class="ui-x">Static</div>';
        $wrapper = '<section data-ui-component-instance-id="uci_9">' . self::INTERACTIVE . '</section>';

        self::assertSame($plain, $finisher->finish('refresh.button', RefreshButtonFixture::class, 'uci_1', [], $plain));
        self::assertSame($wrapper, $finisher->finish('refresh.button', RefreshButtonFixture::class, 'uci_9', [], $wrapper), 'a child\'s manifest is not its own');
        self::assertSame(self::INTERACTIVE, $finisher->finish('other.unknown', '', 'uci_1', [], self::INTERACTIVE), 'not a platform-ui component');
        self::assertSame(self::INTERACTIVE, $finisher->finish('refresh.stateful', RefreshStatefulFixture::class, 'uci_1', [], self::INTERACTIVE), 'server-kept state stays on the server');
        self::assertSame(self::INTERACTIVE, $finisher->finish('refresh.button', RefreshButtonFixture::class, 'uci_1', ['rows' => str_repeat('r', 2000)], self::INTERACTIVE));
        self::assertSame(self::INTERACTIVE, $finisher->finish('refresh.button', RefreshButtonFixture::class, 'uci_1', ['when' => new \DateTimeImmutable()], self::INTERACTIVE), 'an object cannot be signed back');

        SignedContextBinding::clear();
        self::assertSame(self::INTERACTIVE, $finisher->finish('refresh.button', RefreshButtonFixture::class, 'uci_1', [], self::INTERACTIVE), 'drawn outside a session: bound to no one');
    }
}

#[AsComponent(name: 'refresh.button', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'go', uses: ButtonPrimitive::class)]
final class RefreshButtonFixture
{
    #[UiOn(part: 'go', event: 'click')]
    public function onGo(UiInteractionEvent $event): void
    {
    }
}

#[AsComponent(name: 'refresh.stateful', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'go', uses: ButtonPrimitive::class)]
final class RefreshStatefulFixture
{
    #[\Semitexa\PlatformUi\Attribute\UiState]
    public int $count = 0;

    #[UiOn(part: 'go', event: 'click')]
    public function onGo(UiInteractionEvent $event): void
    {
    }
}

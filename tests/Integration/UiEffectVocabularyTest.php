<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiInteractionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatchAdapter;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventNotificationInstruction;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventRedirectInstruction;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * tk-cm-effects: ONE effect vocabulary. A handler asks for a `rerender`; the
 * dispatcher re-renders the signed instance with its signed props plus the
 * handler's overrides (and the page's KISS session) and answers `morph`.
 */
final class UiEffectVocabularyTest extends TestCase
{
    private const INSTANCE = 'uci_effects_test_0001';

    /** @var list<array{string, array<string, mixed>, ?string}> */
    private array $renders = [];

    protected function setUp(): void
    {
        putenv('APP_SECRET=platform-ui-effects-test');
        putenv('APP_ENV=dev');
        UiComponentRegistry::reset();
        UiComponentRegistry::register((new UiComponentMetadataFactory())->fromClass(EffectsCounterFixture::class));
        $this->renders = [];
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
        UiSseSessionState::reset();
        putenv('APP_SECRET');
        putenv('APP_ENV');
    }

    #[Test]
    public function a_rerender_becomes_a_morph_rendered_with_signed_props_plus_overrides(): void
    {
        $result = $this->dispatcher()->dispatch($this->ctx(['count' => 4, 'label' => 'Clicks']), 'ui_evt_' . bin2hex(random_bytes(16)), []);

        // verify:accept-test-change the fixture now binds `count` to the URL, so the morph is followed by its url effect (tk-la-url-state)
        self::assertCount(2, $result->patches);
        self::assertSame(UiResponsePatch::OP_URL, $result->patches[1]->op);
        self::assertSame(['params' => ['n' => '5'], 'history' => 'replace'], $result->patches[1]->args);
        $morph = $result->patches[0];
        self::assertSame(UiResponsePatch::OP_MORPH, $morph->op);
        self::assertSame(self::INSTANCE, $morph->targetInstance);
        self::assertSame('<div>5</div>', $morph->value);
        // The label survived from the signed props, the count was overridden,
        // and the instance keeps its id.
        self::assertSame(['effects.counter', ['count' => 5, 'label' => 'Clicks', 'instanceId' => self::INSTANCE], null], $this->renders[0]);
    }

    #[Test]
    public function the_rerender_runs_inside_the_pages_kiss_session(): void
    {
        $this->dispatcher()->dispatch($this->ctx(['count' => 0], 'sse_' . str_repeat('a', 32)), 'ui_evt_' . bin2hex(random_bytes(16)), []);

        self::assertSame('sse_' . str_repeat('a', 32), $this->renders[0][2], 'the re-rendered manifest must keep the page\'s sub claim');
        self::assertNull(UiSseSessionState::current(), 'and the request scope is restored afterwards');
    }

    #[Test]
    public function without_a_renderer_a_rerender_is_refused_not_dropped(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/rerender/');
        $this->dispatcher(withRenderer: false)->dispatch($this->ctx(['count' => 0]), 'ui_evt_' . bin2hex(random_bytes(16)), []);
    }

    /** @return iterable<string, array{UiResponsePatch, ?string}> */
    public static function effects(): iterable
    {
        $i = self::INSTANCE;
        yield 'toast' => [UiResponsePatch::toast($i, 'Saved', 'success'), null];
        yield 'toast bad level' => [UiResponsePatch::toast($i, 'Saved', 'loud'), 'invalid_toast'];
        yield 'redirect path' => [UiResponsePatch::redirect($i, '/orders/7'), null];
        yield 'redirect off-site' => [UiResponsePatch::redirect($i, 'https://evil.test/'), 'invalid_redirect'];
        yield 'redirect protocol-relative' => [UiResponsePatch::redirect($i, '//evil.test/'), 'invalid_redirect'];
        yield 'dispatch' => [UiResponsePatch::dispatch($i, 'cart:updated', ['count' => 2]), null];
        yield 'dispatch bad name' => [UiResponsePatch::dispatch($i, 'Cart Updated'), 'invalid_dispatch'];
        yield 'dispatch nested detail' => [UiResponsePatch::dispatch($i, 'cart:updated', ['x' => ['y' => 1]]), 'invalid_dispatch'];
        yield 'append html' => [UiResponsePatch::append($i, '<li>x</li>', 'items'), null];
        yield 'morph a part' => [new UiResponsePatch(UiResponsePatch::OP_MORPH, $i, 'items', null, '<b></b>'), 'invalid_patch_target'];
        yield 'remove' => [UiResponsePatch::remove($i, 'items'), null];
        yield 'open a part' => [UiResponsePatch::open($i, 'details'), null];
        yield 'close the surrounding overlay' => [UiResponsePatch::close($i), null];
        yield 'reset a form part' => [UiResponsePatch::reset($i, 'form'), null];
        yield 'url' => [UiResponsePatch::url($i, ['q' => 'php', 'page' => null], true), null];
        yield 'url with a nested value' => [new UiResponsePatch(UiResponsePatch::OP_URL, $i, null, null, null, null, ['params' => ['q' => ['x']], 'history' => 'push']), 'invalid_url'];
        yield 'url with an odd key' => [UiResponsePatch::url($i, ['../x' => 'y']), 'invalid_url'];
        yield 'url with no history' => [new UiResponsePatch(UiResponsePatch::OP_URL, $i, null, null, null, null, ['params' => ['q' => 'x']]), 'invalid_url'];
        yield 'another instance' => [UiResponsePatch::toast('uci_someone_else_00', 'x'), 'patch_instance_mismatch'];
    }

    #[Test]
    #[DataProvider('effects')]
    public function the_validator_admits_the_vocabulary_and_nothing_else(UiResponsePatch $effect, ?string $expectedCode): void
    {
        try {
            (new UiPatchValidator())->validateAll([$effect], self::INSTANCE);
            self::assertNull($expectedCode, 'expected a refusal');
        } catch (UiInteractionUnprocessableException $e) {
            self::assertSame($expectedCode, $e->reason);
        }
    }

    #[Test]
    public function a_service_handlers_instructions_become_effects(): void
    {
        $result = (new UiInteractionDispatchAdapter())->toInteractionResult(new UiEventResponse(
            componentPropsPatch: ['count' => 1],
            redirect: new UiEventRedirectInstruction('/done'),
            notification: new UiEventNotificationInstruction('Saved', 'success'),
        ), self::INSTANCE);

        self::assertSame(
            [UiResponsePatch::OP_RERENDER, UiResponsePatch::OP_TOAST, UiResponsePatch::OP_REDIRECT],
            array_map(static fn (UiResponsePatch $p): string => $p->op, $result->patches),
        );
        self::assertSame(['props' => ['count' => 1]], $result->patches[0]->args);
    }

    #[Test]
    public function the_rendered_props_ride_the_signed_context_only_when_plain_and_small(): void
    {
        $metadata = UiComponentRegistry::get('effects.counter');
        self::assertNotNull($metadata);
        $claimsFor = static function (array $props) use ($metadata): array {
            $manifest = (new \Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder())
                ->build(metadata: $metadata, instanceId: self::INSTANCE, props: $props);
            $ctx = $manifest->toJsonShape()['events'][0]['ctx'];

            return SignedContext::verify($ctx) ?? [];
        };

        self::assertSame(['count' => 3, 'tags' => ['a', 'b']], $claimsFor(['count' => 3, 'tags' => ['a', 'b']])['pr'] ?? null);
        self::assertArrayNotHasKey('pr', $claimsFor(['when' => new \DateTimeImmutable()]), 'an object cannot be signed back');
        self::assertArrayNotHasKey('pr', $claimsFor(['blob' => str_repeat('x', 5000)]), 'too large to ride a context');
        self::assertArrayNotHasKey('pr', $claimsFor([]));
    }

    private function dispatcher(bool $withRenderer = true): UiInteractionDispatcher
    {
        return new UiInteractionDispatcher(
            payloadGuard: new UiPayloadFieldGuard(),
            patchValidator: new UiPatchValidator(),
            replayStore: new InMemoryUiReplayStore(),
            authorizer: new AllowAllUiInteractionAuthorizer(),
            productionLike: false,
            componentRenderer: $withRenderer
                ? function (string $name, array $props): string {
                    $this->renders[] = [$name, $props, UiSseSessionState::current()];
                    return '<div>' . $props['count'] . '</div>';
                }
                : null,
        );
    }

    /** @param array<string, mixed> $props */
    private function ctx(array $props, ?string $sub = null): string
    {
        $claims = ['c' => 'effects.counter', 'i' => self::INSTANCE, 'p' => 'increment', 'e' => 'click', 'pr' => $props];
        if ($sub !== null) {
            $claims['sub'] = $sub;
        }

        return SignedContext::sign($claims);
    }
}

#[AsComponent(name: 'effects.counter', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'increment', uses: ButtonPrimitive::class)]
#[\Semitexa\PlatformUi\Attribute\UiUrl(prop: 'count', as: 'n', type: 'int', except: 0)]
final class EffectsCounterFixture
{
    #[UiOn(part: 'increment', event: 'click')]
    public function onIncrement(UiInteractionEvent $event): UiInteractionResult
    {
        return UiInteractionResult::patch([
            UiResponsePatch::rerender($event->instanceId, ['count' => (int) ($event->props()['count'] ?? 0) + 1]),
        ]);
    }
}

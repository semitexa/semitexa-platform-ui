<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\PlatformUi\Application\Component\Builtin\CollaborativeFormComponent;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CollabManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;
use Semitexa\PlatformUi\Tests\Support\CollabScopeRecorder;
use Semitexa\PlatformUi\Tests\Support\HugDispatch;
use Semitexa\Ssr\Application\Handler\PayloadHandler\HugEventHandler;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;

/**
 * A collaborative form open for longer than its contexts live keeps saving.
 *
 * Every event of the form carries a context signed for
 * {@see SignedContext::DEFAULT_TTL_SECONDS}; past it HUG refuses the event and
 * the form stopped saving with nothing shown. The manifest now carries a
 * renewal context, and the runtime trades it for fresh event contexts when one
 * is refused as expired.
 *
 * Driven through the real door: HugEventHandler, Platform UI's dispatcher and
 * the collaboration handler, and the SHIPPED form-collab-runtime.js in node
 * (tests/Support/collab-runtime-harness.mjs), answered with what HUG answered.
 * Contexts are signed for one second and outlived by a sleep: the signer reads
 * the clock itself.
 */
final class FormCollabContextRenewalTest extends TestCase
{
    private const SCOPE = 'formdoc:property:42';
    private const INSTANCE = 'uci_0a1b2c3d4e5f';
    private const HARNESS = __DIR__ . '/../../Support/collab-runtime-harness.mjs';
    private const RUNTIME = __DIR__ . '/../../../src/Application/Static/js/form-collab-runtime.js';

    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    /** @var array{session: SessionInterface|string|null, t: string}|null */
    private ?array $previousBinding = null;
    private mixed $previousCatalog = null;

    private InMemoryFormCollabDraftStore $draft;
    private FormCollaborationEventHandler $handler;
    private HugDispatch $hug;
    private int $sent = 0;

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
        UiComponentRegistry::register((new UiComponentMetadataFactory())->fromClass(CollaborativeFormComponent::class));
        UiComponentRegistry::registerExternalFromClass(FormCollaborationEventHandler::class);

        $cache = new ArrayCacheManager();
        $this->draft = new InMemoryFormCollabDraftStore();
        $this->handler = (new FormCollaborationEventHandler())->withCollaborationDeps(
            $this->draft,
            (new CacheBackedFormPresenceStore())->withCacheManager($cache),
            (new CacheBackedFormLockStore())->withCacheManager($cache),
            new CollabScopeRecorder(),
        )->withIdentity(static fn (): ?object => null);
        $handler = $this->handler;
        $this->hug = (new HugDispatch())->withContainer(new class ($handler) implements ContainerInterface {
            public function __construct(private readonly FormCollaborationEventHandler $handler)
            {
            }

            public function get(string $id): mixed
            {
                return $this->handler;
            }

            public function has(string $id): bool
            {
                return $id === FormCollaborationEventHandler::class;
            }
        });
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(UiComponentRegistry::class, 'catalog'))->setValue(null, $this->previousCatalog);
        SignedContextBinding::restore($this->previousBinding);
        putenv($this->previousSecret === null ? 'APP_SECRET' : 'APP_SECRET=' . $this->previousSecret);
        putenv($this->previousEnv === null ? 'APP_ENV' : 'APP_ENV=' . $this->previousEnv);
    }

    #[Test]
    public function a_form_open_past_its_contexts_ttl_keeps_saving(): void
    {
        $manifest = $this->manifest('shared', ['title'], ttlSeconds: 1);
        sleep(2);

        // What HUG answers the page's expired contexts, and its renewal.
        $refused = $this->send($manifest['events']['field.edit'], ['field' => 'title', 'value' => 'abc']);
        self::assertSame(422, $refused['http']);
        $replies = ['field.edit' => [$refused]];
        $replies['manifest.renew'] = [$this->send($manifest['renew'], [])];

        $seen = $this->editorSees([
            'manifest' => $manifest,
            'inputs' => ['title' => ''],
            'replies' => $replies,
            'steps' => [['type' => 'title', 'value' => 'abc']],
        ]);

        // The context the runtime last sent the edit with is the one the
        // server gets it with.
        $last = '';
        foreach ($seen['contexts'] as $sent) {
            $last = $sent['event'] === 'field.edit' ? $sent['ctx'] : $last;
        }
        $answer = $this->send($last, ['field' => 'title', 'value' => 'abc']);

        self::assertSame(200, $answer['http'], 'the edit typed after the contexts expired is accepted: ' . self::show($answer['data']));
        self::assertSame(['title' => 'abc'], $this->draft->load(self::SCOPE)->values ?? null, 'and saved');
        self::assertSame(
            ['presence.ping', 'field.edit', 'manifest.renew', 'field.edit'],
            array_column($seen['posts'], 'event'),
            'refused once, renewed once, sent once more',
        );

        // The feed is subscribed again with its renewed context: a reconnect
        // re-subscribes with what the runtime holds, and the old one is dead.
        self::assertSame(2, $seen['feedOpens']);
        self::assertNotSame($manifest['feedCtx'], $seen['feedCtx']);
        self::assertIsArray(SignedContext::verify((string) $seen['feedCtx']), 'and it is a live one');

        // Renewing revived nothing: the old context is refused as before.
        self::assertSame(422, $this->send($manifest['events']['field.edit'], ['field' => 'title', 'value' => 'old'])['http']);
        self::assertSame(['title' => 'abc'], $this->draft->load(self::SCOPE)->values ?? null);
    }

    #[Test]
    public function an_expired_context_alone_is_still_refused(): void
    {
        $manifest = $this->manifest('shared', ['title'], ttlSeconds: 1);
        $shortRenewal = self::resigned($manifest['renew'], [], 1);
        sleep(2);

        $edit = $this->send($manifest['events']['field.edit'], ['field' => 'title', 'value' => 'abc']);
        self::assertSame(422, $edit['http']);
        self::assertSame([HugEventHandler::SIGNED_CONTEXT_EXPIRED], self::contextErrors($edit));
        self::assertNull($this->draft->load(self::SCOPE), 'nothing was written');

        // The renewal context expires like any other, and then renews nothing.
        $renewal = $this->send($shortRenewal, []);
        self::assertSame(422, $renewal['http']);
        self::assertSame([HugEventHandler::SIGNED_CONTEXT_EXPIRED], self::contextErrors($renewal));
        self::assertNoContextIn($renewal, 'no context in a refusal');

        // An expired event context is not a renewal: it routes to its own
        // event and is refused there, whatever the envelope calls it.
        $asRenewal = $this->hug->sendRaw(json_encode([
            'schemaVersion' => 1,
            'eventId' => 'evt_as_renewal',
            'correlationId' => 'corr_as_renewal',
            'semanticEvent' => 'manifest.renew',
            'signedContext' => $manifest['events']['field.edit'],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => (object) [],
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(422, $asRenewal->getStatusCode());
        self::assertNoContextIn(['http' => 422, 'data' => HugDispatch::json($asRenewal)]);
    }

    #[Test]
    public function a_live_event_context_never_renews(): void
    {
        $manifest = $this->manifest('shared', ['title']);

        // The signed (part, event) routes, not the envelope: a field.edit
        // context is handled as a field edit and answers no contexts.
        $answer = $this->send($manifest['events']['presence.ping'], []);

        self::assertSame(200, $answer['http']);
        self::assertNoContextIn($answer);
    }

    #[Test]
    public function a_renewed_context_requires_what_the_original_did(): void
    {
        foreach (['shared', 'optimistic', 'form-lock', 'field-lock'] as $mode) {
            $manifest = $this->manifest($mode, ['title', 'price']);

            $renewed = $this->renewedBy($manifest['renew']);

            // The same events and no other: a mode's renewal cannot mint an
            // event the mode never had (optimistic has no field.edit).
            self::assertSame(array_keys($manifest['events']), array_keys($renewed['events']), $mode);
            foreach ($manifest['events'] as $event => $original) {
                self::assertSame(self::lasting(SignedContext::verify($original)), self::lasting(SignedContext::verify($renewed['events'][$event])), $mode . ' ' . $event);
            }
            self::assertSame(self::lasting(SignedContext::verify($manifest['feedCtx'])), self::lasting(SignedContext::verify($renewed['feedCtx'])), $mode . ' feed');
            // (renewedBy: the answer holds those two and no renewal context,
            // so a day after the page was drawn it is over, however often
            // it renewed.)
        }
    }

    #[Test]
    public function a_renewed_context_lives_as_long_as_the_original_did(): void
    {
        $default = $this->renewedBy($this->manifest('shared', ['title'])['renew']);
        self::assertSame(SignedContext::DEFAULT_TTL_SECONDS, self::lifetime($default['events']['field.edit']));
        self::assertSame(SignedContext::DEFAULT_TTL_SECONDS, self::lifetime($default['feedCtx']));

        $short = $this->renewedBy($this->manifest('shared', ['title'], ttlSeconds: 30)['renew']);
        self::assertSame(30, self::lifetime($short['events']['field.edit']), 'a page that asked for shorter contexts gets shorter ones again');
    }

    #[Test]
    public function the_renewal_context_routes_to_the_renewal_and_carries_what_the_events_carry(): void
    {
        $manifest = $this->manifest('field-lock', ['title', 'price']);

        $renew = self::lasting(SignedContext::verify($manifest['renew']));
        $event = self::lasting(SignedContext::verify($manifest['events']['field.edit']));
        $expected = ['p' => 'manifest', 'e' => 'renew', 'who' => ''] + $event;
        ksort($expected);
        self::assertSame($expected, $renew, 'the event claims, another route, and who it was drawn for (a guest)');
        self::assertSame(CollabManifestBuilder::RENEW_TTL_SECONDS, self::lifetime($manifest['renew']));
        // It is not one of the events: the runtime never sends it as one.
        self::assertSame(['field.edit', 'presence.ping', 'lock.acquire', 'lock.release', 'lock.heartbeat'], array_keys($manifest['events']));
    }

    #[Test]
    public function only_a_renewal_contexts_claims_are_signed_again(): void
    {
        $builder = new CollabManifestBuilder();
        $manifest = $this->manifest('shared', ['title']);
        $renew = SignedContext::verify($manifest['renew']);
        self::assertIsArray($renew);
        self::assertIsArray($builder->renewed($renew));

        // An event's own claims, or a renewal's with anything out of shape.
        $event = SignedContext::verify($manifest['events']['field.edit']);
        self::assertIsArray($event);
        self::assertNull($builder->renewed($event));
        foreach ([
            ['c' => ''],
            ['i' => ''],
            ['cfg' => self::SCOPE],
            ['cfg' => ['scope' => 'property 42', 'mode' => 'shared', 'fields' => []]],
            ['cfg' => ['scope' => self::SCOPE, 'mode' => 'everyone', 'fields' => []]],
            ['cfg' => ['scope' => self::SCOPE, 'mode' => 'shared']],
            ['cfg' => ['scope' => self::SCOPE, 'mode' => 'shared', 'fields' => [['title']]]],
            ['ttl' => 0],
            ['ttl' => '300'],
        ] as $altered) {
            self::assertNull($builder->renewed(array_replace($renew, $altered)), self::show($altered));
        }
    }

    #[Test]
    public function a_renewal_for_another_session_or_tenant_is_refused(): void
    {
        $renew = $this->manifest('shared', ['title'])['renew'];

        SignedContextBinding::bind('session-B', 'default');
        $otherSession = $this->send($renew, []);
        SignedContextBinding::bind('session-A', 'acme');
        $otherTenant = $this->send($renew, []);
        SignedContextBinding::clear();
        $noSession = $this->send($renew, []);
        SignedContextBinding::bind('session-A', 'default');

        foreach (['another session' => $otherSession, 'another tenant' => $otherTenant, 'no session' => $noSession] as $case => $answer) {
            self::assertSame(422, $answer['http'], $case);
            self::assertSame(['Signed context verification failed.'], self::contextErrors($answer), $case);
            self::assertNoContextIn($answer, $case);
        }
        self::assertSame(200, $this->send($renew, [])['http'], 'and accepted where it was minted');
    }

    #[Test]
    public function a_renewal_for_another_visitor_is_refused(): void
    {
        // Drawn for a guest; the session has signed in since.
        $forGuest = $this->manifest('shared', ['title'])['renew'];
        $this->handler->withIdentity(static fn (): object => new class {
            public function getId(): string
            {
                return 'u7';
            }
        });
        $signedIn = $this->send($forGuest, []);

        // Drawn for u7; presented by a guest, then by u8.
        $forU7 = self::resigned($forGuest, ['who' => 'u7']);
        self::assertSame(200, $this->send($forU7, [])['http'], 'u7 renews what was drawn for u7');
        $this->handler->withIdentity(static fn (): ?object => null);
        $signedOut = $this->send($forU7, []);
        $this->handler->withIdentity(static fn (): object => new class {
            public function getId(): string
            {
                return 'u8';
            }
        });
        $otherAccount = $this->send($forU7, []);

        foreach (['signed in since' => $signedIn, 'signed out since' => $signedOut, 'another account' => $otherAccount] as $case => $answer) {
            self::assertSame(422, $answer['http'], $case);
            self::assertSame(FormCollaborationEventHandler::RENEW_SUBJECT_CHANGED, $answer['data']['reason'] ?? null, $case);
            self::assertNoContextIn($answer, $case);
        }
    }

    #[Test]
    public function a_renewal_for_another_route_or_with_altered_claims_is_refused(): void
    {
        $renew = $this->manifest('shared', ['title'])['renew'];

        // A component that is not a collaborative form, an event the form
        // does not have: the dispatcher finds no binding and no handler runs.
        foreach ([['c' => 'platform.button'], ['p' => 'field', 'e' => 'renew'], ['e' => 'edit']] as $altered) {
            $answer = $this->send(self::resigned($renew, $altered), []);
            self::assertSame(404, $answer['http'], self::show($altered));
            self::assertNoContextIn($answer);
        }

        // Claims a renewal context never carries are not signed onwards.
        foreach ([['cfg' => ['scope' => 'not a scope', 'mode' => 'shared', 'fields' => []]], ['cfg' => ['scope' => self::SCOPE, 'mode' => 'nope', 'fields' => []]], ['ttl' => 0], ['ttl' => '900']] as $altered) {
            $answer = $this->send(self::resigned($renew, $altered), []);
            self::assertSame(422, $answer['http'], self::show($altered));
            self::assertNoContextIn($answer);
        }

        // And a context changed after signing is no context at all.
        $tampered = $this->send(substr($renew, 0, -2) . (str_ends_with($renew, 'AA') ? 'BB' : 'AA'), []);
        self::assertSame(422, $tampered['http']);
        self::assertSame(['Signed context verification failed.'], self::contextErrors($tampered));
    }

    #[Test]
    public function a_renewal_opens_no_replay(): void
    {
        $manifest = $this->manifest('shared', ['title']);

        // A renewal request sent twice is a duplicate, like any event.
        self::assertSame(200, $this->send($manifest['renew'], [], 'evt_renew_once')['http']);
        $again = $this->send($manifest['renew'], [], 'evt_renew_once');
        self::assertSame(409, $again['http']);
        self::assertNoContextIn($again);

        // An edit request captured under the first context and sent again
        // is still a duplicate after a renewal: the renewal replaced nothing
        // on the server.
        self::assertSame(200, $this->send($manifest['events']['field.edit'], ['field' => 'title', 'value' => 'a'], 'evt_edit_once')['http']);
        $this->renewedBy($manifest['renew']);
        self::assertSame(409, $this->send($manifest['events']['field.edit'], ['field' => 'title', 'value' => 'replayed'], 'evt_edit_once')['http']);
        self::assertSame(['title' => 'a'], $this->draft->load(self::SCOPE)->values ?? null);
    }

    #[Test]
    public function the_newest_value_goes_again_when_two_edits_expired(): void
    {
        $seen = $this->editorSees([
            'manifest' => self::scripted('shared'),
            'inputs' => ['title' => ''],
            'hold' => ['manifest.renew'],
            'replies' => ['field.edit' => [self::expired(), self::expired()], 'manifest.renew' => [self::renewal()]],
            'steps' => [['type' => 'title', 'value' => 'a'], ['type' => 'title', 'value' => 'ab'], ['answer' => 'oldest']],
        ]);

        self::assertSame(
            [
                ['event' => 'field.edit', 'ctx' => 'ctx-field.edit'],
                ['event' => 'manifest.renew', 'ctx' => 'ctx-renew'],
                // Typed while the renewal is on its way: refused as well.
                ['event' => 'field.edit', 'ctx' => 'ctx-field.edit'],
                ['event' => 'field.edit', 'ctx' => 'new-field.edit'],
            ],
            array_values(array_filter($seen['contexts'], static fn (array $c): bool => $c['event'] !== 'presence.ping')),
            'one renewal for both, one edit after it',
        );
        self::assertSame(
            ['event' => 'field.edit', 'payload' => ['field' => 'title', 'value' => 'ab']],
            $seen['posts'][count($seen['posts']) - 1],
            'the value typed last, not the one refused first',
        );
    }

    #[Test]
    public function an_awaited_event_goes_again_and_later_ones_use_the_renewed_contexts(): void
    {
        // The lock is asked for with an expired context; its grant must not
        // be lost, and the heartbeat started by it must not keep the context
        // the timer was created with.
        $seen = $this->editorSees([
            'manifest' => self::scripted('form-lock'),
            'inputs' => ['title' => ''],
            'lockHost' => true,
            'replies' => ['lock.acquire' => [self::expired()], 'manifest.renew' => [self::renewal()]],
            'steps' => [['focus' => 'title'], ['type' => 'title', 'value' => 'a'], ['tick' => true]],
        ]);

        self::assertSame(
            [
                ['event' => 'presence.ping', 'ctx' => 'ctx-presence.ping'],
                ['event' => 'lock.acquire', 'ctx' => 'ctx-lock.acquire'],
                ['event' => 'manifest.renew', 'ctx' => 'ctx-renew'],
                ['event' => 'lock.acquire', 'ctx' => 'new-lock.acquire'],
                ['event' => 'field.edit', 'ctx' => 'new-field.edit'],
                ['event' => 'presence.ping', 'ctx' => 'new-presence.ping'],
                ['event' => 'lock.heartbeat', 'ctx' => 'new-lock.heartbeat'],
            ],
            $seen['contexts'],
        );
        self::assertSame('new-feed', $seen['feedCtx']);
    }

    #[Test]
    public function a_refused_renewal_is_not_asked_again_and_the_page_is_told(): void
    {
        $seen = $this->editorSees([
            'manifest' => self::scripted('shared'),
            'inputs' => ['title' => ''],
            'replies' => [
                'field.edit' => [self::expired(), self::expired()],
                'manifest.renew' => [['http' => 422, 'data' => ['status' => 'rejected', 'reason' => FormCollaborationEventHandler::RENEW_SUBJECT_CHANGED]]],
            ],
            'steps' => [['type' => 'title', 'value' => 'a'], ['type' => 'title', 'value' => 'ab']],
        ]);

        self::assertSame(
            ['presence.ping', 'field.edit', 'manifest.renew', 'field.edit'],
            array_column($seen['posts'], 'event'),
            'asked once; the second expired edit asks nothing',
        );
        self::assertContains(['type' => 'semitexa:collab:expired', 'detail' => []], $seen['events']);
        self::assertSame(1, $seen['feedOpens'], 'the feed is left as it was');
    }

    #[Test]
    public function a_renewal_that_got_no_answer_is_asked_again_and_a_renewed_context_refused_at_once_is_not(): void
    {
        // The server broke on the first renewal: nothing is lost for good,
        // the next expired answer asks again.
        $broke = $this->editorSees([
            'manifest' => self::scripted('shared'),
            'inputs' => ['title' => ''],
            'replies' => ['field.edit' => [self::expired(), self::expired()], 'manifest.renew' => [['http' => 503], self::renewal()]],
            'steps' => [['type' => 'title', 'value' => 'a'], ['type' => 'title', 'value' => 'ab']],
        ]);
        self::assertSame(
            ['presence.ping', 'field.edit', 'manifest.renew', 'field.edit', 'manifest.renew', 'field.edit'],
            array_column($broke['posts'], 'event'),
        );
        self::assertSame([], array_values(array_filter($broke['events'], static fn (array $e): bool => $e['type'] === 'semitexa:collab:expired')));

        // A server that calls the context it has just signed expired would
        // otherwise be asked for another, and another, as fast as it answers.
        $loop = $this->editorSees([
            'manifest' => self::scripted('shared'),
            'inputs' => ['title' => ''],
            'replies' => ['field.edit' => [self::expired(), self::expired(), self::expired()], 'manifest.renew' => [self::renewal(), self::renewal()]],
            'steps' => [['type' => 'title', 'value' => 'a']],
        ]);
        self::assertSame(['presence.ping', 'field.edit', 'manifest.renew', 'field.edit'], array_column($loop['posts'], 'event'));
    }

    /**
     * What HUG answers an authentic context past its TTL.
     *
     * @return array{http: int, data: array<string, mixed>}
     */
    private static function expired(): array
    {
        return ['http' => 422, 'data' => ['error' => 'validation', 'message' => 'Validation failed.', 'context' => ['errors' => ['signedContext' => [HugEventHandler::SIGNED_CONTEXT_EXPIRED]]]]];
    }

    /**
     * A renewal's answer, with contexts a test can tell from the first ones.
     *
     * @return array<string, mixed>
     */
    private static function renewal(): array
    {
        $events = [];
        foreach (['field.edit', 'form.save', 'presence.ping', 'lock.acquire', 'lock.release', 'lock.heartbeat'] as $event) {
            $events[$event] = 'new-' . $event;
        }

        return ['status' => 'accepted', 'reason' => 'accepted', 'kind' => 'ack', 'debug' => ['frontend' => ['collab' => ['renewed' => ['feedCtx' => 'new-feed', 'events' => $events]]]]];
    }

    /**
     * A manifest whose contexts are names, for the runs the harness answers
     * from a script.
     *
     * @return array<string, mixed>
     */
    private static function scripted(string $mode): array
    {
        $events = [];
        foreach (['field.edit', 'form.save', 'presence.ping', 'lock.acquire', 'lock.release', 'lock.heartbeat'] as $event) {
            $events[$event] = 'ctx-' . $event;
        }

        return [
            'v' => 1, 'i' => 'inst-1', 'scope' => self::SCOPE, 'mode' => $mode, 'self' => 'bob',
            'feed' => 'platform-ui.form-doc', 'feedCtx' => 'ctx-feed', 'events' => $events, 'renew' => 'ctx-renew',
            'fields' => ['title'], 'heartbeatMs' => 15000, 'saved' => '',
        ];
    }

    /**
     * What a context requires for as long as it lives: its claims without
     * the two that say when.
     *
     * @param array<string, mixed>|null $claims
     * @return array<string, mixed>
     */
    private static function lasting(?array $claims): array
    {
        self::assertIsArray($claims);
        unset($claims['iat'], $claims['exp']);
        ksort($claims);

        return $claims;
    }

    /**
     * The claims of `$context` signed again with `$changes`, as only the
     * server could: what a renewal context would be had it been minted so.
     *
     * @param array<string, mixed> $changes
     */
    private static function resigned(string $context, array $changes, ?int $ttlSeconds = null): string
    {
        $claims = SignedContext::verify($context);
        self::assertIsArray($claims);
        unset($claims['iat'], $claims['exp'], $claims['sb'], $claims['tn']);

        return SignedContext::sign(array_replace($claims, $changes), $ttlSeconds ?? CollabManifestBuilder::RENEW_TTL_SECONDS);
    }

    /**
     * The contexts a renewal answers, through HUG.
     *
     * @return array{feedCtx: string, events: array<string, string>}
     */
    private function renewedBy(string $renew): array
    {
        $answer = $this->send($renew, []);
        self::assertSame(200, $answer['http'], self::show($answer['data']));
        $renewed = self::dig($answer['data'], 'debug', 'frontend', 'collab', 'renewed');
        self::assertIsArray($renewed);
        self::assertSame(['feedCtx', 'events'], array_keys($renewed));

        /** @var array{feedCtx: string, events: array<string, string>} $renewed */
        return $renewed;
    }

    /**
     * An answer that says something, and hands out no signed context.
     *
     * @param array{http: int, data: array<string, mixed>} $answer
     */
    private static function assertNoContextIn(array $answer, string $case = ''): void
    {
        self::assertNotSame([], $answer['data'], $case);
        $said = self::show($answer['data']);
        self::assertNotSame('', $said, $case);
        self::assertStringNotContainsString('sc1.', $said, $case);
    }

    /**
     * The `signedContext` errors of a HUG refusal, null when it names none.
     *
     * @param array{http: int, data: array<string, mixed>} $answer
     */
    private static function contextErrors(array $answer): mixed
    {
        return self::dig($answer, 'data', 'context', 'errors', 'signedContext');
    }

    /** @param array<array-key, mixed> $data */
    private static function dig(array $data, string ...$path): mixed
    {
        $at = $data;
        foreach ($path as $key) {
            if (!is_array($at) || !array_key_exists($key, $at)) {
                return null;
            }
            $at = $at[$key];
        }

        return $at;
    }

    private static function show(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    /** How long a context lives, in seconds. */
    private static function lifetime(string $context): int
    {
        $claims = SignedContext::verify($context);
        self::assertIsArray($claims);
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        self::assertIsInt($issuedAt);
        self::assertIsInt($expiresAt);

        return $expiresAt - $issuedAt;
    }

    /**
     * @param list<string> $fields
     * @return array{v: int, i: string, scope: string, mode: string, self: string, feed: string, feedCtx: string, events: array<string, string>, renew: string, fields: list<string>, heartbeatMs: int, saved: string}
     */
    private function manifest(string $mode, array $fields, ?int $ttlSeconds = null): array
    {
        return (new CollabManifestBuilder())->build(
            componentName: 'platform.collab-form',
            instanceId: self::INSTANCE,
            formKey: 'property',
            recordId: '42',
            mode: $mode,
            fields: $fields,
            ttlSeconds: $ttlSeconds,
        );
    }

    /**
     * One event through HUG, as the harness replays it to the runtime:
     * {http, data}.
     *
     * @param array<string, mixed> $payload
     * @return array{http: int, data: array<string, mixed>}
     */
    private function send(string $ctx, array $payload, ?string $eventId = null): array
    {
        $response = $this->hug->send($ctx, $eventId ?? sprintf('evt_%08d', ++$this->sent), $payload);

        return ['http' => $response->getStatusCode(), 'data' => HugDispatch::json($response)];
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array{posts: list<array{event: string, payload: array<string, mixed>}>, contexts: list<array{event: string, ctx: string}>, feedCtx: ?string, feedOpens: int, events: list<array{type: string, detail: mixed}>}
     */
    private function editorSees(array $scenario): array
    {
        // No skip: node is part of the image the framework runs in.
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        self::assertNotSame('', $node, 'node is required to run form-collab-runtime.js; the framework image ships it.');

        $process = proc_open([$node, self::HARNESS], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'could not start node');
        fwrite($pipes[0], json_encode($scenario + ['runtime' => self::RUNTIME, 'steps' => []], \JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $seen = json_decode($stdout, true);
        self::assertTrue($exit === 0 && is_array($seen), "the runtime harness failed (exit {$exit}).\nstdout: {$stdout}\nstderr: {$stderr}");

        /** @var array{posts: list<array{event: string, payload: array<string, mixed>}>, contexts: list<array{event: string, ctx: string}>, feedCtx: ?string, feedOpens: int, events: list<array{type: string, detail: mixed}>} $seen */
        return $seen;
    }
}

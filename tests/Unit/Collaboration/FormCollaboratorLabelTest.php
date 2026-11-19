<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Contract\FormCollaboratorLabelResolverInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;
use Semitexa\PlatformUi\Tests\Support\CollabScopeRecorder;

/**
 * Who the other editors see. A signed-in user was shown by id: the presence
 * chip and "Locked by …" read `0193a7c2-…`. The label now comes from the app's
 * optional resolver, then the user object's own name, then the id.
 */
final class FormCollaboratorLabelTest extends TestCase
{
    private const SCOPE = 'formdoc:property:42';
    private const USER_ID = '0193a7c2-5b1e-7c44-9d2f-3a61b8e0c111';

    private CacheBackedFormPresenceStore $presence;

    private CacheBackedFormLockStore $locks;

    protected function setUp(): void
    {
        $cache = new ArrayCacheManager();
        $this->presence = (new CacheBackedFormPresenceStore())->withCacheManager($cache);
        $this->locks = (new CacheBackedFormLockStore())->withCacheManager($cache);
    }

    private function handler(?object $user, ?FormCollaboratorLabelResolverInterface $resolver = null): FormCollaborationEventHandler
    {
        return (new FormCollaborationEventHandler())
            ->withCollaborationDeps(new InMemoryFormCollabDraftStore(), $this->presence, $this->locks, new CollabScopeRecorder())
            ->withIdentity(static fn (): ?object => $user, $resolver);
    }

    private function event(string $event): UiEventContext
    {
        return new UiEventContext(
            eventId: 'evt',
            correlationId: 'cor',
            semanticEvent: $event,
            signedClaims: ['i' => 'tab-1', 'cfg' => ['scope' => self::SCOPE, 'mode' => 'form-lock', 'fields' => ['title']]],
            request: [],
        );
    }

    /** The label the roster shows after this user's heartbeat. */
    private function chipLabelOf(?object $user, ?FormCollaboratorLabelResolverInterface $resolver = null): string
    {
        $this->handler($user, $resolver)->handle((object) [], $this->event('presence.ping'));
        $roster = $this->presence->roster(self::SCOPE);
        self::assertCount(1, $roster);

        return $roster[0]->label;
    }

    private static function user(?string $displayName = null, ?string $name = null): object
    {
        return new class (self::USER_ID, $displayName, $name) {
            public function __construct(private string $id, private ?string $displayName, private ?string $name)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getDisplayName(): ?string
            {
                return $this->displayName;
            }

            public function getName(): ?string
            {
                return $this->name;
            }
        };
    }

    /** @param array<string, ?string> $labels */
    private static function resolver(array $labels): FormCollaboratorLabelResolverInterface
    {
        return new class ($labels) implements FormCollaboratorLabelResolverInterface {
            /** @param array<string, ?string> $labels */
            public function __construct(private array $labels)
            {
            }

            public function labelFor(string $userId): ?string
            {
                return $this->labels[$userId] ?? null;
            }
        };
    }

    #[Test]
    public function the_apps_resolver_wins_over_the_user_objects_own_names(): void
    {
        $label = $this->chipLabelOf(self::user('Display Olena', 'olena'), self::resolver([self::USER_ID => 'Olena K. (partner)']));

        self::assertSame('Olena K. (partner)', $label);
    }

    #[Test]
    public function a_resolver_with_no_answer_falls_through_to_the_display_name(): void
    {
        self::assertSame('Display Olena', $this->chipLabelOf(self::user('Display Olena', 'olena'), self::resolver([])));
        self::assertSame('Display Olena', $this->chipLabelOf(self::user('Display Olena', 'olena'), self::resolver([self::USER_ID => '  '])));
    }

    #[Test]
    public function without_a_resolver_the_display_name_then_the_name_then_the_id(): void
    {
        self::assertSame('Display Olena', $this->chipLabelOf(self::user('Display Olena', 'olena')));
        self::assertSame('olena', $this->chipLabelOf(self::user(null, 'olena')));
        self::assertSame('olena', $this->chipLabelOf(self::user('', 'olena')));
        self::assertSame(self::USER_ID, $this->chipLabelOf(self::user()));
    }

    #[Test]
    public function a_user_object_with_only_an_id_is_shown_by_id(): void
    {
        $user = new class {
            public function getId(): string
            {
                return 'u-7';
            }
        };

        self::assertSame('u-7', $this->chipLabelOf($user));
    }

    #[Test]
    public function the_identity_stays_the_id_whatever_the_label(): void
    {
        $this->handler(self::user('Display Olena'))->handle((object) [], $this->event('presence.ping'));

        self::assertSame(self::USER_ID, $this->presence->roster(self::SCOPE)[0]->participantId, 'locks and echo suppression key on the id');
    }

    #[Test]
    public function the_lock_holder_carries_the_same_label(): void
    {
        $this->handler(self::user('Display Olena'))->handle((object) [], $this->event('lock.acquire'));

        $lock = $this->locks->current(self::SCOPE, null);
        self::assertSame('Display Olena', $lock?->holderLabel);
        self::assertSame(self::USER_ID, $lock->holderId);
    }

    #[Test]
    public function a_resolver_that_throws_does_not_take_presence_down(): void
    {
        $throwing = new class implements FormCollaboratorLabelResolverInterface {
            public function labelFor(string $userId): ?string
            {
                throw new \RuntimeException('user service offline');
            }
        };

        self::assertSame('olena', $this->chipLabelOf(self::user(null, 'olena'), $throwing));
    }

    #[Test]
    public function the_resolver_is_taken_from_the_container_when_the_app_binds_one(): void
    {
        $bound = self::resolver([self::USER_ID => 'From the container']);
        $container = new class ($bound) implements ContainerInterface {
            public function __construct(private object $resolver)
            {
            }

            public function get(string $id): object
            {
                return $this->resolver;
            }

            public function has(string $id): bool
            {
                return $id === FormCollaboratorLabelResolverInterface::class;
            }
        };
        $handler = $this->handler(self::user('Display Olena'));
        (new \ReflectionProperty($handler, 'container'))->setValue($handler, $container);

        $handler->handle((object) [], $this->event('presence.ping'));

        self::assertSame('From the container', $this->presence->roster(self::SCOPE)[0]->label);
    }

    #[Test]
    public function a_container_with_no_binding_is_the_normal_case(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new \LogicException('not asked for when has() is false');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $handler = $this->handler(self::user('Display Olena'));
        (new \ReflectionProperty($handler, 'container'))->setValue($handler, $container);

        $handler->handle((object) [], $this->event('presence.ping'));

        self::assertSame('Display Olena', $this->presence->roster(self::SCOPE)[0]->label);
    }

    #[Test]
    public function a_label_is_one_short_line_of_text(): void
    {
        // A line break, a tab and a right-to-left override (U+202E, which
        // would make "nimda" read "admin") go; the rest is cut to the limit.
        self::assertSame('Olena K nimda', $this->chipLabelOf(self::user("  Olena\n\tK \u{202E}nimda  ")));
        self::assertSame(
            FormCollaborationEventHandler::LABEL_MAX_LENGTH,
            mb_strlen($this->chipLabelOf(self::user(str_repeat('я', 300)))),
        );
    }

    #[Test]
    public function the_two_joiners_stay_because_they_are_spelling(): void
    {
        // U+200D builds an emoji family from three people; U+200C separates
        // two letters in Persian. Stripping them changes what the name says.
        self::assertSame("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} Olena", $this->chipLabelOf(self::user("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} Olena")));
        self::assertSame("می\u{200C}خواهم", $this->chipLabelOf(self::user("می\u{200C}خواهم")));
        self::assertSame('olena', $this->chipLabelOf(self::user("\u{200D}\u{200C} \u{200D}", 'olena')), 'joiners alone are no name');
    }

    #[Test]
    public function a_name_that_is_not_text_falls_through(): void
    {
        self::assertSame('olena', $this->chipLabelOf(self::user("\xC3\x28", 'olena')), 'invalid UTF-8 is no label');
        self::assertSame(self::USER_ID, $this->chipLabelOf(self::user("\u{200B}\u{200B}")), 'neither is an invisible one');
    }

    #[Test]
    public function markup_in_a_name_is_kept_as_the_characters_it_is(): void
    {
        // Not stripped and not entity-encoded: it is data, and the runtime
        // writes it as text (FormCollabRuntimeTest renders it).
        $hostile = '<img src=x onerror=alert(1)>';

        self::assertSame($hostile, $this->chipLabelOf(self::user($hostile)));
    }

    #[Test]
    public function an_anonymous_editor_is_still_a_guest_keyed_by_the_tab(): void
    {
        $this->handler(null)->handle((object) [], $this->event('presence.ping'));

        $participant = $this->presence->roster(self::SCOPE)[0];
        self::assertSame(['tab-1', 'Guest'], [$participant->participantId, $participant->label]);
    }
}

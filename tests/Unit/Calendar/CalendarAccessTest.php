<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\CalendarEventDeleteHandler;
use Semitexa\PlatformUi\Application\Payload\Request\CalendarEventDeletePayload;
use Semitexa\PlatformUi\Application\Payload\Request\CalendarEventSavePayload;
use Semitexa\PlatformUi\Application\Payload\Request\CalendarEventsFeedPayload;
use Semitexa\PlatformUi\Domain\Contract\CalendarEventRepositoryInterface;
use Semitexa\PlatformUi\Domain\Model\CalendarEvent;
use Semitexa\PlatformUi\Domain\Security\CalendarPermission;

/**
 * The calendar endpoints used to be public: anyone could read, create, change
 * or delete any user's events. Access now goes through configured
 * permissions, and a user only ever acts on their own events.
 */
final class CalendarAccessTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function endpoints(): iterable
    {
        yield 'feed' => [CalendarEventsFeedPayload::class, CalendarPermission::READ];
        yield 'save' => [CalendarEventSavePayload::class, CalendarPermission::WRITE];
        yield 'delete' => [CalendarEventDeletePayload::class, CalendarPermission::WRITE];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function every_endpoint_is_protected_by_its_permission(string $payloadClass, string $permission): void
    {
        $resolver = new PayloadAccessPolicyResolver();
        $payload = (new \ReflectionClass($payloadClass))->newInstanceWithoutConstructor();

        $resolver->assertValidMetadata($payload);
        self::assertSame(PayloadAccessType::Protected, $resolver->accessType($payload));
        self::assertSame([$permission], $resolver->requiredPermissions($payload));
    }

    #[Test]
    public function a_user_cannot_delete_another_users_event(): void
    {
        // The event exists, so the ownership check — not a findById() miss —
        // is what has to stop the delete.
        $events = $this->createMock(CalendarEventRepositoryInterface::class);
        $events->expects(self::once())
            ->method('findById')
            ->with('ev-1')
            ->willReturn(new CalendarEvent('ev-1', null, 'alice', 'Standup', new \DateTimeImmutable(), new \DateTimeImmutable()));
        $events->expects(self::never())->method('deleteById');

        $this->expectException(AccessDeniedException::class);
        $this->deleteAs('mallory', $events, 'ev-1');
    }

    #[Test]
    public function the_owner_can_delete_their_event(): void
    {
        $events = $this->repository(new CalendarEvent('ev-1', null, 'alice', 'Standup', new \DateTimeImmutable(), new \DateTimeImmutable()));

        $this->deleteAs('alice', $events, 'ev-1');

        self::assertSame(['ev-1'], $events->deleted);
    }

    #[Test]
    public function a_guest_is_denied_even_if_the_route_guard_were_bypassed(): void
    {
        $events = $this->repository(new CalendarEvent('ev-1', null, 'alice', 'Standup', new \DateTimeImmutable(), new \DateTimeImmutable()));

        $this->expectException(AccessDeniedException::class);
        $this->deleteAs(null, $events, 'ev-1');
    }

    private function deleteAs(?string $userId, CalendarEventRepositoryInterface $events, string $eventId): void
    {
        $handler = new CalendarEventDeleteHandler();
        (new \ReflectionProperty($handler, 'events'))->setValue($handler, $events);
        (new \ReflectionProperty($handler, 'auth'))->setValue($handler, $this->auth($userId));

        $payload = new CalendarEventDeletePayload();
        (new \ReflectionProperty($payload, 'id'))->setValue($payload, $eventId);

        $handler->handle($payload, new ResourceResponse());
    }

    private function auth(?string $userId): AuthContextInterface
    {
        $user = $userId === null ? null : new class ($userId) implements AuthenticatableInterface {
            public function __construct(private string $id) {}
            public function getId(): string { return $this->id; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return $this->id; }
        };

        return new class ($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return $this->user === null; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?AuthContextInterface { return null; }
            public static function getOrFail(): AuthContextInterface { throw new \LogicException('unused'); }
        };
    }

    private function repository(CalendarEvent ...$stored): CalendarEventRepositoryInterface
    {
        return new class ($stored) implements CalendarEventRepositoryInterface {
            /** @var list<string> */
            public array $deleted = [];

            /** @param list<CalendarEvent> $stored */
            public function __construct(private array $stored) {}

            public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $userId = null): array { return []; }

            public function findById(string $id): ?CalendarEvent
            {
                foreach ($this->stored as $event) {
                    if ($event->getId() === $id) {
                        return $event;
                    }
                }

                return null;
            }

            public function insert(CalendarEvent $event): void {}
            public function update(CalendarEvent $event): void {}
            public function deleteById(string $id): void { $this->deleted[] = $id; }
        };
    }
}

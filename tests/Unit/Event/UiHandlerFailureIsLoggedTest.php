<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;

/**
 * The browser gets `handler_error` and no detail, rightly. Without this log line
 * the reason existed nowhere: a handler failing on an unset property looked like
 * a 422 with nothing to read.
 */
final class UiHandlerFailureIsLoggedTest extends TestCase
{
    protected function tearDown(): void
    {
        StaticLoggerBridge::reset();
    }

    #[Test]
    public function the_reason_goes_to_the_log_and_the_answer_stays_generic(): void
    {
        $logged = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message, array $context = []) use (&$logged): void {
            $logged[] = [$message, $context];
        });
        StaticLoggerBridge::set($logger);

        $failure = (new ReflectionMethod(UiInteractionDispatcher::class, 'handlerFailed'))
            ->invoke(null, new RuntimeException('the draft was never injected'));

        $this->assertCount(1, $logged);
        $this->assertSame('the draft was never injected', $logged[0][1]['message']);
        $this->assertSame(RuntimeException::class, $logged[0][1]['exception']);
        $this->assertStringNotContainsString('draft', $failure->getMessage());
    }
}

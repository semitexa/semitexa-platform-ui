<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
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
    private ?LoggerInterface $before = null;

    protected function setUp(): void
    {
        $this->before = (new ReflectionProperty(StaticLoggerBridge::class, 'logger'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(StaticLoggerBridge::class, 'logger'))->setValue(null, $this->before);
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
        $this->assertSame(basename(__FILE__), explode(':', $logged[0][1]['at'])[0], 'no absolute path in the log');
        $this->assertSame('The declared handler threw while processing this event.', $failure->getMessage());
    }

    #[Test]
    public function a_path_inside_the_message_is_cut_down_to_its_file(): void
    {
        $logged = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message, array $context = []) use (&$logged): void {
            $logged[] = $context;
        });
        StaticLoggerBridge::set($logger);

        (new ReflectionMethod(UiInteractionDispatcher::class, 'handlerFailed'))
            ->invoke(null, new RuntimeException('Argument #1 must be int, called in /home/alice/app/src/Handler.php on line 12'));

        $this->assertSame('Argument #1 must be int, called in Handler.php on line 12', $logged[0]['message']);
    }

    #[Test]
    public function a_logger_that_fails_does_not_change_the_answer(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('disk full'));
        StaticLoggerBridge::set($logger);

        $failure = (new ReflectionMethod(UiInteractionDispatcher::class, 'handlerFailed'))
            ->invoke(null, new RuntimeException('the handler failed'));

        $this->assertSame('The declared handler threw while processing this event.', $failure->getMessage());
    }
}

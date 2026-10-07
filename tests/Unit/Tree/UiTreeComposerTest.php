<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\ScriptedProvider;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeComposer;

/**
 * tk-ai-compose: the reply is checked, the repair errors go back to the model
 * as a correction turn, and only a sound tree is drawn — a tree still wrong
 * after the last round never is.
 */
final class UiTreeComposerTest extends TestCase
{
    #[Test]
    public function a_reply_is_found_inside_prose_or_code_fences(): void
    {
        self::assertSame('{"a":{"b":1}}', UiTreeComposer::jsonOf("Here you go:\n```json\n{\"a\":{\"b\":1}}\n```\nEnjoy."));
        self::assertSame('no json', UiTreeComposer::jsonOf('no json'));
    }

    #[Test]
    public function the_scripted_provider_answers_in_order_and_keeps_what_it_was_sent(): void
    {
        $provider = new ScriptedProvider(['first', 'second']);

        self::assertSame('first', $provider->complete(new \Semitexa\Llm\Domain\Model\LlmRequest('sys', 'hi'))->content);
        self::assertSame('second', $provider->complete(new \Semitexa\Llm\Domain\Model\LlmRequest('sys', 'again'))->content);
        self::assertFalse($provider->complete(new \Semitexa\Llm\Domain\Model\LlmRequest('sys', 'more'))->success);
        self::assertSame(['hi', 'again', 'more'], array_map(static fn ($r) => $r->userMessage, $provider->requests));
    }
}

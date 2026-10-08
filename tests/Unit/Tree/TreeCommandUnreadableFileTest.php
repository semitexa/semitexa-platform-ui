<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Console\Command\TreeComposeCommand;
use Semitexa\PlatformUi\Application\Console\Command\TreeRenderCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A file the tree commands cannot read is an error that names it — before
 * anything boots — not an empty reply blamed on the model, nor
 * "rendered: false" with no reason.
 */
final class TreeCommandUnreadableFileTest extends TestCase
{
    #[Test]
    public function render_names_a_tree_file_it_cannot_read(): void
    {
        $missing = sys_get_temp_dir() . '/no-such-tree-' . bin2hex(random_bytes(4)) . '.json';
        $tester = new CommandTester(new TreeRenderCommand());

        self::assertSame(1, $tester->execute(['tree' => $missing]));
        $out = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(TreeRenderCommand::ARTIFACT, $out['artifact']);
        self::assertFalse($out['rendered']);
        self::assertSame([['code' => 'tree.unreadable', 'path' => '', 'message' => "No tree could be read from {$missing}."]], $out['errors']);
    }

    #[Test]
    public function compose_names_a_scripted_reply_it_cannot_read(): void
    {
        $reply = tempnam(sys_get_temp_dir(), 'reply');
        self::assertIsString($reply);
        file_put_contents($reply, '{}');
        $missing = sys_get_temp_dir() . '/no-such-reply-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $tester = new CommandTester(new TreeComposeCommand());

            self::assertSame(1, $tester->execute(['description' => 'Orders', '--scripted' => $reply . ', ' . $missing]));
            $out = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([
                'artifact' => TreeComposeCommand::ARTIFACT,
                'composed' => false,
                'rounds' => 0,
                'errorsPerRound' => [],
                'failure' => "No model reply could be read from {$missing}.",
                'html' => null,
            ], $out);
        } finally {
            @unlink($reply);
        }
    }
}

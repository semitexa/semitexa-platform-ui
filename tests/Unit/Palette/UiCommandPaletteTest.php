<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Palette;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\CommandPaletteComponent;
use Semitexa\PlatformUi\Application\Service\Palette\UiCommandResultsHtml;
use Semitexa\PlatformUi\Application\Service\Palette\UiCommandSourceInterface;
use Semitexa\PlatformUi\Application\Service\Palette\UiCommandSources;
use Semitexa\PlatformUi\Attribute\AsCommandSource;
use Semitexa\PlatformUi\Domain\Model\Palette\UiPaletteItem;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/** tk-br-command-palette: server commands, filtered by the visitor's permissions. */
final class UiCommandPaletteTest extends TestCase
{
    protected function tearDown(): void
    {
        UiCommandSources::reset();
    }

    #[Test]
    public function commands_needing_a_permission_the_visitor_lacks_never_leave_the_server(): void
    {
        UiCommandSources::add('test', new PaletteFixtureSource());
        UiCommandSources::checkPermissionsWith(static fn (string $permission): bool => $permission === 'content.read');

        $titles = array_map(static fn (UiPaletteItem $c): string => $c->title, UiCommandSources::search('art'));
        self::assertSame(['Article one', 'Public page'], $titles, 'the content.publish command is gone');
    }

    #[Test]
    public function a_permission_nobody_can_check_is_not_granted(): void
    {
        UiCommandSources::add('test', new PaletteFixtureSource());

        self::assertSame(['Public page'], array_map(static fn (UiPaletteItem $c): string => $c->title, UiCommandSources::search('art')));
    }

    #[Test]
    public function queries_and_limits_are_bounded(): void
    {
        UiCommandSources::add('many', new PaletteManySource());
        UiCommandSources::add('more', new PaletteManySource());

        self::assertSame([], UiCommandSources::search('   '));
        self::assertSame([], UiCommandSources::search(str_repeat('x', 101)));
        self::assertCount(UiCommandSources::PER_SOURCE * 2, UiCommandSources::search('item'), 'each source capped at PER_SOURCE');
        for ($i = 0; $i < 3; $i++) {
            UiCommandSources::add('extra' . $i, new PaletteManySource());
        }
        self::assertCount(UiCommandSources::TOTAL, UiCommandSources::search('item'));
    }

    #[Test]
    public function a_command_goes_to_a_same_origin_path_only(): void
    {
        foreach (['https://evil.test/', '//evil.test', '/\\evil.test', 'javascript:alert(1)'] as $href) {
            try {
                new UiPaletteItem('x', $href);
                self::fail($href . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_results_are_escaped_options_under_the_patch_target(): void
    {
        $html = UiCommandResultsHtml::render('uci_palette_test_01', '<q>', [new UiPaletteItem('<b>Bold</b>', '/a?x=1&y=2', 'G<1>', 'sub')]);

        self::assertStringStartsWith('<div data-ui-patch-target="server-results" data-query="&lt;q&gt;">', $html);
        self::assertStringContainsString('<a role="option" id="uci_palette_test_01-s0" href="/a?x=1&amp;y=2"', $html);
        self::assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
        self::assertStringContainsString('aria-label="G&lt;1&gt;"', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    #[Test]
    public function typing_answers_with_a_replace_of_the_server_section(): void
    {
        UiCommandSources::add('test', new PaletteFixtureSource());
        $event = new UiInteractionEvent(
            componentName: 'platform.command-palette', instanceId: 'uci_palette_test_01', partName: 'query', eventName: 'input',
            updatesPath: null, payload: ['value' => '  art  '], issuedAt: time(), expiresAt: time() + 60,
        );
        $result = (new CommandPaletteComponent())->onQuery($event);

        self::assertCount(1, $result->patches);
        self::assertSame(UiResponsePatch::OP_REPLACE, $result->patches[0]->op);
        self::assertSame('server-results', $result->patches[0]->targetName);
        self::assertStringContainsString('data-query="art"', (string) $result->patches[0]->value);
    }

    #[Test]
    public function discovery_needs_the_interface(): void
    {
        $this->expectException(\LogicException::class);
        UiCommandSources::discover([PaletteNotASource::class], static fn (string $c): object => new $c());
    }
}

final class PaletteFixtureSource implements UiCommandSourceInterface
{
    public function search(string $query, int $limit): iterable
    {
        yield new UiPaletteItem('Article one', '/articles/1', 'Articles', permission: 'content.read');
        yield new UiPaletteItem('New article', '/articles/new', 'Articles', permission: 'content.publish');
        yield new UiPaletteItem('Public page', '/public');
    }
}

final class PaletteManySource implements UiCommandSourceInterface
{
    public function search(string $query, int $limit): iterable
    {
        for ($i = 0; $i < 50; $i++) {
            yield new UiPaletteItem('Item ' . $i, '/item/' . $i);
        }
    }
}

#[AsCommandSource]
final class PaletteNotASource
{
}

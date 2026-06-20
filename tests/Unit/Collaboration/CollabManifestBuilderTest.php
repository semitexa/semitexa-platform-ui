<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Collaboration\CollabManifestBuilder;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * Collaborative Form Data · Phase 3 — the collab manifest the form emits for
 * `form-collab-runtime.js`. The builder mints the read feed token (cfg only)
 * and the per-event write tokens (the routable (c,p,e) triple + the same cfg);
 * the runtime relays them opaquely. These tests verify the wire shape and that
 * every minted token round-trips through {@see SignedContext::verify()} with the
 * trusted scope/mode intact — the read↔write trust the whole feature rests on.
 */
final class CollabManifestBuilderTest extends TestCase
{
    private const COMPONENT = 'playground.collab-form';
    private const INSTANCE = 'uci_abc123';

    private function build(string $mode): array
    {
        return (new CollabManifestBuilder())->build(
            componentName: self::COMPONENT,
            instanceId: self::INSTANCE,
            formKey: 'article',
            recordId: '42',
            mode: $mode,
            fields: ['title', 'body'],
        );
    }

    #[Test]
    public function it_emits_the_runtime_wire_shape(): void
    {
        $m = $this->build('shared');

        self::assertSame(1, $m['v']);
        self::assertSame(self::INSTANCE, $m['i']);
        self::assertSame('formdoc:article:42', $m['scope']);
        self::assertSame('shared', $m['mode']);
        self::assertSame('/__ui/form-doc', $m['feedUrl']);
        self::assertSame('/__ui/event', $m['eventUrl']);
        self::assertSame(['title', 'body'], $m['fields']);
        // self defaults to the instance id when no auth user is present.
        self::assertSame(self::INSTANCE, $m['self']);
    }

    #[Test]
    public function the_feed_token_carries_the_trusted_scope_and_mode(): void
    {
        $m = $this->build('shared');

        $claims = SignedContext::verify($m['feedCtx']);
        self::assertIsArray($claims);
        self::assertSame('formdoc:article:42', $claims['cfg']['scope']);
        self::assertSame('shared', $claims['cfg']['mode']);
    }

    #[Test]
    public function shared_mode_mints_routable_field_edit_and_presence_tokens(): void
    {
        $m = $this->build('shared');

        self::assertSame(['field.edit', 'presence.ping'], array_keys($m['events']));

        $fieldEdit = SignedContext::verify($m['events']['field.edit']);
        self::assertSame(self::COMPONENT, $fieldEdit['c']);
        self::assertSame('field', $fieldEdit['p']);
        self::assertSame('edit', $fieldEdit['e']);
        // Each event token ALSO carries cfg so the inbound handler reads
        // scope/mode from the signed claim, never the request body.
        self::assertSame('formdoc:article:42', $fieldEdit['cfg']['scope']);

        $ping = SignedContext::verify($m['events']['presence.ping']);
        self::assertSame('presence', $ping['p']);
        self::assertSame('ping', $ping['e']);
    }

    #[Test]
    public function optimistic_mode_mints_a_form_save_token_and_no_live_field_edit(): void
    {
        $m = $this->build('optimistic');

        // No live coupling: a save-time version guard instead of keystroke
        // broadcast, so a form.save token replaces field.edit. Presence stays.
        self::assertSame(['form.save', 'presence.ping'], array_keys($m['events']));
        self::assertArrayNotHasKey('field.edit', $m['events']);

        $save = SignedContext::verify($m['events']['form.save']);
        self::assertSame('form', $save['p']);
        self::assertSame('save', $save['e']);
        self::assertSame('optimistic', $m['mode']);
    }

    #[Test]
    public function lock_modes_add_the_lock_lifecycle_events(): void
    {
        $m = $this->build('field-lock');

        self::assertSame(
            ['field.edit', 'presence.ping', 'lock.acquire', 'lock.release', 'lock.heartbeat'],
            array_keys($m['events']),
        );
        self::assertSame('field-lock', $m['mode']);
    }

    #[Test]
    public function an_unknown_mode_falls_back_to_the_default(): void
    {
        $m = $this->build('nonsense');

        // FormCollaborationMode::default() — not a crash, and a valid wire mode.
        self::assertNotSame('nonsense', $m['mode']);
        self::assertNotSame('', $m['mode']);
        $claims = SignedContext::verify($m['feedCtx']);
        self::assertSame($m['mode'], $claims['cfg']['mode']);
    }
}

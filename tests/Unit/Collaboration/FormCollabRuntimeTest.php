<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\FormDocumentFeedHandler;
use Semitexa\PlatformUi\Application\Payload\Request\FormDocumentFeedPayload;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CollabManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollabSaveNotices;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationDrafts;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormDocumentProjector;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatchAdapter;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;
use Semitexa\PlatformUi\Tests\Support\CollabScopeRecorder;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * The server and the SHIPPED form-collab-runtime.js, end to end without a
 * browser: the stores and the feed handler produce the frames an open editor
 * would receive, and node runs the runtime over a recording DOM
 * (tests/Support/collab-runtime-harness.mjs) to say what that editor then sees.
 *
 * Node is in the framework image (it runs the ssr parity test too); where it
 * is missing these fail and say so.
 */
final class FormCollabRuntimeTest extends TestCase
{
    private const SCOPE = 'formdoc:property:42';
    private const HARNESS = __DIR__ . '/../../Support/collab-runtime-harness.mjs';
    private const RUNTIME = __DIR__ . '/../../../src/Application/Static/js/form-collab-runtime.js';

    private InMemoryFormCollabDraftStore $draft;

    private CacheBackedFormPresenceStore $presence;

    private CacheBackedFormLockStore $locks;

    private CollabScopeRecorder $scopes;

    private FormCollabSaveNotices $notices;

    protected function setUp(): void
    {
        $cache = new ArrayCacheManager();
        $this->draft = new InMemoryFormCollabDraftStore();
        $this->presence = (new CacheBackedFormPresenceStore())->withCacheManager($cache);
        $this->locks = (new CacheBackedFormLockStore())->withCacheManager($cache);
        $this->scopes = new CollabScopeRecorder();
        $this->notices = (new FormCollabSaveNotices())->withCacheManager($cache);
    }

    private function drafts(): FormCollaborationDrafts
    {
        return (new FormCollaborationDrafts())->with($this->draft, $this->scopes, $this->notices);
    }

    private function handler(): FormCollaborationEventHandler
    {
        return (new FormCollaborationEventHandler())->withCollaborationDeps($this->draft, $this->presence, $this->locks, $this->scopes);
    }

    #[Test]
    public function the_touch_of_a_discard_reaches_an_open_editor_and_rebases_its_inputs(): void
    {
        // Bob has the form open. Alice typed a price and notes; her POST saved
        // the record (the app normalised the price), and the app ended the draft.
        $payload = $this->feedOf('shared', ['price', 'title', 'notes']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120.5', 'title' => 'Flat', 'notes' => 'call owner'], 'alice');
        $whileEditing = $this->frame($payload);

        $this->drafts()->discardForRecord('property', '42', ['price' => '120.50', 'title' => 'Flat', 'notes' => '']);

        self::assertSame($payload->dynamicWatchScopes(), $this->scopes->touched, "the touch is on the scope Bob's feed watches");
        $afterSave = $this->frame($payload);
        self::assertSame([], $afterSave['data']['values'], 'no draft is left holding a copy of the record');

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price', 'title', 'notes']),
            'inputs' => ['price' => '100', 'title' => 'Flat', 'notes' => ''],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $afterSave], ['snapshot' => $afterSave]],
        ]);

        self::assertSame(['price' => '120.50', 'title' => 'Flat', 'notes' => ''], $seen['values'], 'what was stored, the unsaved note gone');
        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => true]]], $seen['events'], 'once, however often the frame repeats');
        self::assertSame(['presence.ping'], array_column($seen['posts'], 'event'), 'his heartbeat and nothing else: applying a frame is not an edit of his own');
    }

    #[Test]
    public function a_field_being_typed_in_survives_the_rebase_as_a_new_edit(): void
    {
        $payload = $this->feedOf('shared', ['price', 'title']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120', 'title' => 'Flat'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->drafts()->discard(self::SCOPE, ['price' => '125', 'title' => 'Flat']);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price', 'title']),
            'inputs' => ['price' => '100', 'title' => 'Flat'],
            'steps' => [['snapshot' => $whileEditing], ['focus' => 'title'], ['type' => 'title', 'value' => 'Flat with a view'], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(['price' => '125', 'title' => 'Flat with a view'], $seen['values']);
        self::assertSame(
            [['field' => 'title', 'value' => 'Flat with a view'], ['field' => 'title', 'value' => 'Flat with a view']],
            array_column(array_filter($seen['posts'], static fn (array $p): bool => $p['event'] === 'field.edit'), 'payload'),
            'typed once, and sent again when the draft that held it ended',
        );
    }

    #[Test]
    public function a_field_the_app_did_not_name_keeps_what_the_tab_showed(): void
    {
        // The documented contract: the frame re-bases exactly the fields it
        // names, so the app passes every managed field.
        $payload = $this->feedOf('shared', ['price', 'notes']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120', 'notes' => 'call owner'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->drafts()->discard(self::SCOPE, ['price' => '125']);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price', 'notes']),
            'inputs' => ['price' => '100', 'notes' => ''],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(['price' => '125', 'notes' => 'call owner'], $seen['values']);
    }

    #[Test]
    public function a_discard_with_no_values_leaves_the_inputs_and_tells_the_page(): void
    {
        $payload = $this->feedOf('shared', ['price', 'notes']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->drafts()->discard(self::SCOPE);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price', 'notes']),
            'inputs' => ['price' => '100', 'notes' => ''],
            'steps' => [['snapshot' => $whileEditing], ['type' => 'notes', 'value' => 'mine'], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(['price' => '120', 'notes' => 'mine'], $seen['values'], 'nothing is overwritten with a guess');
        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => false]]], $seen['events']);
    }

    #[Test]
    public function unsaved_optimistic_edits_on_a_discarded_draft_meet_the_conflict_prompt(): void
    {
        $payload = $this->feedOf('optimistic', ['price']);
        $this->draft->apply(self::SCOPE, ['price' => '100'], 0, 'alice');
        $base = $this->frame($payload);
        $this->drafts()->discard(self::SCOPE);
        $after = $this->frame($payload);
        $scenario = [
            'manifest' => self::manifest('optimistic', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'save' => true,
            'steps' => [['snapshot' => $base], ['type' => 'price', 'value' => '999'], ['snapshot' => $after], ['save' => true]],
        ];

        // What he posts, answered by the real handler ...
        $save = self::saves($this->editorSees($scenario));
        self::assertSame([['values' => ['price' => '999'], 'version' => 1, 'epoch' => $base['data']['epoch']]], $save, 'still based on the draft that ended');
        $answer = $this->answer($save[0]);
        self::assertSame('form_draft_version_conflict', $answer['reason']);

        // ... and what he sees when that answer comes back.
        $seen = $this->editorSees($scenario + ['replies' => ['form.save' => [$answer]]]);
        self::assertFalse($seen['conflict']['hidden']);
        self::assertSame(['Keep mine', 'Take theirs'], $seen['conflict']['buttons']);
        self::assertSame(['price' => '999'], $seen['values']);
    }

    #[Test]
    public function a_save_names_the_draft_it_was_based_on_and_the_server_refuses_another(): void
    {
        // Bob edits on version 2 of one draft. It is discarded and other
        // people's saves bring the NEXT draft to version 2 as well. The number
        // matches; the draft does not.
        $payload = $this->feedOf('optimistic', ['price']);
        $this->draft->apply(self::SCOPE, ['price' => '100'], 0, 'alice');
        $this->draft->apply(self::SCOPE, ['price' => '110'], 1, 'alice');
        $base = $this->frame($payload);
        self::assertIsString($base['data']['epoch']);
        self::assertNotSame('', $base['data']['epoch']);

        $seen = $this->editorSees([
            'manifest' => self::manifest('optimistic', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'save' => true,
            'steps' => [['snapshot' => $base], ['type' => 'price', 'value' => '999'], ['save' => true]],
        ]);
        $save = array_values(array_filter($seen['posts'], static fn (array $p): bool => $p['event'] === 'form.save'));
        self::assertCount(1, $save);
        self::assertSame(['values' => ['price' => '999'], 'version' => 2, 'epoch' => $base['data']['epoch']], $save[0]['payload']);

        $this->draft->discard(self::SCOPE);
        $this->draft->apply(self::SCOPE, ['price' => '300'], 0, 'carol');
        $this->draft->apply(self::SCOPE, ['price' => '310'], 1, 'carol');
        self::assertSame(2, $this->draft->load(self::SCOPE)?->version, 'the coincidence this guards against');

        $resp = $this->handler()->handle((object) [], $this->event('form.save', 'optimistic', $save[0]['payload'], ['price']));
        self::assertSame('form_draft_version_conflict', $resp->error?->code);
        self::assertSame(['price' => '310'], $this->draft->load(self::SCOPE)?->values, "Carol's draft stands");
    }

    #[Test]
    public function a_draft_created_and_discarded_between_two_frames_is_still_noticed(): void
    {
        // No draft before, no draft after: the draft part of the two frames is
        // identical. The save notice is what tells the page the record moved.
        $payload = $this->feedOf('optimistic', ['price']);
        $before = $this->frame($payload);
        $this->draft->apply(self::SCOPE, ['price' => '500'], 0, 'alice');
        $this->drafts()->discard(self::SCOPE);
        $after = $this->frame($payload);
        self::assertSame($before['data'], $after['data']);

        $seen = $this->editorSees([
            'manifest' => self::manifest('optimistic', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'steps' => [['snapshot' => $before], ['snapshot' => $after]],
        ]);

        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => false]]], $seen['events']);
    }

    #[Test]
    public function a_draft_that_ends_without_a_notice_tells_the_page_too(): void
    {
        // An expiry, or an app that called the store's discard() itself.
        $payload = $this->feedOf('shared', ['price']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->draft->discard(self::SCOPE);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => false]]], $seen['events']);
        self::assertSame(['price' => '120'], $seen['values']);
    }

    #[Test]
    public function the_page_the_saver_is_redirected_to_is_not_told_about_her_own_save(): void
    {
        // POST, save, discard, redirect, render: all within one second, so no
        // clock can order the notice and the page. The manifest names the
        // notice that was current when the page was rendered instead.
        $this->drafts()->discardForRecord('property', '42', ['price' => '125']);
        $manifest = (new CollabManifestBuilder())->build('platform.collab-form', 'inst-1', 'property', '42', 'shared', ['price'], null, $this->notices);
        $notice = $this->notices->current(self::SCOPE);
        self::assertNotNull($notice);
        self::assertSame($notice['id'], $manifest['saved']);
        $frame = $this->frame($this->feedOf('shared', ['price']));
        self::assertIsArray($frame['meta']['saved']);
        self::assertSame($notice['id'], $frame['meta']['saved']['id']);

        $seen = $this->editorSees([
            // She saved 125; the app shows it with a currency format the
            // notice's raw value would overwrite.
            'manifest' => $manifest,
            'inputs' => ['price' => '125.00 EUR'],
            'steps' => [['snapshot' => $frame], ['snapshot' => $frame]],
        ]);

        self::assertSame(['price' => '125.00 EUR'], $seen['values'], 'no re-base on the fresh page');
        self::assertSame([], $seen['events'], 'and no reset');
    }

    #[Test]
    public function a_save_after_the_one_the_page_was_rendered_with_is_news(): void
    {
        $this->drafts()->discardForRecord('property', '42', ['price' => '125']);
        $manifest = (new CollabManifestBuilder())->build('platform.collab-form', 'inst-1', 'property', '42', 'shared', ['price'], null, $this->notices);
        $payload = $this->feedOf('shared', ['price']);
        $first = $this->frame($payload);
        $this->drafts()->discardForRecord('property', '42', ['price' => '130']);

        $seen = $this->editorSees([
            'manifest' => $manifest,
            'inputs' => ['price' => '125'],
            'steps' => [['snapshot' => $first], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(['price' => '130'], $seen['values']);
        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => true]]], $seen['events']);
    }

    #[Test]
    public function a_page_whose_manifest_names_no_notice_takes_the_first_frames_as_its_start(): void
    {
        // The cache could not say at render time (or there was none). Nothing
        // shows the notice in the first frame is newer than the page, and it
        // may be a day old: applying it would be the stale overlay again.
        $this->drafts()->discardForRecord('property', '42', ['price' => '125']);
        $payload = $this->feedOf('shared', ['price']);
        $first = $this->frame($payload);
        self::assertIsArray($first['meta']['saved']);
        $this->drafts()->discardForRecord('property', '42', ['price' => '130']);

        $scenario = [
            'manifest' => self::manifest('shared', 'bob', ['price']),
            'inputs' => ['price' => '140'],
            'steps' => [['snapshot' => $first]],
        ];
        $seen = $this->editorSees($scenario);
        self::assertSame(['price' => '140'], $seen['values'], 'what the page rendered');
        self::assertSame([], $seen['events']);

        $scenario['steps'][] = ['snapshot' => $this->frame($payload)];
        self::assertSame(['price' => '130'], $this->editorSees($scenario)['values'], 'a later save is news as usual');
    }

    #[Test]
    public function a_frame_that_carries_the_notice_with_the_dying_draft_is_one_event_not_two(): void
    {
        // The notice is published before the draft is deleted. A heartbeat in
        // between pushes a frame with both.
        $payload = $this->feedOf('shared', ['price']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->notices->publish(self::SCOPE, ['price' => '125']);
        $between = $this->frame($payload);
        self::assertSame(['price' => '120'], $between['data']['values']);
        $this->draft->discard(self::SCOPE);
        $after = $this->frame($payload);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $between], ['snapshot' => $after]],
        ]);

        self::assertSame(['price' => '125'], $seen['values'], "the stored value, not the dying draft's");
        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => true]]], $seen['events']);
    }

    #[Test]
    public function a_repeat_of_the_frame_with_the_dying_draft_does_not_put_its_values_back(): void
    {
        // Two heartbeats land between the notice and the delete: the same
        // frame twice. The second is no news, and the draft it carries is
        // still the one the save replaced.
        $payload = $this->feedOf('shared', ['price']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->notices->publish(self::SCOPE, ['price' => '125']);
        $between = $this->frame($payload);
        $this->draft->discard(self::SCOPE);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $between], ['snapshot' => $between], ['snapshot' => $between], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(['price' => '125'], $seen['values'], 'what was stored');
        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => true]]], $seen['events']);
    }

    #[Test]
    public function values_for_a_managed_field_the_page_has_no_input_for_rebase_nothing(): void
    {
        // The page and the save disagree about the form. Moving the fields
        // that happen to exist and reporting a re-base would be half of one.
        $payload = $this->feedOf('shared', ['price', 'notes']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->drafts()->discard(self::SCOPE, ['price' => '125', 'notes' => 'stored']);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price', 'notes']),
            'inputs' => ['price' => '100'],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(['price' => '120'], $seen['values'], 'no input moved');
        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => false]]], $seen['events']);
    }

    #[Test]
    public function values_the_app_passes_for_what_is_not_a_managed_field_are_no_target(): void
    {
        $payload = $this->feedOf('shared', ['price']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->drafts()->discard(self::SCOPE, ['id' => '42', 'price' => '125', 'owner' => 'u-7']);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(['price' => '125'], $seen['values']);
        self::assertSame([['type' => 'semitexa:collab:reset', 'detail' => ['rebased' => true]]], $seen['events']);
    }

    #[Test]
    public function take_theirs_does_not_offer_what_an_earlier_save_stored(): void
    {
        // Save 1 said price=125. Save 2 ended the next draft and said nothing:
        // it may have stored 150. "Theirs" is then not known, and 125 is not it.
        $payload = $this->feedOf('optimistic', ['price']);
        $this->draft->apply(self::SCOPE, ['price' => '100'], 0, 'alice');
        $base = $this->frame($payload);
        $this->drafts()->discard(self::SCOPE, ['price' => '125']);
        $afterFirst = $this->frame($payload);
        $this->draft->apply(self::SCOPE, ['price' => '140'], 0, 'carol');
        $this->drafts()->discard(self::SCOPE);
        $afterSecond = $this->frame($payload);
        $steps = [['snapshot' => $base], ['type' => 'price', 'value' => '999'], ['snapshot' => $afterFirst], ['snapshot' => $afterSecond], ['save' => true]];
        $scenario = ['manifest' => self::manifest('optimistic', 'bob', ['price']), 'inputs' => ['price' => '100'], 'save' => true, 'steps' => $steps];

        $conflict = $this->answer(self::saves($this->editorSees($scenario))[0]);
        self::assertSame('form_draft_version_conflict', $conflict['reason']);
        $scenario['replies'] = ['form.save' => [$conflict]];
        $scenario['steps'][] = ['click' => 'data-ui-collab-take-theirs'];

        self::assertSame(['price' => '999'], $this->editorSees($scenario)['values'], 'left as it is: neither 125 nor blank');
    }

    #[Test]
    public function a_discard_that_failed_after_its_notice_does_not_hide_the_real_end_of_the_draft(): void
    {
        // Notice published, delete failed: the draft lives on, frame after
        // frame. When it does end, much later, the page must hear of it.
        $payload = $this->feedOf('shared', ['price']);
        $this->draft->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $whileEditing = $this->frame($payload);
        $this->notices->publish(self::SCOPE, ['price' => '125']);
        $between = $this->frame($payload);
        $this->draft->mergeFields(self::SCOPE, ['price' => '126'], 'alice');
        $stillThere = $this->frame($payload);
        $this->draft->discard(self::SCOPE);

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['price']),
            'inputs' => ['price' => '100'],
            'steps' => [['snapshot' => $whileEditing], ['snapshot' => $between], ['snapshot' => $stillThere], ['snapshot' => $this->frame($payload)]],
        ]);

        self::assertSame(
            [['rebased' => true], ['rebased' => false]],
            array_column($seen['events'], 'detail'),
        );
    }

    #[Test]
    public function with_the_stream_down_the_second_save_passes_on_what_the_first_ack_said(): void
    {
        // The first save of a document creates its draft. No frame arrives to
        // say which; the ack does.
        $empty = $this->frame($this->feedOf('optimistic', ['price']));
        $steps = [['snapshot' => $empty], ['type' => 'price', 'value' => '110'], ['save' => true]];
        $scenario = ['manifest' => self::manifest('optimistic', 'bob', ['price']), 'inputs' => ['price' => '100'], 'save' => true, 'steps' => $steps];

        $first = self::saves($this->editorSees($scenario));
        self::assertSame([['values' => ['price' => '110'], 'version' => 0, 'epoch' => '']], $first);
        $ack = $this->answer($first[0]);
        self::assertSame('accepted', $ack['status']);

        $scenario['replies'] = ['form.save' => [$ack]];
        $scenario['steps'] = [...$steps, ['type' => 'price', 'value' => '120'], ['save' => true]];
        $both = self::saves($this->editorSees($scenario));
        self::assertCount(2, $both);
        $stored = $this->draft->load(self::SCOPE);
        self::assertNotNull($stored);
        self::assertSame(['values' => ['price' => '120'], 'version' => 1, 'epoch' => $stored->epoch], $both[1]);
        self::assertSame('accepted', $this->answer($both[1])['status']);
        self::assertSame(['price' => '120'], $this->draft->load(self::SCOPE)?->values);
    }

    #[Test]
    public function with_the_stream_down_keep_mine_retries_on_the_base_the_conflict_named(): void
    {
        $payload = $this->feedOf('optimistic', ['price']);
        $this->draft->apply(self::SCOPE, ['price' => '100'], 0, 'alice');
        $base = $this->frame($payload);
        // Meanwhile, unseen by Bob: the draft is replaced and saved twice.
        $this->draft->discard(self::SCOPE);
        $this->draft->apply(self::SCOPE, ['price' => '300'], 0, 'carol');
        $current = $this->draft->apply(self::SCOPE, ['price' => '310'], 1, 'carol');
        $steps = [['snapshot' => $base], ['type' => 'price', 'value' => '999'], ['save' => true]];
        $scenario = ['manifest' => self::manifest('optimistic', 'bob', ['price']), 'inputs' => ['price' => '100'], 'save' => true, 'steps' => $steps];

        $conflict = $this->answer(self::saves($this->editorSees($scenario))[0]);
        self::assertSame('form_draft_version_conflict', $conflict['reason']);

        $scenario['replies'] = ['form.save' => [$conflict]];
        $scenario['steps'][] = ['click' => 'data-ui-collab-keep-mine'];
        $retry = self::saves($this->editorSees($scenario))[1] ?? null;
        self::assertSame(['values' => ['price' => '999'], 'version' => $current->version, 'epoch' => $current->epoch], $retry);
        self::assertSame('accepted', $this->answer($retry)['status'], 'one retry, not a loop of conflicts');
    }

    #[Test]
    public function a_hostile_name_is_text_in_the_chip_and_in_locked_by(): void
    {
        $hostile = '<img src=x onerror=alert(1)>"\'&amp;</span><script>a()</script>';
        self::assertLessThanOrEqual(FormCollaborationEventHandler::LABEL_MAX_LENGTH, mb_strlen($hostile));
        $mallory = new class ($hostile) {
            public function __construct(private string $name)
            {
            }

            public function getId(): string
            {
                return 'u-mallory';
            }

            public function getName(): string
            {
                return $this->name;
            }
        };
        $handler = (new FormCollaborationEventHandler())
            ->withCollaborationDeps($this->draft, $this->presence, $this->locks, $this->scopes)
            ->withIdentity(static fn (): object => $mallory);
        $handler->handle((object) [], $this->event('presence.ping', 'form-lock'));
        $handler->handle((object) [], $this->event('lock.acquire', 'form-lock'));
        $handler->handle((object) [], $this->event('lock.acquire', 'field-lock', ['field' => 'title']));

        $formLock = $this->editorSees([
            'manifest' => self::manifest('form-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'lockHost' => true,
            'steps' => [['snapshot' => $this->frame($this->feedOf('form-lock', ['title']))]],
        ]);
        $fieldLock = $this->editorSees([
            'manifest' => self::manifest('field-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'lockHost' => true,
            'steps' => [['snapshot' => $this->frame($this->feedOf('field-lock', ['title']))]],
        ]);

        self::assertCount(1, $formLock['chips']);
        self::assertSame($hostile, $formLock['chips'][0]['text'], 'the chip shows the name, character for character');
        self::assertSame(0, $formLock['chips'][0]['elements'], 'and holds no element made from it');
        self::assertSame(['text' => '🔒 Locked by ' . $hostile, 'elements' => 0], ['text' => $formLock['lock']['text'] ?? null, 'elements' => $formLock['lock']['elements'] ?? null]);
        self::assertSame($hostile, $fieldLock['fieldLocks']['title'], 'an attribute value, set as one');
        self::assertSame([], $formLock['htmlSinks'], 'nothing was written as HTML');
        self::assertSame([], $fieldLock['htmlSinks']);
    }

    #[Test]
    public function edits_answered_out_of_order_leave_the_last_value_typed_on_the_server(): void
    {
        // Every field.edit carries the whole value and the server keeps the
        // one that arrives last. Three keystrokes, answered newest first.
        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [
                ['focus' => 'title'],
                ['type' => 'title', 'value' => 'Flat'],
                ['type' => 'title', 'value' => 'Flat by the town hall'],
                ['type' => 'title', 'value' => 'Flat by the town hall.'],
                ['answer' => 'newest-first'],
            ],
        ]);

        $arrived = self::edits($seen['served']);
        self::assertSame(['field' => 'title', 'value' => 'Flat by the town hall.'], end($arrived), 'the draft ends on what was typed last');
        self::assertSame(['title' => 1], $seen['maxInFlight']);
    }

    #[Test]
    public function a_field_has_one_edit_in_flight_and_keeps_only_the_latest_value_behind_it(): void
    {
        $scenario = [
            'manifest' => self::manifest('shared', 'bob', ['title', 'price']),
            'inputs' => ['title' => '', 'price' => ''],
            'hold' => ['field.edit'],
            'steps' => [
                ['type' => 'title', 'value' => 'a'],
                ['type' => 'title', 'value' => 'ab'],
                ['type' => 'title', 'value' => 'abc'],
                ['type' => 'price', 'value' => '1'],
            ],
        ];
        $seen = $this->editorSees($scenario);

        self::assertSame(
            [['field' => 'title', 'value' => 'a'], ['field' => 'price', 'value' => '1']],
            self::edits($seen['posts']),
            'one request per field; another field does not wait for this one',
        );

        $scenario['steps'][] = ['answer' => 'oldest'];
        $seen = $this->editorSees($scenario);

        self::assertSame(
            [['field' => 'title', 'value' => 'a'], ['field' => 'price', 'value' => '1'], ['field' => 'title', 'value' => 'abc']],
            self::edits($seen['posts']),
            'when it settles the latest value goes out, not every one typed meanwhile',
        );
        self::assertSame(['title' => 1, 'price' => 1], $seen['maxInFlight']);
    }

    #[Test]
    public function an_edit_that_failed_is_sent_again(): void
    {
        $scenario = [
            'manifest' => self::manifest('shared', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [['type' => 'title', 'value' => 'abc'], ['fail' => 'oldest']],
        ];
        $once = $this->editorSees($scenario);
        self::assertSame(
            [['field' => 'title', 'value' => 'abc'], ['field' => 'title', 'value' => 'abc']],
            self::edits($once['posts']),
            'nothing newer was typed, so the value that failed is the one to send',
        );

        // It fails again: no loop, the value waits for the heartbeat.
        $scenario['steps'][] = ['fail' => 'oldest'];
        self::assertCount(2, self::edits($this->editorSees($scenario)['posts']), 'two attempts, then it waits');

        $scenario['steps'][] = ['tick' => true];
        $scenario['steps'][] = ['answer' => 'oldest'];
        $seen = $this->editorSees($scenario);
        self::assertCount(3, self::edits($seen['posts']), 'the heartbeat sends it');
        self::assertSame([['field' => 'title', 'value' => 'abc']], self::edits($seen['served']), 'and the server has it');
        self::assertSame(['title' => 1], $seen['maxInFlight']);
    }

    #[Test]
    public function a_newer_value_replaces_the_one_that_failed(): void
    {
        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [
                ['type' => 'title', 'value' => 'a'],
                ['type' => 'title', 'value' => 'ab'],
                ['fail' => 'oldest'],
                ['answer' => 'oldest'],
            ],
        ]);

        self::assertSame([['field' => 'title', 'value' => 'ab']], self::edits($seen['served']));
        self::assertSame(['title' => 1], $seen['maxInFlight']);
    }

    #[Test]
    public function a_frame_does_not_put_back_an_older_value_while_the_edit_is_on_its_way(): void
    {
        // Bob typed and left the field; his edit has not arrived yet. Alice
        // edits the price, and the frame that causes still shows his old title.
        $payload = $this->feedOf('shared', ['title', 'price']);
        $this->draft->mergeFields(self::SCOPE, ['title' => 'Flat', 'price' => '120'], 'alice');

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['title', 'price']),
            'inputs' => ['title' => 'Flat', 'price' => '100'],
            'hold' => ['field.edit'],
            'steps' => [
                ['focus' => 'title'],
                ['type' => 'title', 'value' => 'Flat with a view'],
                ['blur' => 'title'],
                ['snapshot' => $this->frame($payload)],
            ],
        ]);

        self::assertSame(['title' => 'Flat with a view', 'price' => '120'], $seen['values']);
    }

    #[Test]
    public function a_field_lock_is_released_after_the_last_edit_of_the_field_arrived(): void
    {
        // The server refuses an edit of a field the editor no longer holds:
        // a release that overtakes the last edit loses what was typed last.
        $seen = $this->editorSees([
            'manifest' => self::manifest('field-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [
                ['focus' => 'title'],
                ['type' => 'title', 'value' => 'a'],
                ['type' => 'title', 'value' => 'ab'],
                ['blur' => 'title'],
                ['answer' => 'oldest'],
                ['answer' => 'oldest'],
            ],
        ]);

        self::assertSame(
            [
                ['event' => 'field.edit', 'payload' => ['field' => 'title', 'value' => 'a']],
                ['event' => 'field.edit', 'payload' => ['field' => 'title', 'value' => 'ab']],
                ['event' => 'lock.release', 'payload' => ['field' => 'title']],
            ],
            array_values(array_filter($seen['served'], static fn (array $p): bool => in_array($p['event'], ['field.edit', 'lock.release'], true))),
        );
    }

    #[Test]
    public function a_field_focused_again_before_its_release_went_out_keeps_the_lock(): void
    {
        $seen = $this->editorSees([
            'manifest' => self::manifest('field-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [
                ['focus' => 'title'],
                ['type' => 'title', 'value' => 'a'],
                ['blur' => 'title'],
                ['focus' => 'title'],
                ['answer' => 'oldest'],
                ['type' => 'title', 'value' => 'ab'],
                ['answer' => 'oldest'],
            ],
        ]);

        self::assertSame(['lock.acquire', 'field.edit', 'field.edit'], array_values(array_diff(array_column($seen['served'], 'event'), ['presence.ping'])));
    }

    #[Test]
    public function a_form_lock_is_released_after_the_last_edit_arrived(): void
    {
        $seen = $this->editorSees([
            'manifest' => self::manifest('form-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [
                ['focus' => 'title'],
                ['type' => 'title', 'value' => 'a'],
                // The editor is removed from the page with the edit in flight.
                ['unmount' => true],
                ['answer' => 'oldest'],
            ],
        ]);

        self::assertSame(['lock.acquire', 'field.edit', 'lock.release'], array_values(array_diff(array_column($seen['served'], 'event'), ['presence.ping'])));
    }

    #[Test]
    public function a_value_waiting_behind_an_edit_in_flight_is_sent_when_the_page_is_hidden(): void
    {
        // The app's own form post navigates away; nothing will be there when
        // the edit in flight settles.
        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [['type' => 'title', 'value' => 'a'], ['type' => 'title', 'value' => 'ab'], ['pagehide' => true]],
        ]);

        self::assertSame([['field' => 'title', 'value' => 'a'], ['field' => 'title', 'value' => 'ab']], self::edits($seen['posts']));
    }

    #[Test]
    public function the_form_data_carries_the_fields_a_lock_disabled_and_they_stay_locked(): void
    {
        // Alice holds the form. Bob's browser leaves every disabled control
        // out of the data of the form the app posts.
        $this->locks->acquire(self::SCOPE, null, 'alice', 'Alice');
        $fields = ['address', 'furnished', 'balcony', 'kind', 'tags', 'internal'];

        $seen = $this->editorSees([
            'manifest' => self::manifest('form-lock', 'bob', $fields),
            'inputs' => ['address' => 'Rynok 1', 'furnished' => '1', 'balcony' => '1', 'kind' => 'flat', 'tags' => '', 'internal' => 'x'],
            'form' => true,
            'controls' => [
                'furnished' => ['type' => 'checkbox', 'checked' => true],
                'balcony' => ['type' => 'checkbox'],
                'kind' => ['tag' => 'select', 'options' => [['value' => 'house'], ['value' => 'flat', 'selected' => true]]],
                'tags' => ['tag' => 'select', 'multiple' => true, 'name' => 'tags[]', 'options' => [['value' => 'a', 'selected' => true], ['value' => 'b'], ['value' => 'c', 'selected' => true]]],
                'internal' => ['name' => null],
            ],
            'steps' => [['snapshot' => $this->frame($this->feedOf('form-lock', $fields))], ['formData' => true]],
        ]);

        self::assertSame(
            [['address', 'Rynok 1'], ['furnished', '1'], ['kind', 'flat'], ['tags[]', 'a'], ['tags[]', 'c']],
            $seen['formData'],
            'what the form shows: no unchecked box, every selected option, nothing without a name',
        );
        self::assertSame(array_fill_keys($fields, true), $seen['disabled'], 'nothing was unlocked to get there');
    }

    #[Test]
    public function a_locked_field_is_in_the_form_data_once_also_when_the_page_enabled_it_itself(): void
    {
        $this->locks->acquire(self::SCOPE, 'address', 'alice', 'Alice');
        $scenario = [
            'manifest' => self::manifest('field-lock', 'bob', ['title', 'address']),
            'inputs' => ['title' => 'Flat', 'address' => 'Rynok 1'],
            'form' => true,
            'steps' => [['snapshot' => $this->frame($this->feedOf('field-lock', ['title', 'address']))], ['formData' => true]],
        ];
        $seen = $this->editorSees($scenario);

        self::assertSame([['title', 'Flat'], ['address', 'Rynok 1']], $seen['formData']);
        self::assertSame(['title' => false, 'address' => true], $seen['disabled']);
        self::assertSame('Alice', $seen['fieldLocks']['address']);

        // An app that enables the inputs in its own submit listener.
        array_splice($scenario['steps'], 1, 0, [['enable' => 'address']]);
        self::assertSame([['title', 'Flat'], ['address', 'Rynok 1']], $this->editorSees($scenario)['formData']);
    }

    #[Test]
    public function two_locked_rows_with_the_same_name_and_value_are_both_in_the_form_data(): void
    {
        $this->locks->acquire(self::SCOPE, null, 'alice', 'Alice');

        $seen = $this->editorSees([
            'manifest' => self::manifest('form-lock', 'bob', ['q1', 'q2']),
            'inputs' => ['q1' => '1', 'q2' => '1'],
            'form' => true,
            'controls' => ['q1' => ['name' => 'qty[]'], 'q2' => ['name' => 'qty[]']],
            'steps' => [['snapshot' => $this->frame($this->feedOf('form-lock', ['q1', 'q2']))], ['formData' => true]],
        ]);

        self::assertSame([['qty[]', '1'], ['qty[]', '1']], $seen['formData'], 'two rows, not one: equal values are not duplicates');
    }

    #[Test]
    public function a_locked_row_keeps_its_place_among_the_rows_of_the_same_name(): void
    {
        $this->locks->acquire(self::SCOPE, 'a', 'alice', 'Alice');

        $seen = $this->editorSees([
            'manifest' => self::manifest('field-lock', 'bob', ['a', 'b', 'c']),
            'inputs' => ['a' => 'A', 'b' => 'B', 'c' => 'C'],
            'form' => true,
            'controls' => ['a' => ['name' => 'line[]'], 'b' => ['name' => 'line[]'], 'c' => ['name' => 'line[]']],
            'steps' => [['snapshot' => $this->frame($this->feedOf('field-lock', ['a', 'b', 'c']))], ['formData' => true]],
        ]);

        self::assertSame([['line[]', 'A'], ['line[]', 'B'], ['line[]', 'C']], $seen['formData'], 'document order, as the server indexes line[]');
        self::assertSame(['a' => true, 'b' => false, 'c' => false], $seen['disabled']);
    }

    #[Test]
    public function an_edit_with_no_answer_is_aborted_and_what_was_typed_after_it_goes_out(): void
    {
        $scenario = [
            'manifest' => self::manifest('shared', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [['type' => 'title', 'value' => 'a'], ['type' => 'title', 'value' => 'ab'], ['elapse' => 9000]],
        ];
        $waiting = $this->editorSees($scenario);
        self::assertSame([['field' => 'title', 'value' => 'a']], self::edits($waiting['posts']), 'not yet');
        self::assertSame(0, $waiting['aborted']);

        $scenario['steps'][] = ['elapse' => 1000];
        $seen = $this->editorSees($scenario);
        self::assertSame(1, $seen['aborted'], 'the stalled request is given up after 10 s');
        self::assertSame([['field' => 'title', 'value' => 'a'], ['field' => 'title', 'value' => 'ab']], self::edits($seen['posts']));
        self::assertSame(['title' => 1], $seen['maxInFlight']);
    }

    #[Test]
    public function a_stalled_edit_does_not_hold_the_field_lock_for_ever(): void
    {
        // Bob typed, left the field, and the request hangs. Without a bound
        // the release never goes out while the heartbeat keeps the lock.
        $steps = [['focus' => 'title'], ['type' => 'title', 'value' => 'a'], ['blur' => 'title']];
        $fieldLock = $this->editorSees([
            'manifest' => self::manifest('field-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [...$steps, ['elapse' => 30000]],
        ]);
        self::assertContains(['event' => 'lock.release', 'payload' => ['field' => 'title']], $fieldLock['posts']);
        self::assertSame(2, $fieldLock['aborted'], 'the edit and its one retry');

        // The same for a form removed from the page while its edit hangs.
        $formLock = $this->editorSees([
            'manifest' => self::manifest('form-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [['focus' => 'title'], ['type' => 'title', 'value' => 'a'], ['unmount' => true], ['elapse' => 30000]],
        ]);
        self::assertContains(['event' => 'lock.release', 'payload' => []], $formLock['posts']);
    }

    #[Test]
    public function a_page_hidden_after_blur_with_the_edit_in_flight_still_releases_the_field(): void
    {
        $seen = $this->editorSees([
            'manifest' => self::manifest('field-lock', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [['focus' => 'title'], ['type' => 'title', 'value' => 'a'], ['blur' => 'title'], ['pagehide' => true]],
        ]);

        // The edit in flight was sent without keepalive and dies with the
        // page: its value goes again, then the release that waited for it.
        self::assertSame(
            [
                ['event' => 'field.edit', 'payload' => ['field' => 'title', 'value' => 'a']],
                ['event' => 'lock.release', 'payload' => ['field' => 'title']],
            ],
            self::withoutPings($seen['keepalive']),
        );
    }

    #[Test]
    public function a_value_that_failed_twice_gets_one_more_try_before_its_lock_is_released(): void
    {
        // Two quick failures, then blur. The release used to go out at once,
        // the value was dropped, and the next frame put the old text back.
        $payload = $this->feedOf('field-lock', ['title']);
        $this->draft->mergeFields(self::SCOPE, ['title' => 'old'], 'alice');

        $seen = $this->editorSees([
            'manifest' => self::manifest('field-lock', 'bob', ['title']),
            'inputs' => ['title' => 'old'],
            'hold' => ['field.edit'],
            'steps' => [
                ['focus' => 'title'],
                ['type' => 'title', 'value' => 'new text'],
                ['fail' => 'oldest'],
                ['fail' => 'oldest'],
                ['blur' => 'title'],
                ['snapshot' => $this->frame($payload)],
                ['answer' => 'oldest'],
            ],
        ]);

        self::assertSame(
            [
                ['event' => 'field.edit', 'payload' => ['field' => 'title', 'value' => 'new text']],
                ['event' => 'lock.release', 'payload' => ['field' => 'title']],
            ],
            array_values(array_filter($seen['served'], static fn (array $p): bool => in_array($p['event'], ['field.edit', 'lock.release'], true))),
        );
        self::assertSame(['title' => 'new text'], $seen['values']);
    }

    #[Test]
    public function a_value_that_keeps_failing_is_dropped_after_three_heartbeats(): void
    {
        $payload = $this->feedOf('shared', ['title']);
        $this->draft->mergeFields(self::SCOPE, ['title' => 'from alice'], 'alice');
        $beat = [['tick' => true], ['fail' => 'oldest']];

        $seen = $this->editorSees([
            'manifest' => self::manifest('shared', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'hold' => ['field.edit'],
            'steps' => [
                ['type' => 'title', 'value' => 'mine'],
                ['fail' => 'oldest'],
                ['fail' => 'oldest'],
                ...$beat, ...$beat, ...$beat,
                ['tick' => true],
                ['tick' => true],
                ['snapshot' => $this->frame($payload)],
            ],
        ]);

        self::assertCount(5, self::edits($seen['posts']), 'twice at once, three heartbeats, and no more');
        self::assertSame(['title' => 'from alice'], $seen['values'], 'the field is open to the others again');
        self::assertSame([], self::withoutPings($seen['keepalive']), 'no edit asked for keepalive: its 64 KiB quota fails a long text every time');
    }

    #[Test]
    public function a_server_error_is_retried_and_a_refusal_is_not(): void
    {
        $scenario = [
            'manifest' => self::manifest('shared', 'bob', ['title']),
            'inputs' => ['title' => ''],
            'steps' => [['type' => 'title', 'value' => 'abc'], ['tick' => true]],
        ];

        $broke = $this->editorSees($scenario + ['replies' => ['field.edit' => [['http' => 503]]]]);
        self::assertSame(
            [['field' => 'title', 'value' => 'abc'], ['field' => 'title', 'value' => 'abc']],
            self::edits($broke['posts']),
            '5xx: the server does not have the value',
        );

        $refused = $this->editorSees($scenario + ['replies' => ['field.edit' => [['http' => 422, 'data' => ['status' => 'rejected', 'reason' => 'field_locked']]]]]);
        self::assertSame([['field' => 'title', 'value' => 'abc']], self::edits($refused['posts']), '4xx is an answer: not at once, not on the heartbeat');
    }

    /**
     * @param list<array{event: string, payload: array<string, mixed>}> $requests
     * @return list<array{event: string, payload: array<string, mixed>}>
     */
    private static function withoutPings(array $requests): array
    {
        return array_values(array_filter($requests, static fn (array $p): bool => $p['event'] !== 'presence.ping'));
    }

    /**
     * The field.edit payloads of a list of requests, in order.
     *
     * @param list<array{event: string, payload: array<string, mixed>}> $requests
     * @return list<array<string, mixed>>
     */
    private static function edits(array $requests): array
    {
        return array_column(array_filter($requests, static fn (array $p): bool => $p['event'] === 'field.edit'), 'payload');
    }

    /**
     * The form.save posts of a run.
     *
     * @param array{posts: list<array{event: string, payload: array<string, mixed>}>} $seen
     * @return list<array<string, mixed>>
     */
    private static function saves(array $seen): array
    {
        return array_column(array_filter($seen['posts'], static fn (array $p): bool => $p['event'] === 'form.save'), 'payload');
    }

    /**
     * What HUG answers a form.save with: the real handler, and the adapter the
     * dispatcher puts between it and the wire.
     *
     * @param array<string, mixed> $save
     * @return array<string, mixed>
     */
    private function answer(array $save): array
    {
        $response = $this->handler()->handle((object) [], $this->event('form.save', 'optimistic', $save, ['price']));
        try {
            $result = (new UiInteractionDispatchAdapter())->toInteractionResult($response, 'inst-1');
        } catch (UiInteractionUnprocessableException $e) {
            return ['status' => 'rejected', 'reason' => $e->reason, 'message' => $e->getMessage()] + ($e->details !== [] ? ['details' => $e->details] : []);
        }

        return ['status' => 'accepted', 'reason' => 'accepted', 'kind' => $result->kind, 'debug' => $result->debug];
    }

    /** @param list<string> $fields */
    private function feedOf(string $mode, array $fields): FormDocumentFeedPayload
    {
        $payload = new FormDocumentFeedPayload();
        $payload->setCtx(SignedContext::sign(['i' => 'bob-tab', 'cfg' => ['scope' => self::SCOPE, 'mode' => $mode, 'fields' => $fields]]));

        return $payload;
    }

    /**
     * The frame the feed pushes to a subscriber of `$payload` right now: the
     * re-run a scope touch triggers.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function frame(FormDocumentFeedPayload $payload): array
    {
        $handler = (new FormDocumentFeedHandler())->withProjector(
            (new FormDocumentProjector())->withStores($this->draft, $this->presence, $this->locks, $this->notices),
        );
        $response = (new \ReflectionMethod(FormDocumentFeedHandler::class, 'buildDocumentResponse'))
            ->invoke($handler, $payload, new JsonResourceResponse());
        self::assertInstanceOf(JsonResourceResponse::class, $response);
        $frame = json_decode($response->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($frame);

        /** @var array{data: array<string, mixed>, meta: array<string, mixed>} $frame */
        return $frame;
    }

    /**
     * @param array<string, mixed> $request
     * @param list<string> $fields
     */
    private function event(string $event, string $mode, array $request = [], array $fields = ['title']): UiEventContext
    {
        return new UiEventContext(
            eventId: 'evt',
            correlationId: 'cor',
            semanticEvent: $event,
            signedClaims: ['i' => 'mallory-tab', 'cfg' => ['scope' => self::SCOPE, 'mode' => $mode, 'fields' => $fields]],
            request: $request,
        );
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private static function manifest(string $mode, string $self, array $fields): array
    {
        $events = [];
        foreach (['field.edit', 'form.save', 'presence.ping', 'lock.acquire', 'lock.release', 'lock.heartbeat'] as $event) {
            $events[$event] = 'ctx-' . $event;
        }

        return [
            'v' => 1, 'i' => 'inst-1', 'scope' => self::SCOPE, 'mode' => $mode, 'self' => $self,
            'feed' => 'platform-ui.form-doc', 'feedCtx' => 'ctx-feed', 'events' => $events,
            'fields' => $fields, 'heartbeatMs' => 15000,
            // No save notice existed when the page was rendered.
            'saved' => '',
        ];
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array{values: array<string, string>, fieldLocks: array<string, ?string>, chips: list<array{text: string, attrs: array<string, string>, elements: int}>, lock: ?array{text: string, attrs: array<string, string>, elements: int}, conflict: array{text: string, hidden: bool, buttons: list<string>}, saveStatus: string, events: list<array{type: string, detail: mixed}>, posts: list<array{event: string, payload: array<string, mixed>}>, served: list<array{event: string, payload: array<string, mixed>}>, keepalive: list<array{event: string, payload: array<string, mixed>}>, aborted: int, maxInFlight: array<string, int>, disabled: array<string, bool>, formData: ?list<array{string, string}>, htmlSinks: list<mixed>}
     */
    private function editorSees(array $scenario): array
    {
        // No skip: node is part of the image the framework runs in, and a
        // skipped run would report a runtime nobody executed.
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

        /** @var array{values: array<string, string>, fieldLocks: array<string, ?string>, chips: list<array{text: string, attrs: array<string, string>, elements: int}>, lock: ?array{text: string, attrs: array<string, string>, elements: int}, conflict: array{text: string, hidden: bool, buttons: list<string>}, saveStatus: string, events: list<array{type: string, detail: mixed}>, posts: list<array{event: string, payload: array<string, mixed>}>, served: list<array{event: string, payload: array<string, mixed>}>, keepalive: list<array{event: string, payload: array<string, mixed>}>, aborted: int, maxInFlight: array<string, int>, disabled: array<string, bool>, formData: ?list<array{string, string}>, htmlSinks: list<mixed>} $seen */
        return $seen;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;
use Semitexa\PlatformUi\Application\Service\Submit\AllowAllUiDemoSubmissionAdminAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\ConfigurableUiDemoSubmissionAdminAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormDatabaseDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\UiDemoSubmissionAdminAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiDemoSubmissionAdminAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Domain\Exception\UiDemoSubmissionAdminAuthorizationException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionCursor;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;

/**
 * Drives the playground-side DemoSubmissionsAdminHandler end-to-end
 * against an in-memory repository + custom authorizer fakes. Skips
 * the Twig render — exercising the handler verifies the safety
 * contract (auth seam, safe projection, no leaked tokens/ctx); the
 * curl regression covers the actual template output.
 *
 * The handler class lives in the UiPlayground project module
 * (`Semitexa\Modules\UiPlayground\…`); this test reaches into the
 * project module's class to assert the safe-projection contract.
 * Pulling the handler into the package's test boundary mirrors the
 * way the playground module already depends on the package — there
 * is no parallel test infra to spin up.
 */
final class DemoSubmissionsAdminHandlerTest extends TestCase
{
    private const HANDLER_CLASS = 'Semitexa\\Modules\\UiPlayground\\Application\\Handler\\PayloadHandler\\DemoSubmissionsAdminHandler';
    private const RESPONSE_CLASS = 'Semitexa\\Modules\\UiPlayground\\Application\\Resource\\Response\\DemoSubmissionsAdminResponse';
    private const PAYLOAD_CLASS = 'Semitexa\\Modules\\UiPlayground\\Application\\Payload\\Request\\DemoSubmissionsAdminPayload';

    protected function setUp(): void
    {
        if (!class_exists(self::HANDLER_CLASS) || !class_exists(self::RESPONSE_CLASS) || !class_exists(self::PAYLOAD_CLASS)) {
            $this->markTestSkipped('UiPlayground project module not available in this test environment.');
        }
        UiDemoSubmissionAdminAuthorizer::reset();
        UiFormDatabaseDemoSubmissionRepository::reset();
    }

    protected function tearDown(): void
    {
        UiDemoSubmissionAdminAuthorizer::reset();
        UiFormDatabaseDemoSubmissionRepository::reset();
    }

    private function makeHandler(InMemoryUiFormDatabaseDemoSubmissionRepository $repo, ?UiDemoSubmissionAdminAuthorizerInterface $authorizer = null): object
    {
        $handler = new (self::HANDLER_CLASS)();
        $handler->withRepository($repo);
        $handler->withAuthorizer($authorizer ?? new AllowAllUiDemoSubmissionAdminAuthorizer());
        return $handler;
    }

    private function invokeHandler(object $handler): object
    {
        $payload = new (self::PAYLOAD_CLASS)();
        $resource = new (self::RESPONSE_CLASS)();
        return $handler->handle($payload, $resource);
    }

    private function renderContext(object $resource): array
    {
        // HtmlResponse keeps the render-context array on a protected
        // property — reach in with reflection to assert the shape
        // pushed via withSubmissions() / withDenied().
        $reflectionMethod = new \ReflectionMethod($resource, 'getRenderContext');
        $reflectionMethod->setAccessible(true);
        /** @var array<string, mixed> $ctx */
        $ctx = $reflectionMethod->invoke($resource);
        return $ctx;
    }

    private function makeRecord(string $id, int $submittedAt, array $values): UiFormDemoSubmissionRecord
    {
        return new UiFormDemoSubmissionRecord(
            id: $id,
            formInstanceId: 'uci_handler_unit',
            actionName: 'platform.demo.storeContactDb',
            submittedAt: $submittedAt,
            values: $values,
        );
    }

    #[Test]
    public function empty_repository_renders_empty_submission_list(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $handler = $this->makeHandler($repo);
        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);
        self::assertSame([], $ctx['submissions']);
        self::assertArrayNotHasKey('denied', $ctx);
        self::assertSame(200, $resource->getStatusCode());
    }

    #[Test]
    public function saved_submissions_render_in_newest_first_order(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord('uifs_a', 100, ['contact_name' => 'Ada', 'contact_message' => 'Hello.']));
        $repo->save($this->makeRecord('uifs_b', 300, ['contact_name' => 'Bea', 'contact_message' => 'Hi.']));
        $repo->save($this->makeRecord('uifs_c', 200, ['contact_name' => 'Cyn', 'contact_message' => 'Yo.']));

        $resource = $this->invokeHandler($this->makeHandler($repo));
        $rows = $this->renderContext($resource)['submissions'];
        self::assertCount(3, $rows);
        self::assertSame(['uifs_b', 'uifs_c', 'uifs_a'], array_column($rows, 'id'));
    }

    #[Test]
    public function view_model_projects_only_documented_keys(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord('uifs_keys', 1, [
            'contact_name'    => 'Ada',
            'contact_topic'   => 'Hello',
            'contact_message' => 'Welcome to the demo.',
        ]));
        $resource = $this->invokeHandler($this->makeHandler($repo));
        $rows = $this->renderContext($resource)['submissions'];
        self::assertSame(
            ['id', 'actionName', 'formInstanceId', 'submittedAt', 'contactName', 'contactTopic', 'contactMessagePreview', 'storedFieldCount'],
            array_keys($rows[0]),
        );
    }

    #[Test]
    public function view_model_truncates_long_message_preview_with_ellipsis(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $longMessage = str_repeat('a', 500);
        $repo->save($this->makeRecord('uifs_long', 1, [
            'contact_name'    => 'Ada',
            'contact_message' => $longMessage,
        ]));
        $resource = $this->invokeHandler($this->makeHandler($repo));
        $rows = $this->renderContext($resource)['submissions'];
        $preview = $rows[0]['contactMessagePreview'];
        self::assertStringEndsWith('…', $preview);
        // Length of preview is at most the configured limit + the ellipsis.
        self::assertLessThanOrEqual(
            161, // 160 + 1-char ellipsis
            mb_strlen($preview),
        );
    }

    #[Test]
    public function view_model_does_NOT_carry_raw_values_json(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord('uifs_no_raw', 1, [
            'contact_name'    => 'Ada',
            'contact_message' => 'msg',
        ]));
        $resource = $this->invokeHandler($this->makeHandler($repo));
        $rows = $this->renderContext($resource)['submissions'];
        self::assertArrayNotHasKey('values_json', $rows[0]);
        self::assertArrayNotHasKey('values',      $rows[0]);
    }

    #[Test]
    public function projection_keeps_html_unchanged_for_template_autoescape(): void
    {
        // Twig autoescapes at render time. The projection itself
        // does NOT escape — that's the template's job. Pin the
        // value passes through unchanged so the template can
        // produce the correct escaped output.
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord('uifs_html', 1, [
            'contact_name'    => '<script>alert(1)</script>',
            'contact_message' => 'safe message',
        ]));
        $resource = $this->invokeHandler($this->makeHandler($repo));
        $rows = $this->renderContext($resource)['submissions'];
        self::assertSame('<script>alert(1)</script>', $rows[0]['contactName']);
    }

    #[Test]
    public function denied_authorizer_returns_403_and_does_not_read_repository(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord('uifs_should_not_be_read', 1, ['contact_name' => 'Ada']));

        $deny = new class implements UiDemoSubmissionAdminAuthorizerInterface {
            public function authorize(): void
            {
                throw new UiDemoSubmissionAdminAuthorizationException(
                    'You are not allowed to view this listing.',
                    'role_required',
                );
            }
        };
        $handler = $this->makeHandler($repo, $deny);
        $resource = $this->invokeHandler($handler);
        self::assertSame(403, $resource->getStatusCode());
        $ctx = $this->renderContext($resource);
        self::assertTrue($ctx['denied']);
        self::assertSame('role_required', $ctx['denialReason']);
        self::assertSame('You are not allowed to view this listing.', $ctx['denialMessage']);
        self::assertArrayNotHasKey('submissions', $ctx);
    }

    #[Test]
    public function default_authorizer_falls_back_to_configurable_static_holder(): void
    {
        // No `withAuthorizer()` call → handler MUST resolve through
        // UiDemoSubmissionAdminAuthorizer::getActive() (lazy-default
        // to configurable deny-by-default mode). Pin the bridge.
        //
        // The deny posture is forced with an EXPLICIT falsey flag:
        // putenv-clearing cannot represent "unset" here because
        // Environment::getEnvValue() falls back to the project `.env`
        // (where the dev compose stack legitimately enables the flag).
        // The truly-unset posture is pinned in isolation by
        // ConfigurableUiDemoSubmissionAdminAuthorizerTest::unset_env_flag_denies.
        $this->setEnvFlag('0');
        try {
            $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
            $repo->save($this->makeRecord('uifs_bridge', 1, ['contact_name' => 'Ada']));
            $handler = new (self::HANDLER_CLASS)();
            $handler->withRepository($repo);
            // do NOT call withAuthorizer
            $resource = $this->invokeHandler($handler);
            self::assertSame(403, $resource->getStatusCode());
            $ctx = $this->renderContext($resource);
            self::assertTrue($ctx['denied']);
            self::assertSame('demo_admin_disabled', $ctx['denialReason']);
            self::assertArrayNotHasKey('submissions', $ctx);
        } finally {
            $this->restoreEnvFlag();
        }
    }

    // ----------------------------------------------------------------
    // ConfigurableUiDemoSubmissionAdminAuthorizer — env-gated mode
    // ----------------------------------------------------------------

    private ?string $previousFlag = null;

    private function setEnvFlag(?string $value): void
    {
        $current = getenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG);
        $this->previousFlag = $current === false ? null : $current;
        if ($value === null) {
            putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG);
        } else {
            putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG . '=' . $value);
        }
    }

    private function restoreEnvFlag(): void
    {
        if ($this->previousFlag === null) {
            putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG);
        } else {
            putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG . '=' . $this->previousFlag);
        }
        $this->previousFlag = null;
    }

    #[Test]
    public function protected_mode_with_falsey_env_flag_denies_and_does_not_read_repository(): void
    {
        // Explicit falsey value, not putenv-clear: see the bridge test
        // above for why "unset" is not representable in-process. The
        // subject here is the deny PATH (403 envelope + the repository
        // is never read), which any denied flag value exercises.
        $this->setEnvFlag('0');
        try {
            // The repository would throw if the handler tried to
            // call it on the deny path — same trick as the earlier
            // typed-deny test, applied here to the real env-gated
            // authorizer.
            $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
                public function recent(int $limit = 25): array
                {
                    throw new \LogicException('repository::recent MUST NOT be called on a denied request');
                }
            };
            $authorizer = new ConfigurableUiDemoSubmissionAdminAuthorizer();
            $handler = $this->makeHandler($repo, $authorizer);
            $resource = $this->invokeHandler($handler);

            self::assertSame(403, $resource->getStatusCode());
            $ctx = $this->renderContext($resource);
            self::assertTrue($ctx['denied']);
            self::assertSame('demo_admin_disabled', $ctx['denialReason']);
            self::assertSame(
                'Diagnostic listing access is disabled. An operator must enable it explicitly.',
                $ctx['denialMessage'],
            );
            self::assertArrayNotHasKey('submissions', $ctx);
        } finally {
            $this->restoreEnvFlag();
        }
    }

    #[Test]
    public function protected_mode_with_env_flag_enabled_allows_and_renders_rows(): void
    {
        $this->setEnvFlag('1');
        try {
            $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
            $repo->save($this->makeRecord('uifs_enabled', 1, ['contact_name' => 'Ada', 'contact_message' => 'Hi.']));
            $authorizer = new ConfigurableUiDemoSubmissionAdminAuthorizer();
            $resource = $this->invokeHandler($this->makeHandler($repo, $authorizer));

            self::assertSame(200, $resource->getStatusCode());
            $rows = $this->renderContext($resource)['submissions'];
            self::assertCount(1, $rows);
            self::assertSame('uifs_enabled', $rows[0]['id']);
        } finally {
            $this->restoreEnvFlag();
        }
    }

    #[Test]
    public function protected_mode_with_random_env_value_denies(): void
    {
        $this->setEnvFlag('maybe');
        try {
            $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
            $authorizer = new ConfigurableUiDemoSubmissionAdminAuthorizer();
            $resource = $this->invokeHandler($this->makeHandler($repo, $authorizer));
            self::assertSame(403, $resource->getStatusCode());
            self::assertSame('demo_admin_disabled', $this->renderContext($resource)['denialReason']);
        } finally {
            $this->restoreEnvFlag();
        }
    }

    // ----------------------------------------------------------------
    // Cursor pagination — handler-level coverage
    // ----------------------------------------------------------------

    /**
     * Build an id matching the cursor's `uifs_[a-f0-9]{16}` shape so
     * the handler's nextCursor can be encoded without tripping the
     * constructor regex.
     */
    private static function paginationId(int $seq): string
    {
        return 'uifs_' . str_pad(dechex($seq), 16, '0', STR_PAD_LEFT);
    }

    /**
     * Build a Request carrying ?cursor=… / ?limit=… query params.
     */
    private function makeRequest(array $query): Request
    {
        return new Request(
            method:  'GET',
            uri:     '/ui-playground/admin/demo-submissions',
            headers: [],
            query:   $query,
            post:    [],
            server:  [],
            cookies: [],
        );
    }

    #[Test]
    public function first_page_without_cursor_renders_pagination_state(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeRecord(self::paginationId($i), $i * 100, ['contact_name' => 'Ada']));
        }
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['limit' => '2']));

        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);

        self::assertSame(200, $resource->getStatusCode());
        self::assertCount(2, $ctx['submissions']);
        self::assertSame(2, $ctx['paginationLimit']);
        self::assertTrue($ctx['paginationHasMore']);
        self::assertNotNull($ctx['paginationNextCursor']);
        // Encoded cursor must be a URL-safe single token (the same
        // contract the cursor unit test pins).
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_\-]+\z/', $ctx['paginationNextCursor']);
    }

    #[Test]
    public function following_a_next_cursor_returns_the_next_page(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeRecord(self::paginationId($i), $i * 100, ['contact_name' => 'Ada']));
        }

        // First page
        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['limit' => '2']));
        $r1 = $this->invokeHandler($h1);
        $ctx1 = $this->renderContext($r1);
        self::assertSame(
            [self::paginationId(5), self::paginationId(4)],
            array_column($ctx1['submissions'], 'id'),
        );
        $next = $ctx1['paginationNextCursor'];
        self::assertNotNull($next);

        // Second page — follow the cursor
        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['limit' => '2', 'cursor' => $next]));
        $r2 = $this->invokeHandler($h2);
        $ctx2 = $this->renderContext($r2);
        self::assertSame(200, $r2->getStatusCode());
        self::assertSame(
            [self::paginationId(3), self::paginationId(2)],
            array_column($ctx2['submissions'], 'id'),
        );
        self::assertTrue($ctx2['paginationHasMore']);
        self::assertNotNull($ctx2['paginationNextCursor']);

        // Third page — should be the last
        $h3 = $this->makeHandler($repo);
        $h3->withRequest($this->makeRequest(['limit' => '2', 'cursor' => $ctx2['paginationNextCursor']]));
        $r3 = $this->invokeHandler($h3);
        $ctx3 = $this->renderContext($r3);
        self::assertSame([self::paginationId(1)], array_column($ctx3['submissions'], 'id'));
        self::assertFalse($ctx3['paginationHasMore']);
        self::assertNull($ctx3['paginationNextCursor']);
    }

    #[Test]
    public function last_page_hides_next_cursor(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 3; $i++) {
            $repo->save($this->makeRecord(self::paginationId($i), $i * 100, ['contact_name' => 'Ada']));
        }
        $handler = $this->makeHandler($repo);
        // Limit equals total → exactly one page, no more.
        $handler->withRequest($this->makeRequest(['limit' => '3']));
        $ctx = $this->renderContext($this->invokeHandler($handler));
        self::assertCount(3, $ctx['submissions']);
        self::assertFalse($ctx['paginationHasMore']);
        self::assertNull($ctx['paginationNextCursor']);
    }

    #[Test]
    public function limit_query_is_clamped_to_max(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord(self::paginationId(1), 1, ['contact_name' => 'Ada']));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['limit' => '999999']));
        $ctx = $this->renderContext($this->invokeHandler($handler));
        self::assertSame(
            UiFormDatabaseDemoSubmissionRepositoryInterface::MAX_RECENT_LIMIT,
            $ctx['paginationLimit'],
        );
    }

    #[Test]
    public function non_numeric_or_negative_limit_falls_back_to_default(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord(self::paginationId(1), 1, ['contact_name' => 'Ada']));

        // Strict definition of "fall back to default": anything that
        // does not parse as a non-negative integer. `'0'` is a digit
        // string that DOES parse — the [1, MAX] clamp then pulls it
        // up to `1`. Pinned separately below.
        foreach (['', '-5', 'banana', '1; DROP TABLE', '   '] as $bad) {
            $handler = $this->makeHandler($repo);
            $handler->withRequest($this->makeRequest(['limit' => $bad]));
            $ctx = $this->renderContext($this->invokeHandler($handler));
            self::assertSame(
                UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT,
                $ctx['paginationLimit'],
                "limit query '{$bad}' must fall back to the default",
            );
        }
    }

    #[Test]
    public function zero_limit_clamps_to_one(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord(self::paginationId(1), 1, ['contact_name' => 'Ada']));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['limit' => '0']));
        $ctx = $this->renderContext($this->invokeHandler($handler));
        self::assertSame(1, $ctx['paginationLimit']);
    }

    #[Test]
    public function malformed_cursor_returns_400_and_does_not_read_repository(): void
    {
        // Repository explodes if recent()/paginate() is called — the
        // 400-on-bad-cursor branch MUST short-circuit before any read.
        $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
            public function recent(int $limit = 25): array
            {
                throw new \LogicException('repository::recent MUST NOT be called on a malformed cursor');
            }
            public function paginate(
                ?UiFormDemoSubmissionCursor $cursor = null,
                int $limit = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT,
            ): \Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionPage {
                throw new \LogicException('repository::paginate MUST NOT be called on a malformed cursor');
            }
        };

        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['cursor' => 'NOT$A$VALID$CURSOR']));

        $resource = $this->invokeHandler($handler);
        self::assertSame(400, $resource->getStatusCode());
        $ctx = $this->renderContext($resource);
        self::assertTrue($ctx['badCursor']);
        self::assertSame('invalid_cursor', $ctx['badCursorReason']);
        self::assertSame('Pagination cursor is invalid.', $ctx['badCursorMessage']);
        // No submissions key — the response is in the bad-cursor branch.
        self::assertArrayNotHasKey('submissions', $ctx);
    }

    #[Test]
    public function denied_authorizer_wins_over_malformed_cursor(): void
    {
        // A denied caller must NEVER see a 400 — that would leak the
        // cursor decode oracle. Denial precedence is the security
        // invariant we pin here.
        $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
            public function recent(int $limit = 25): array
            {
                throw new \LogicException('repository::recent MUST NOT be called on a denied request');
            }
            public function paginate(
                ?UiFormDemoSubmissionCursor $cursor = null,
                int $limit = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT,
            ): \Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionPage {
                throw new \LogicException('repository::paginate MUST NOT be called on a denied request');
            }
        };
        $deny = new class implements UiDemoSubmissionAdminAuthorizerInterface {
            public function authorize(): void
            {
                throw new UiDemoSubmissionAdminAuthorizationException(
                    'You are not allowed to view this listing.',
                    'role_required',
                );
            }
        };
        $handler = $this->makeHandler($repo, $deny);
        // Cursor is intentionally malformed — should never be looked at.
        $handler->withRequest($this->makeRequest(['cursor' => 'NOT$A$VALID$CURSOR']));

        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);

        self::assertSame(403, $resource->getStatusCode());
        self::assertTrue($ctx['denied']);
        self::assertSame('role_required', $ctx['denialReason']);
        // The bad-cursor branch must NOT be the one we landed in.
        self::assertArrayNotHasKey('badCursor', $ctx);
        self::assertArrayNotHasKey('paginationLimit', $ctx);
    }

    #[Test]
    public function empty_cursor_query_is_treated_as_first_page(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeRecord(self::paginationId(1), 1, ['contact_name' => 'Ada']));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['cursor' => '']));

        $resource = $this->invokeHandler($handler);
        self::assertSame(200, $resource->getStatusCode());
        $ctx = $this->renderContext($resource);
        self::assertCount(1, $ctx['submissions']);
        self::assertArrayNotHasKey('badCursor', $ctx);
    }

    // ----------------------------------------------------------------
    // Search / filter — handler-level coverage
    // ----------------------------------------------------------------

    private function makeSearchableRecord(
        int $seq,
        int $submittedAt,
        string $contactName = 'Ada',
        string $contactTopic = '',
        string $contactMessage = '',
        string $actionName = 'platform.demo.storeContactDb',
    ): UiFormDemoSubmissionRecord {
        return new UiFormDemoSubmissionRecord(
            id:             self::paginationId($seq),
            formInstanceId: 'uci_handler_search',
            actionName:     $actionName,
            submittedAt:    $submittedAt,
            values:         [
                'contact_name'    => $contactName,
                'contact_topic'   => $contactTopic,
                'contact_message' => $contactMessage,
            ],
        );
    }

    #[Test]
    public function get_with_q_returns_filtered_rows(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeSearchableRecord(1, 100, contactName: 'Ada Lovelace'));
        $repo->save($this->makeSearchableRecord(2, 200, contactName: 'Bea Beatty'));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['q' => 'lovelace']));
        $ctx = $this->renderContext($this->invokeHandler($handler));

        self::assertCount(1, $ctx['submissions']);
        self::assertSame(self::paginationId(1), $ctx['submissions'][0]['id']);
        self::assertSame('lovelace', $ctx['filterQuery']);
        self::assertNull($ctx['filterAction']);
    }

    #[Test]
    public function get_with_action_returns_filtered_rows(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeSearchableRecord(1, 100, actionName: 'platform.demo.storeContactDb'));
        $repo->save($this->makeSearchableRecord(2, 200, actionName: 'platform.demo.storeContact'));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['action' => 'platform.demo.storeContactDb']));
        $ctx = $this->renderContext($this->invokeHandler($handler));

        self::assertCount(1, $ctx['submissions']);
        self::assertSame(self::paginationId(1), $ctx['submissions'][0]['id']);
        self::assertSame('platform.demo.storeContactDb', $ctx['filterAction']);
    }

    #[Test]
    public function next_cursor_carries_filter_fingerprint_under_search(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100, contactName: 'match'));
        }
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['q' => 'match', 'limit' => '2']));
        $ctx = $this->renderContext($this->invokeHandler($handler));

        self::assertTrue($ctx['paginationHasMore']);
        $encoded = $ctx['paginationNextCursor'];
        self::assertNotNull($encoded);
        $decoded = UiFormDemoSubmissionCursor::decode($encoded);
        self::assertNotNull($decoded->filterFingerprint);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{16}\z/', $decoded->filterFingerprint);
    }

    #[Test]
    public function following_cursor_under_same_filter_returns_next_filtered_page(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100, contactName: 'match'));
        }
        // Add unrelated rows that must not appear in the filtered listing.
        $repo->save($this->makeSearchableRecord(100, 9999, contactName: 'unrelated'));

        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['q' => 'match', 'limit' => '2']));
        $ctx1 = $this->renderContext($this->invokeHandler($h1));
        $cursor = $ctx1['paginationNextCursor'];

        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['q' => 'match', 'limit' => '2', 'cursor' => $cursor]));
        $r2 = $this->invokeHandler($h2);
        $ctx2 = $this->renderContext($r2);
        self::assertSame(200, $r2->getStatusCode());
        // Page 1 ids: 5,4. Page 2 ids: 3,2.
        self::assertSame(
            [self::paginationId(3), self::paginationId(2)],
            array_column($ctx2['submissions'], 'id'),
        );
        // No unrelated row leaks in.
        self::assertNotContains(self::paginationId(100), array_column($ctx2['submissions'], 'id'));
    }

    #[Test]
    public function cursor_from_different_filter_is_rejected_with_400(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100, contactName: 'match'));
        }

        // Generate a cursor under q=match.
        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['q' => 'match', 'limit' => '2']));
        $cursor = $this->renderContext($this->invokeHandler($h1))['paginationNextCursor'];
        self::assertNotNull($cursor);

        // Reuse it under a DIFFERENT filter (q=other) — must reject.
        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['q' => 'other', 'cursor' => $cursor]));
        $r2 = $this->invokeHandler($h2);
        $ctx = $this->renderContext($r2);
        self::assertSame(400, $r2->getStatusCode());
        self::assertTrue($ctx['badCursor']);
        self::assertSame('invalid_cursor', $ctx['badCursorReason']);
    }

    #[Test]
    public function cursor_from_filtered_query_is_rejected_against_unfiltered(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100, contactName: 'match'));
        }
        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['q' => 'match', 'limit' => '2']));
        $cursor = $this->renderContext($this->invokeHandler($h1))['paginationNextCursor'];

        // Reuse the filtered cursor with NO q — must reject (the
        // unfiltered fingerprint is null, the cursor's is not).
        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['cursor' => $cursor]));
        $r2 = $this->invokeHandler($h2);
        self::assertSame(400, $r2->getStatusCode());
        self::assertSame('invalid_cursor', $this->renderContext($r2)['badCursorReason']);
    }

    #[Test]
    public function unfiltered_cursor_is_accepted_for_unfiltered_request(): void
    {
        // Backwards-compatibility pin: an existing (v1) cursor
        // generated by the legacy unfiltered paginate() must still
        // work when used with no q / action.
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100));
        }
        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['limit' => '2']));
        $cursor = $this->renderContext($this->invokeHandler($h1))['paginationNextCursor'];
        self::assertNotNull($cursor);

        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['cursor' => $cursor, 'limit' => '2']));
        $r2 = $this->invokeHandler($h2);
        self::assertSame(200, $r2->getStatusCode());
        self::assertCount(2, $this->renderContext($r2)['submissions']);
    }

    #[Test]
    public function too_long_q_returns_safe_400(): void
    {
        // No repository read, no bad-input echo, fixed safe reason.
        $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
            public function recent(int $limit = 25): array
            {
                throw new \LogicException('repository::recent MUST NOT be called on invalid q');
            }
        };
        $handler = $this->makeHandler($repo);
        $tooLong = str_repeat('a', 101);
        $handler->withRequest($this->makeRequest(['q' => $tooLong]));

        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);
        self::assertSame(400, $resource->getStatusCode());
        self::assertTrue($ctx['badSearch']);
        self::assertSame('invalid_search_query', $ctx['badSearchReason']);
        self::assertSame('Search query is invalid.', $ctx['badSearchMessage']);
        self::assertArrayNotHasKey('submissions', $ctx);
        // No raw bad input echoed back.
        self::assertNull($ctx['filterQuery']);
    }

    #[Test]
    public function unknown_action_returns_safe_400(): void
    {
        $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
            public function recent(int $limit = 25): array
            {
                throw new \LogicException('repository MUST NOT be called on invalid action');
            }
        };
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['action' => 'platform.evil.dropTable']));

        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);
        self::assertSame(400, $resource->getStatusCode());
        self::assertTrue($ctx['badSearch']);
        self::assertSame('invalid_action_filter', $ctx['badSearchReason']);
        self::assertSame('Action filter is invalid.', $ctx['badSearchMessage']);
        // Bad action value MUST NOT leak through any render context.
        self::assertNull($ctx['filterAction']);
        self::assertSame('', $ctx['badSearchMessage'] === 'Action filter is invalid.' ? '' : $ctx['badSearchMessage']);
    }

    #[Test]
    public function denied_authorizer_wins_over_bad_search_input(): void
    {
        // Authorization runs FIRST — a denied caller never sees a
        // 400 for bad q / action and never gets a validation oracle.
        $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
            public function recent(int $limit = 25): array
            {
                throw new \LogicException('repository::recent MUST NOT be called on denied request');
            }
        };
        $deny = new class implements UiDemoSubmissionAdminAuthorizerInterface {
            public function authorize(): void
            {
                throw new UiDemoSubmissionAdminAuthorizationException(
                    'You are not allowed to view this listing.',
                    'role_required',
                );
            }
        };
        $handler = $this->makeHandler($repo, $deny);
        $handler->withRequest($this->makeRequest([
            'q'      => str_repeat('a', 200),
            'action' => 'platform.evil.dropTable',
            'cursor' => 'NOT$A$VALID$CURSOR',
        ]));

        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);
        self::assertSame(403, $resource->getStatusCode());
        self::assertTrue($ctx['denied']);
        self::assertSame('role_required', $ctx['denialReason']);
        // None of the input-validation branches were entered.
        self::assertArrayNotHasKey('badSearch', $ctx);
        self::assertArrayNotHasKey('badCursor', $ctx);
    }

    #[Test]
    public function xss_q_is_echoed_only_as_filter_state_for_template_to_escape(): void
    {
        // The handler passes the canonical q VERBATIM to the
        // template — Twig autoescape sanitises it at render time.
        // This pin protects the projection step from accidentally
        // pre-escaping (which would double-escape the visible
        // string) or stripping (which would silently drop input).
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeSearchableRecord(1, 100, contactName: 'Ada'));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['q' => '<script>alert(1)</script>']));

        $ctx = $this->renderContext($this->invokeHandler($handler));
        self::assertSame('<script>alert(1)</script>', $ctx['filterQuery']);
    }

    #[Test]
    public function search_view_model_still_carries_only_documented_fields(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeSearchableRecord(1, 100, contactName: 'Ada match'));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['q' => 'match']));
        $ctx = $this->renderContext($this->invokeHandler($handler));
        self::assertSame(
            ['id', 'actionName', 'formInstanceId', 'submittedAt', 'contactName', 'contactTopic', 'contactMessagePreview', 'storedFieldCount'],
            array_keys($ctx['submissions'][0]),
        );
        self::assertArrayNotHasKey('values',      $ctx['submissions'][0]);
        self::assertArrayNotHasKey('values_json', $ctx['submissions'][0]);
    }

    // ----------------------------------------------------------------
    // Sort-slice — server-owned allow-listed sort tokens.
    // ----------------------------------------------------------------

    #[Test]
    public function default_unsorted_request_pushes_default_sort_token_to_template(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeSearchableRecord(1, 100, contactName: 'Ada'));
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest([]));
        $ctx = $this->renderContext($this->invokeHandler($handler));
        self::assertSame('submittedAt_desc', $ctx['filterSort']);
    }

    #[Test]
    public function ascending_sort_changes_row_order(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeSearchableRecord(1, 100));
        $repo->save($this->makeSearchableRecord(2, 300));
        $repo->save($this->makeSearchableRecord(3, 200));

        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['sort' => 'submittedAt_asc']));
        $ctx = $this->renderContext($this->invokeHandler($handler));

        self::assertSame(
            [self::paginationId(1), self::paginationId(3), self::paginationId(2)],
            array_column($ctx['submissions'], 'id'),
        );
        self::assertSame('submittedAt_asc', $ctx['filterSort']);
    }

    #[Test]
    public function bad_sort_returns_400_invalid_sort_and_does_not_read_repository(): void
    {
        $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
            public function recent(int $limit = 25): array
            {
                throw new \LogicException('repository::recent MUST NOT be called on invalid sort');
            }
            public function paginate(
                ?UiFormDemoSubmissionCursor $cursor = null,
                int $limit = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT,
            ): \Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionPage {
                throw new \LogicException('repository::paginate MUST NOT be called on invalid sort');
            }
        };
        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['sort' => 'contactName_desc']));

        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);
        self::assertSame(400, $resource->getStatusCode());
        self::assertTrue($ctx['badSearch']);
        self::assertSame('invalid_sort', $ctx['badSearchReason']);
        self::assertSame('Sort option is invalid.', $ctx['badSearchMessage']);
        // No raw bad input echoed back.
        self::assertNull($ctx['filterSort']);
        self::assertArrayNotHasKey('submissions', $ctx);
    }

    #[Test]
    public function denied_authorizer_wins_over_bad_sort(): void
    {
        $repo = new class extends InMemoryUiFormDatabaseDemoSubmissionRepository {
            public function recent(int $limit = 25): array
            {
                throw new \LogicException('repository::recent MUST NOT be called on denied request');
            }
        };
        $deny = new class implements UiDemoSubmissionAdminAuthorizerInterface {
            public function authorize(): void
            {
                throw new UiDemoSubmissionAdminAuthorizationException(
                    'Diagnostic listing access is denied.',
                    'demo_admin_forbidden',
                );
            }
        };
        $handler = $this->makeHandler($repo, $deny);
        $handler->withRequest($this->makeRequest(['sort' => 'contactName_desc']));

        $resource = $this->invokeHandler($handler);
        $ctx = $this->renderContext($resource);
        self::assertSame(403, $resource->getStatusCode());
        self::assertTrue($ctx['denied']);
        self::assertSame('demo_admin_forbidden', $ctx['denialReason']);
        self::assertArrayNotHasKey('badSearch', $ctx);
    }

    #[Test]
    public function cursor_minted_under_one_sort_cannot_be_used_under_another(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100));
        }
        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['sort' => 'submittedAt_asc', 'limit' => '2']));
        $cursor = $this->renderContext($this->invokeHandler($h1))['paginationNextCursor'];
        self::assertNotNull($cursor);

        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['sort' => 'submittedAt_desc', 'limit' => '2', 'cursor' => $cursor]));
        $r2 = $this->invokeHandler($h2);
        $ctx = $this->renderContext($r2);
        self::assertSame(400, $r2->getStatusCode());
        self::assertTrue($ctx['badCursor']);
        self::assertSame('invalid_cursor', $ctx['badCursorReason']);
    }

    #[Test]
    public function cursor_minted_under_ascending_is_accepted_under_same_sort(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100));
        }
        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['sort' => 'submittedAt_asc', 'limit' => '2']));
        $cursor = $this->renderContext($this->invokeHandler($h1))['paginationNextCursor'];
        self::assertNotNull($cursor);

        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['sort' => 'submittedAt_asc', 'limit' => '2', 'cursor' => $cursor]));
        $r2 = $this->invokeHandler($h2);
        $ctx2 = $this->renderContext($r2);
        self::assertSame(200, $r2->getStatusCode());
        // Page 1 (asc) ids: 1, 2. Page 2: 3, 4.
        self::assertSame(
            [self::paginationId(3), self::paginationId(4)],
            array_column($ctx2['submissions'], 'id'),
        );
    }

    #[Test]
    public function sort_composes_with_query_filter(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->makeSearchableRecord(1, 300, contactName: 'match'));
        $repo->save($this->makeSearchableRecord(2, 100, contactName: 'match'));
        $repo->save($this->makeSearchableRecord(3, 200, contactName: 'match'));
        $repo->save($this->makeSearchableRecord(4, 50,  contactName: 'no'));

        $handler = $this->makeHandler($repo);
        $handler->withRequest($this->makeRequest(['q' => 'match', 'sort' => 'submittedAt_asc']));
        $ctx = $this->renderContext($this->invokeHandler($handler));
        self::assertSame(
            [self::paginationId(2), self::paginationId(3), self::paginationId(1)],
            array_column($ctx['submissions'], 'id'),
        );
        self::assertSame('match', $ctx['filterQuery']);
        self::assertSame('submittedAt_asc', $ctx['filterSort']);
    }

    #[Test]
    public function v1_cursor_without_fingerprint_still_works_for_default_sort(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->makeSearchableRecord($i, $i * 100));
        }
        $h1 = $this->makeHandler($repo);
        $h1->withRequest($this->makeRequest(['limit' => '2']));
        $cursor = $this->renderContext($this->invokeHandler($h1))['paginationNextCursor'];
        self::assertNotNull($cursor);

        $h2 = $this->makeHandler($repo);
        $h2->withRequest($this->makeRequest(['limit' => '2', 'cursor' => $cursor]));
        $r2 = $this->invokeHandler($h2);
        self::assertSame(200, $r2->getStatusCode());
        self::assertCount(2, $this->renderContext($r2)['submissions']);
    }
}

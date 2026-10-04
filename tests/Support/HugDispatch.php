<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Support;

use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiResponseDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiReplayStoreInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\Ssr\Application\Handler\PayloadHandler\HugEventHandler;
use Semitexa\Ssr\Application\Payload\Request\HugEventPayload;

/**
 * Sends one UI event through HUG — `POST /__semitexa_hug`, the single inbound
 * door — exactly as `event-runtime.js` does: the signed context, a per-attempt
 * event id and the payload wrapped in the canonical envelope, handled by the
 * real HugEventHandler and Platform UI's response dispatcher.
 *
 * Replaces the tests' former direct calls into the removed `/__ui/dispatch`
 * handler, so the pipeline under test is the one production runs.
 */
final class HugDispatch
{
    /** One store for every send, as one worker would hold: a repeated event id is a replay. */
    private UiReplayStoreInterface $replayStore;
    private ?UiInteractionAuthorizerInterface $authorizer = null;
    private ?UiFieldRuleRegistryInterface $ruleRegistry = null;

    public function __construct()
    {
        $this->replayStore = new InMemoryUiReplayStore();
    }

    public function withReplayStore(UiReplayStoreInterface $replayStore): self
    {
        $this->replayStore = $replayStore;
        return $this;
    }

    public function withAuthorizer(UiInteractionAuthorizerInterface $authorizer): self
    {
        $this->authorizer = $authorizer;
        return $this;
    }

    public function withRuleRegistry(UiFieldRuleRegistryInterface $ruleRegistry): self
    {
        $this->ruleRegistry = $ruleRegistry;
        return $this;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $ctx, string $eventId, array $payload): ResourceResponse
    {
        return $this->sendRaw(json_encode([
            'schemaVersion' => 1,
            'eventId' => $eventId,
            'correlationId' => 'corr_' . bin2hex(random_bytes(8)),
            'semanticEvent' => 'test.event',
            'signedContext' => $ctx,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => (object) $payload,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The body event-runtime used to post to the removed `/__ui/dispatch`
     * (`{ctx, dispatchId, payload}`), sent through HUG instead.
     */
    public function sendLegacy(string $legacyJson): ResourceResponse
    {
        $legacy = json_decode($legacyJson, true, 512, JSON_THROW_ON_ERROR);
        $legacy = is_array($legacy) ? $legacy : [];
        $payload = is_array($legacy['payload'] ?? null) ? $legacy['payload'] : [];
        return $this->send((string) ($legacy['ctx'] ?? ''), (string) ($legacy['dispatchId'] ?? ''), $payload);
    }

    public function sendRaw(string $body): ResourceResponse
    {
        $request = new Request(
            method: 'POST',
            uri: '/__semitexa_hug',
            headers: ['Content-Type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: $body,
            files: [],
        );

        $dispatcher = (new PlatformUiResponseDispatcher())->withReplayStore($this->replayStore);
        if ($this->authorizer !== null) {
            $dispatcher->withAuthorizer($this->authorizer);
        }
        if ($this->ruleRegistry !== null) {
            $dispatcher->withRuleRegistry($this->ruleRegistry);
        }

        try {
            return (new HugEventHandler())
                ->withRequest($request)
                ->withDispatcher($dispatcher)
                ->handle(new HugEventPayload(), new ResourceResponse());
        } catch (ValidationException $rejected) {
            // HUG refuses a tampered signed context or a smuggled field before
            // any dispatcher runs; the pipeline's ExceptionMapper answers 422.
            return (new ResourceResponse())
                ->setStatusCode($rejected->getStatusCode()->value)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->setContent(json_encode([
                    'error' => 'validation',
                    'reason' => 'hug_rejected',
                    'context' => $rejected->getErrorContext(),
                ], JSON_THROW_ON_ERROR));
        }
    }

    /** @return array<string, mixed> */
    public static function json(ResourceResponse $response): array
    {
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}

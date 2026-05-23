<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Typed read-only context handed to a
 * {@see \Semitexa\PlatformUi\Contract\UiEventHandlerInterface} when the
 * Framework Layer dispatches a verified UI event (technical-design.md §7.6.2
 * + §12.9).
 *
 * The event identity (`eventId`, `correlationId`, `semanticEvent`) mirrors
 * the canonical `UiEventEnvelope` in `semitexa/ssr` so handlers can correlate
 * the synchronous response with later SSE updates (the `correlationId` join).
 *
 * `signedClaims` is the FW-verified claim map extracted from the envelope's
 * signed context. The Framework Layer guarantees the blob was decoded,
 * signature-checked, and TTL-validated before this object reaches the
 * handler — the handler reads it as plain data, never re-verifies.
 *
 * `request` carries the originating request snapshot. The dual `object|array`
 * shape matches {@see \Semitexa\Ssr\Domain\Model\DataProviderContext} so the
 * same primitives flow through sync and deferred render paths.
 */
final readonly class UiEventContext
{
    /**
     * @param array<string, mixed>             $signedClaims
     * @param object|array<string, mixed>|null $request
     */
    public function __construct(
        public string $eventId,
        public string $correlationId,
        public string $semanticEvent,
        public array $signedClaims = [],
        public object|array|null $request = null,
    ) {}
}

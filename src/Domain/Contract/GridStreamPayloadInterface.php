<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\Core\Request;

/**
 * The minimal payload contract the held-open grid-stream feed handler reads.
 *
 * A `#[GridFeed]` grid's request DTO carries the held-open stream's transport
 * metadata (the live HTTP request for content-negotiation, the adopted stream
 * id, and the view-change params for a re-hydrate command). Implemented by a
 * grid's app-side payload (e.g. the leads grid's `LeadGridStreamPayload`) so
 * the generic {@see \Semitexa\PlatformUi\Application\Handler\AbstractGridStreamFeedHandler}
 * can drive the held-open serve + the one-URL re-hydrate intake without
 * knowing the concrete grid.
 *
 * The interface is deliberately narrow: filters/paging/sort live as the
 * payload's own typed `#[LiveFilterParam]` fields (read only by the grid's
 * own envelope resolver), never reached through this contract — only the
 * transport coordinates leak across the seam, and `streamId` is NEVER an
 * overridable filter (the anti-poisoning invariant).
 */
interface GridStreamPayloadInterface
{
    /**
     * The live request the framework hands the payload during hydration. The
     * feed handler reads ONLY transport metadata from it (the `Accept` header
     * for content-negotiation, the `X-Semitexa-Stream-Rehydrate` intent
     * header) — never a filter.
     */
    public function getHttpRequest(): ?Request;

    /**
     * The adopted server-minted stream id the POST re-hydrate command carries
     * in its body to address the open stream. Null on the GET connect (the
     * server mints + announces its own id; the client adopts it).
     */
    public function getStreamId(): ?string;

    /**
     * The flat view-change params for the re-hydrate intake, forwarded
     * verbatim to {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::submitViewChange()},
     * which applies ONLY the keys the stream DTO marks `#[LiveFilterParam]`
     * (so the stream coordinate / identity is un-overridable by construction).
     *
     * @return array<string, mixed>
     */
    public function toViewParams(): array;
}

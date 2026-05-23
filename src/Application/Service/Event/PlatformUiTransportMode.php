<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Canonical platform-UI SSE transport mode.
 *
 * String values match the framework's
 * {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::TRANSPORT_MODE_DRAIN}
 * /
 * {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::TRANSPORT_MODE_LIVE}
 * constants AND the `mode=…` query parameter on `/__semitexa_kiss`,
 * so the meta tag, the wire URL, and the server-side resolver share
 * one alphabet.
 *
 * Drain is the hard default for public/guest pages: the runtime opens
 * the KISS stream only after a canonical UI event reports that
 * patches were published, and the server flushes the queue + emits
 * `event: close` so the connection terminates deterministically.
 *
 * Live is reserved for explicitly trusted surfaces — authenticated
 * dashboards, admin tools, internal monitoring — where the page
 * deliberately holds the stream open for the lifetime of the view.
 */
enum PlatformUiTransportMode: string
{
    case Drain = 'drain';
    case Live  = 'live';

    /**
     * Hard public/guest default. Centralised so the policy, the Twig
     * helper, and the test fixtures all agree on a single value.
     */
    public static function default(): self
    {
        return self::Drain;
    }
}

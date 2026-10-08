<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Link;

/**
 * Keeps a link target that came in as data (a page block's props, CMS
 * content) from becoming script: escaping stops an attribute break-out, not
 * `javascript:alert(1)` in a perfectly escaped href.
 *
 * Allowed: a same-site path or relative reference, a fragment, a query, and
 * the http, https, mailto and tel schemes. Anything else — another scheme,
 * a protocol-relative `//host`, control characters — returns ''.
 */
final class UiSafeHref
{
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function filter(mixed $href): string
    {
        if (!is_string($href) && !is_int($href)) {
            return '';
        }
        $href = trim((string) $href);
        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return '';
        }
        if (preg_match('#\A[/\\\\]{2}#', $href) === 1) {
            return '';
        }
        // A scheme is what comes before the first ':' — unless a '/', '?' or
        // '#' comes first, which makes the ':' part of a path, query or fragment.
        if (preg_match('/\A([^:\/?#]*):/', $href, $m) === 1) {
            return in_array(strtolower($m[1]), self::SCHEMES, true) ? $href : '';
        }

        return $href;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Workbench;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;

/**
 * Who may open the UI Workbench.
 *
 * It renders every catalog entry with fixture data and names the PHP class and
 * file behind each one — a development surface, not a public page. Open under
 * APP_ENV=dev, or anywhere PLATFORM_UI_WORKBENCH=1 says so explicitly (a
 * showcase deployment). A page on another site can never read it: the app
 * answers with a permissive CORS header, so a cross-site read is refused.
 */
#[AsService]
final class WorkbenchGate
{
    public const ENV_FLAG = 'PLATFORM_UI_WORKBENCH';

    public function allows(): bool
    {
        $enabled = Environment::create()->isDev()
            || Environment::getEnvValue(self::ENV_FLAG) === '1';

        return $enabled && !$this->isCrossSiteRead(CurrentRequestStore::get());
    }

    /**
     * Same rule as semitexa/dev's ObservatoryPanelGate (tk-core-cross-site-read
     * hoists it into core): a top-level navigation is fine — the other site
     * cannot read what it opens — but a request whose response another site
     * could read is refused.
     */
    private function isCrossSiteRead(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }
        $mode = strtolower(trim((string) $request->getHeader('Sec-Fetch-Mode')));
        $dest = strtolower(trim((string) $request->getHeader('Sec-Fetch-Dest')));
        if ($mode === 'navigate' && $dest === 'document' && strtoupper($request->getMethod()) === 'GET') {
            return false;
        }

        $site = strtolower(trim((string) $request->getHeader('Sec-Fetch-Site')));
        if ($site === 'cross-site' || $site === 'same-site') {
            return true;
        }

        $origin = trim((string) $request->getHeader('Origin'));
        if ($origin === '') {
            return false;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return true;
        }
        $originHost = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $host = strtolower(trim((string) $request->getHeader('Host')));

        return $host === '' || ($originHost !== $host && strtolower($parts['host']) !== $host);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Workbench;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Http\CrossSiteRead;
use Semitexa\Core\Http\ThisMachine;
use Semitexa\Core\Lifecycle\CurrentRequestStore;

/**
 * Who may open the UI Workbench.
 *
 * It renders every catalog entry with fixture data and names the PHP class and
 * file behind each one — a development surface, not a public page. Open under
 * APP_ENV=dev to this machine only (ThisMachine: the dev stack publishes its
 * port on every interface, so "dev" alone opened it to the whole Wi-Fi), or
 * to anyone where PLATFORM_UI_WORKBENCH=1 says so explicitly (a showcase
 * deployment). A page on another site can never read it: the app answers
 * with a permissive CORS header, so a cross-site read is refused.
 */
#[AsService]
final class WorkbenchGate
{
    public const ENV_FLAG = 'PLATFORM_UI_WORKBENCH';

    /** Per worker: the environment does not change under a running worker. */
    private static ?bool $dev = null;
    private static ?bool $showcase = null;

    public function allows(): bool
    {
        self::$showcase ??= Environment::getEnvValue(self::ENV_FLAG) === '1';
        $request = CurrentRequestStore::get();
        $open = self::$showcase || (self::isDev() && ThisMachine::sent($request));

        return $open && !CrossSiteRead::isCrossSiteRead($request);
    }

    /**
     * Class names and file paths are for a developer's machine. A showcase
     * deployment (the flag without APP_ENV=dev) shows the components, not
     * where they live.
     */
    public function showsSource(): bool
    {
        return self::isDev();
    }

    private static function isDev(): bool
    {
        return self::$dev ??= Environment::create()->isDev();
    }

}

<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Workbench;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Http\CrossSiteRead;
use Semitexa\Core\Lifecycle\CurrentRequestStore;

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

    /** Per worker: the environment does not change under a running worker. */
    private static ?bool $dev = null;
    private static ?bool $enabled = null;

    public function allows(): bool
    {
        self::$enabled ??= self::isDev() || Environment::getEnvValue(self::ENV_FLAG) === '1';

        return self::$enabled && !CrossSiteRead::isCrossSiteRead(CurrentRequestStore::get());
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

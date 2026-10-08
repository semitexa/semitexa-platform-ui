<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Icons;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\PlatformUi\Application\Service\Icon\LucideIconSetWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Regenerates the vendored Lucide set (resources/icons/lucide/) from a
 * `lucide-static` release — downloaded from the npm registry, or an unpacked
 * package given with --from. Run it in the platform-ui package checkout when
 * upgrading the icon set; the result is committed with the package.
 */
#[AsCommand(
    name: 'platform-ui:icons:sync',
    description: 'Regenerate the vendored Lucide icon set from a lucide-static release.',
)]
final class IconsSyncCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'lucide-static version to download', 'latest')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'An unpacked lucide-static package directory instead of downloading');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->getOption('from');
        $cleanup = null;
        if (!is_string($from) || $from === '') {
            [$from, $cleanup] = $this->download((string) $input->getOption('release'));
        }
        try {
            $target = dirname(__DIR__, 5) . '/resources/icons/lucide';
            $result = (new LucideIconSetWriter())->write($from, $target);
            $output->writeln(sprintf('<info>Lucide %s: %d icons, %d aliases → %s</info>', $result['version'], $result['icons'], $result['aliases'], $target));

            return Command::SUCCESS;
        } finally {
            if ($cleanup !== null) {
                $cleanup();
            }
        }
    }

    /** @return array{0: string, 1: \Closure(): void} the package dir and how to remove it */
    private function download(string $version): array
    {
        $meta = json_decode((string) @file_get_contents('https://registry.npmjs.org/lucide-static/' . rawurlencode($version)), true);
        $tarball = is_array($meta) ? ($meta['dist']['tarball'] ?? null) : null;
        if (!is_string($tarball) || !str_starts_with($tarball, 'https://registry.npmjs.org/')) {
            throw new \RuntimeException(sprintf('lucide-static %s was not found on the npm registry.', $version));
        }
        $dir = sys_get_temp_dir() . '/lucide-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $tgz = $dir . '/package.tgz';
        file_put_contents($tgz, (string) file_get_contents($tarball));
        (new \PharData($tgz))->extractTo($dir);

        return [$dir . '/package', static function () use ($dir): void {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }];
    }
}

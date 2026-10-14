<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Skins;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Theme\Application\Service\Skin\SkinContrastAudit;
use Semitexa\Theme\Discovery\SkinDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The contrast table of every skin (or one): WCAG 2 ratio and APCA Lc for each
 * pair the components draw, per mode. Fails when a gating pair is under AA, so
 * a skin generated or refined by hand can be checked before it ships. The
 * framework's own skins are held to it by ShippedSkinContrastTest.
 */
#[AsCommand(
    name: 'skins:contrast',
    description: 'Report the WCAG/APCA contrast table of every skin (or one); fails when a gating pair is under AA.',
)]
final class ContrastSkinCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Skin slug. Omit to check every discovered skin.')
            ->addOption('failures', null, InputOption::VALUE_NONE, 'Print only the pairs that fail')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $discovery = new SkinDiscovery(ProjectRoot::get());
        $slug = $input->getArgument('slug');
        $slugs = is_string($slug) && $slug !== '' ? [$slug] : $discovery->availableSlugs();
        sort($slugs);

        $report = [];
        $failed = 0;
        foreach ($slugs as $one) {
            $entry = $discovery->find($one);
            $manifest = $entry === null ? null : dirname($entry->tokensFilePath) . '/skin.json';
            if ($manifest === null || !is_file($manifest)) {
                // Nothing to measure is not a pass.
                $report[] = ['skin' => $one, 'error' => $entry === null ? 'not found' : 'no skin.json next to tokens.css', 'pairs' => []];
                $failed++;
                continue;
            }
            $skin = json_decode((string) file_get_contents($manifest), true);
            $tokens = is_array($skin) && is_array($skin['tokens'] ?? null) ? $skin['tokens'] : [];
            $rows = SkinContrastAudit::audit($tokens);
            $failures = count(SkinContrastAudit::failures($rows));
            $failed += $failures > 0 ? 1 : 0;
            $report[] = ['skin' => $one, 'failures' => $failures, 'pairs' => $rows];
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['artifact' => 'semitexa.platform-ui.skin-contrast/v1', 'clean' => $failed === 0, 'skins' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $onlyFailures = (bool) $input->getOption('failures');
        foreach ($report as $skin) {
            if (isset($skin['error'])) {
                $output->writeln(sprintf('<error>%s: %s</error>', $skin['skin'], $skin['error']));
                continue;
            }
            $output->writeln(sprintf('<info>%s</info>%s', $skin['skin'], $skin['failures'] > 0 ? sprintf(' <error>%d under AA</error>', $skin['failures']) : ''));
            foreach ($skin['pairs'] as $row) {
                if ($onlyFailures && $row['pass']) {
                    continue;
                }
                $output->writeln(sprintf(
                    '  %-5s %-20s on %-20s %6s  Lc %6s  needs %.1f  %s',
                    $row['mode'],
                    substr($row['foreground'], 5),
                    substr($row['background'], 5),
                    $row['ratio'] === null ? 'n/a' : number_format($row['ratio'], 2),
                    $row['apca'] === null ? 'n/a' : number_format($row['apca'], 1),
                    $row['required'],
                    $row['pass'] ? 'ok' : ($row['gating'] ? 'FAIL' : 'advisory'),
                ));
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}

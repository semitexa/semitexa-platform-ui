<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Skins;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Theme\Discovery\SkinDiscovery;
use Semitexa\Theme\Application\Service\Skin\SkinManifest;
use Semitexa\Theme\Application\Service\Skin\TokenEmitter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-emit `tokens.css` for an existing skin from its `skin.json`.
 *
 * `skin.json` is the canonical artifact; `tokens.css` is a derived output of
 * the same TokenEmitter that runs for `skins:generate`. This command is the
 * one path that touches every kind of skin — algorithm-generated,
 * LLM-prompted, or hand-curated `algorithm: "manual"`. There is no
 * alternative writer; if the file isn't produced here, it isn't canonical.
 *
 * Behaviour:
 *  - Locates `<slug>` via SkinDiscovery (project src/skins → framework default).
 *  - Loads `skin.json`; refuses anything that isn't `schema_version: "3.0"`.
 *  - Validates that `tokens.light` and `tokens.dark` cover the full
 *    TokenContract surface (DualSkinPalette enforces this on construction).
 *  - Writes back to `<skinDir>/tokens.css` (or stdout for dry-run).
 *  - Idempotent: running twice produces byte-identical output.
 */
#[AsCommand(
    name: 'skins:rebuild',
    description: 'Re-emit tokens.css for a skin from its skin.json (canonical path; works for every skin source).',
)]
final class RebuildSkinCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Skin slug to rebuild. Omit to rebuild every project-local skin.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Rebuild every project-local skin (alias for omitting <slug>)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Persist regenerated tokens.css. Default: dry-run preview to stdout.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        $all = (bool) $input->getOption('all') || ($slug === null || $slug === '');
        $write = (bool) $input->getOption('write');
        $asJson = (bool) $input->getOption('json');

        $discovery = new SkinDiscovery(ProjectRoot::get());

        if ($all) {
            return $this->rebuildAll($output, $discovery, $write, $asJson);
        }

        return $this->rebuildOne($output, $discovery, (string) $slug, $write, $asJson);
    }

    private function rebuildOne(OutputInterface $output, SkinDiscovery $discovery, string $slug, bool $write, bool $asJson): int
    {
        $entry = $discovery->find($slug);
        if ($entry === null) {
            return $this->fail($output, $asJson, "Skin '{$slug}' not found. Available: " . implode(', ', $discovery->availableSlugs()));
        }
        $skinDir = dirname($entry->tokensFilePath);
        $manifestPath = $skinDir . '/skin.json';
        if (!is_file($manifestPath)) {
            return $this->fail($output, $asJson, "Skin '{$slug}' has no skin.json at {$manifestPath}. Cannot rebuild without a manifest.");
        }

        try {
            $manifest = SkinManifest::fromJson((string) file_get_contents($manifestPath));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $asJson, "Cannot rebuild '{$slug}': " . $e->getMessage());
        }

        $css = (new TokenEmitter())->emit($manifest->tokens, $manifest->emitterContext());

        if (!$write) {
            if ($asJson) {
                $output->writeln((string) json_encode([
                    'artifact' => 'semitexa.skins-base.skin-rebuild/v1',
                    'status' => 'dry-run',
                    'slug' => $slug,
                    'manifest' => $manifestPath,
                    'bytes' => strlen($css),
                ], JSON_PRETTY_PRINT));
            } else {
                $output->writeln("<comment>Dry-run rebuild of '{$slug}'. Pass --write to persist.</comment>");
                $output->writeln('');
                $output->writeln($css);
            }
            return Command::SUCCESS;
        }

        $tokensPath = $skinDir . '/tokens.css';
        if (file_put_contents($tokensPath, $css) === false) {
            return $this->fail($output, $asJson, "Failed to write {$tokensPath}");
        }

        if ($asJson) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.skins-base.skin-rebuild/v1',
                'status' => 'written',
                'slug' => $slug,
                'tokens_css' => $tokensPath,
                'bytes' => strlen($css),
            ], JSON_PRETTY_PRINT));
        } else {
            $output->writeln("<info>Rebuilt '{$slug}'</info> → {$tokensPath} (" . strlen($css) . ' bytes)');
        }
        return Command::SUCCESS;
    }

    private function rebuildAll(OutputInterface $output, SkinDiscovery $discovery, bool $write, bool $asJson): int
    {
        $slugs = $discovery->availableSlugs();
        if ($slugs === []) {
            if ($asJson) {
                $output->writeln((string) json_encode(['artifact' => 'semitexa.skins-base.skin-rebuild/v1', 'status' => 'noop', 'slugs' => []], JSON_PRETTY_PRINT));
            } else {
                $output->writeln('<comment>No skins discovered.</comment>');
            }
            return Command::SUCCESS;
        }

        $results = [];
        $failures = 0;
        foreach ($slugs as $slug) {
            $entry = $discovery->find($slug);
            if ($entry === null || $entry->source !== 'project') {
                $results[$slug] = ['status' => 'skipped', 'reason' => 'not project-local'];
                continue;
            }
            $skinDir = dirname($entry->tokensFilePath);
            $manifestPath = $skinDir . '/skin.json';
            if (!is_file($manifestPath)) {
                $results[$slug] = ['status' => 'skipped', 'reason' => 'no skin.json'];
                continue;
            }
            try {
                $manifest = SkinManifest::fromJson((string) file_get_contents($manifestPath));
                $css = (new TokenEmitter())->emit($manifest->tokens, $manifest->emitterContext());
                if ($write) {
                    file_put_contents($skinDir . '/tokens.css', $css);
                    $results[$slug] = ['status' => 'written', 'bytes' => strlen($css)];
                } else {
                    $results[$slug] = ['status' => 'dry-run', 'bytes' => strlen($css)];
                }
            } catch (\Throwable $e) {
                $results[$slug] = ['status' => 'error', 'error' => $e->getMessage()];
                $failures++;
            }
        }

        if ($asJson) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.skins-base.skin-rebuild/v1',
                'status' => $failures === 0 ? ($write ? 'written' : 'dry-run') : 'partial',
                'failures' => $failures,
                'results' => $results,
            ], JSON_PRETTY_PRINT));
        } else {
            foreach ($results as $slug => $r) {
                $verb = match ($r['status']) {
                    'written' => "<info>rebuilt</info>",
                    'dry-run' => "<comment>dry-run</comment>",
                    'skipped' => "<comment>skipped</comment>",
                    'error'   => "<error>error</error>",
                };
                $extra = isset($r['bytes']) ? " ({$r['bytes']} bytes)" : (isset($r['reason']) ? "  — {$r['reason']}" : (isset($r['error']) ? "  — {$r['error']}" : ''));
                $output->writeln("  {$verb}  {$slug}{$extra}");
            }
            if (!$write && $failures === 0) {
                $output->writeln('');
                $output->writeln('<comment>Dry-run. Pass --write to persist.</comment>');
            }
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function fail(OutputInterface $output, bool $asJson, string $message): int
    {
        if ($asJson) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.skins-base.skin-rebuild/v1',
                'status' => 'error',
                'error' => $message,
            ], JSON_PRETTY_PRINT));
        } else {
            $output->writeln("<error>{$message}</error>");
        }
        return Command::FAILURE;
    }
}

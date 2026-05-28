<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Css;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'platform-ui:css:explain',
    description: 'Explain what a slice-id or primitive produces — CSS, layer, semantic token references.',
)]
final class ExplainCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Slice id like "sx-gap:4" OR primitive id like "button"')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        $catalog = SliceCatalog::withDefaults();
        $primitives = new PrimitiveRegistry();

        if (!str_contains($id, ':')) {
            $primitive = $primitives->get($id);
            if ($primitive === null) {
                $output->writeln("<error>Unknown primitive '{$id}'. Available: " . implode(', ', $primitives->ids()) . '</error>');
                return Command::FAILURE;
            }

            $css = is_file($primitive->cssPath) ? (string) file_get_contents($primitive->cssPath) : '';
            $tokens = $this->extractTokens($css);

            if ((bool) $input->getOption('json')) {
                $output->writeln(json_encode([
                    'artifact' => 'semitexa.platform-ui.css-explain/v1',
                    'kind' => 'primitive',
                    'id' => $primitive->id,
                    'layer' => 'platform-ui.primitives',
                    'variants' => $primitive->variants,
                    'tones' => $primitive->tones,
                    'sizes' => $primitive->sizes,
                    'states' => $primitive->states,
                    'tokens_referenced' => $tokens,
                    'bytes' => strlen($css),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }

            $output->writeln("<info>Primitive</info> ui=\"{$primitive->id}\"  (@layer platform-ui.primitives)");
            $output->writeln('');
            if ($primitive->variants !== []) $output->writeln('  variants: ' . implode(', ', $primitive->variants));
            if ($primitive->tones !== [])    $output->writeln('  tones:    ' . implode(', ', $primitive->tones));
            if ($primitive->sizes !== [])    $output->writeln('  sizes:    ' . implode(', ', $primitive->sizes));
            if ($primitive->states !== [])   $output->writeln('  states:   ' . implode(', ', $primitive->states));
            $output->writeln('');
            $output->writeln('<comment>Semantic tokens referenced:</comment> ' . implode(', ', $tokens));
            $output->writeln('<comment>CSS bytes:</comment> ' . strlen($css));
            return Command::SUCCESS;
        }

        [$attribute, $value] = explode(':', $id, 2);
        $emitter = $catalog->emitter($attribute);
        if ($emitter === null) {
            $output->writeln("<error>Unknown attribute '{$attribute}'. Known: " . implode(', ', $catalog->attributes()) . '</error>');
            return Command::FAILURE;
        }
        if (!in_array($value, $emitter->allowedValues(), true)) {
            $output->writeln("<error>Invalid value '{$value}' for '{$attribute}'. Allowed: " . implode(', ', $emitter->allowedValues()) . '</error>');
            return Command::FAILURE;
        }

        $slice = $emitter->emit($value);
        $tokens = $this->extractTokens($slice->css);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.platform-ui.css-explain/v1',
                'kind' => 'grammar',
                'slice_id' => $slice->id,
                'attribute' => $attribute,
                'value' => $value,
                'layer' => 'platform-ui.grammar',
                'css' => $slice->css,
                'tokens_referenced' => $tokens,
                'sibling_values' => array_values(array_diff($emitter->allowedValues(), [$value])),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln("<info>Grammar slice</info> {$slice->id}  (@layer platform-ui.grammar)");
        $output->writeln('');
        $output->writeln($slice->css);
        if ($tokens !== []) {
            $output->writeln('');
            $output->writeln('<comment>Semantic tokens referenced:</comment> ' . implode(', ', $tokens));
        }
        $siblings = array_diff($emitter->allowedValues(), [$value]);
        if ($siblings !== []) {
            $output->writeln('<comment>Other values for ' . $attribute . ':</comment> ' . implode(', ', $siblings));
        }
        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function extractTokens(string $css): array
    {
        preg_match_all('/var\((--ui-[a-z0-9-]+)\)/', $css, $m);
        return array_values(array_unique($m[1] ?? []));
    }
}

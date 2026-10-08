<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Field;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldTypes;
use Semitexa\PlatformUi\Attribute\AsFieldType;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the field types (built-in and #[AsFieldType]): what each holds, the
 * control that edits it, the grid column it shows and how it filters.
 * `--json` is the same list for tools and agents.
 */
#[AsCommand(
    name: 'platform-ui:field-types',
    description: 'List the field types: control, column, filter and rules of each.',
)]
final class FieldTypesCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $discovery = new ClassDiscovery();
        $discovery->initialize();
        UiFieldTypes::discover($discovery->findClassesWithAttribute(AsFieldType::class));

        $rows = [];
        foreach (UiFieldTypes::all() as $name => $type) {
            $sample = new UiField('value', $name);
            $form = $type->formProps($sample);
            $filter = $type->filter($sample);
            $rows[] = [
                'name' => $name,
                'description' => $type->description(),
                'control' => (string) ($form['control'] ?? 'input') . (isset($form['inputProps']['type']) ? ':' . $form['inputProps']['type'] : ''),
                'column' => $type->column($sample)['format'],
                'filter' => $filter === null ? null : $filter['operators'],
                'rules' => array_map(static fn (string|array $r): string => is_array($r) ? (string) $r[0] : $r, $type->rules($sample)),
                'class' => $type::class,
            ];
        }

        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Type', 'Control', 'Column', 'Filter', 'Rules', 'Description']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['name'],
                $row['control'],
                $row['column'],
                $row['filter'] === null ? '—' : implode(' ', $row['filter']),
                $row['rules'] === [] ? '—' : implode(' ', $row['rules']),
                $row['description'],
            ]);
        }
        $table->render();
        $output->writeln(sprintf('%d field types. Declare one with Field::<type>(\'name\'); add your own with #[AsFieldType].', count($rows)));

        return Command::SUCCESS;
    }
}

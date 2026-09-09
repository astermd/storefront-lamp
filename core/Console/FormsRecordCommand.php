<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Forms\TeleformGateway;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `forms:record --teleform=<id> [--out=<dir>]` — writes what the EMR
 * currently serves for one teleform to disk, as the metadata record and the
 * definition document.
 *
 * It reads through the gateway rather than through
 * {@see \AsterMD\Storefront\Forms\TeleformSource}, deliberately bypassing the
 * definition cache: a recording that could be answered from cache would
 * capture what the storefront happens to be holding rather than what the EMR
 * is serving, which is the opposite of what anyone runs this for. It is here
 * so that re-recording a republished form, or capturing an envelope shape
 * nobody anticipated, is one command instead of a hand-written fixture.
 *
 * The metadata written is the reduced record the storefront actually keeps
 * — id, identifier, type, layout and the `db_fields` mapping — not the raw
 * API payload, since the reduction is the part every later reader consumes.
 *
 * A thin wrapper over the services `[9.1]`. A teleform that cannot be
 * resolved is a failure exit `[9.2]`, and nothing is written in that case:
 * a half-written recording read later as a fixture is worse than no
 * recording.
 */
#[AsCommand(name: 'forms:record', description: 'Capture a teleform metadata record and definition to disk')]
final class FormsRecordCommand extends Command
{
    public function __construct(
        private readonly TeleformGateway $gateway,
        private readonly string $defaultOutputDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('teleform', null, InputOption::VALUE_REQUIRED, 'The teleform id to record');
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Directory to write the two files into');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $teleformId = trim((string) $input->getOption('teleform'));
        if ($teleformId === '') {
            $output->writeln('<error>A teleform id is required: --teleform=ID</error>');

            return Command::FAILURE;
        }

        $metadata = $this->gateway->metadata($teleformId);
        if ($metadata === null) {
            $output->writeln(sprintf('<error>No teleform metadata for "%s".</error>', $teleformId));

            return Command::FAILURE;
        }

        $definition = $this->gateway->definition($metadata);
        if ($definition === null) {
            $output->writeln(sprintf('<error>No definition for teleform "%s".</error>', $teleformId));

            return Command::FAILURE;
        }

        $outputDir = trim((string) ($input->getOption('out') ?? '')) ?: $this->defaultOutputDir;
        if (!is_dir($outputDir) && !mkdir($outputDir, 0o770, true) && !is_dir($outputDir)) {
            $output->writeln(sprintf('<error>Unable to create output directory: %s</error>', $outputDir));

            return Command::FAILURE;
        }

        $pages = array_values(array_filter((array) ($definition['pages'] ?? []), 'is_array'));
        $fields = 0;
        foreach ($pages as $page) {
            $fields += count(array_filter((array) ($page['fields'] ?? []), 'is_array'));
        }

        $metadataFile = $outputDir . '/teleform-metadata.json';
        $definitionFile = $outputDir . '/teleform-definition.json';

        file_put_contents($metadataFile, self::encode([
            'id' => $metadata->id,
            'form_json_identifier' => $metadata->identifier,
            'type' => $metadata->type,
            'layout' => $metadata->layout,
            'db_fields' => $metadata->dbFields,
        ]));
        file_put_contents($definitionFile, self::encode($definition));

        $output->writeln(sprintf('Identifier: %s', $metadata->identifier));
        $output->writeln(sprintf('Form: %s', is_string($definition['formName'] ?? null) ? $definition['formName'] : '(unnamed)'));
        $output->writeln(sprintf('Type: %s', $metadata->type));
        $output->writeln(sprintf('Layout: %s', $metadata->layout));
        $output->writeln(sprintf('Pages: %d', count($pages)));
        $output->writeln(sprintf('Fields: %d', $fields));
        $output->writeln(sprintf('db_fields: %d', count($metadata->dbFields)));
        $output->writeln(sprintf('Wrote %s', $metadataFile));
        $output->writeln(sprintf('Wrote %s', $definitionFile));

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

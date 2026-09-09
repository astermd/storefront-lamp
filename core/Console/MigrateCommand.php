<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Database\ConnectionFactory;
use AsterMD\Storefront\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `db:migrate` — thin CLI wrapper around {@see Migrator}. Builds its own PDO
 * connection (rather than reusing a shared one) since this command may be
 * the very thing that creates the sqlite file in the first place.
 */
#[AsCommand(name: 'db:migrate', description: 'Create the database (if needed) and apply pending migrations')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly string $migrationsDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $migrator = new Migrator($this->connections->create(), $this->connections->driver(), $this->migrationsDir);
            $applied = $migrator->migrate();
        } catch (\Throwable $e) {
            $output->writeln('<error>Migration failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($applied === []) {
            $output->writeln('Nothing to migrate.');
        } else {
            foreach ($applied as $name) {
                $output->writeln('Applied: ' . $name);
            }
        }

        return Command::SUCCESS;
    }
}

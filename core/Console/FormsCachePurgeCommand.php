<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Forms\DefinitionCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `forms:cache-purge` — empties the teleform definition cache.
 *
 * Routine operation never needs this: the cache is keyed on the teleform's
 * `form_json_identifier`, which carries the form's version and publish time,
 * so a republished form is already a miss `[3.12]`. It exists for the case
 * the key cannot see — the storefront's own reading of a definition changed
 * (a corrected parser, a widened field vocabulary) and every cached copy
 * should be re-read even though no form was touched.
 *
 * A thin wrapper over the service `[9.1]`, and an empty cache is a success
 * rather than a failure `[9.2]`: there was nothing stale left to remove.
 */
#[AsCommand(name: 'forms:cache-purge', description: 'Remove every cached teleform definition')]
final class FormsCachePurgeCommand extends Command
{
    public function __construct(private readonly DefinitionCache $cache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->cache->purge();
        $output->writeln(sprintf('Teleform cache purged (%d definitions removed).', $removed));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Catalog\MediaLocalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `media:prune` — reports (and, with `--force`, deletes) files under
 * `public/assets/media/` that {@see MediaLocalizer::findOrphans()} no longer
 * associates with any current catalog product slug — i.e. the product that
 * owned those files has left the catalog entirely (removed, or re-synced
 * under a new slug/id). A re-hashed stale file left behind by a product that
 * is still live is NOT an orphan by this definition and is intentionally
 * kept: {@see MediaLocalizer::localize()}'s failure-fallback ladder falls
 * back to a product's newest already-downloaded local file when a re-download
 * fails, so that stale file must survive until either a successful
 * re-download replaces it or the product itself disappears. Defaults to a
 * dry run so running it accidentally never deletes anything [3.14].
 */
#[AsCommand(name: 'media:prune', description: 'List (or, with --force, delete) orphaned files in public/assets/media')]
final class MediaPruneCommand extends Command
{
    public function __construct(
        private readonly string $mediaDir,
        private readonly CatalogProvider $catalogProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Delete orphaned files instead of just listing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slugs = array_map(static fn (array $product): string => (string) $product['slug'], $this->catalogProvider->products());
        $orphans = MediaLocalizer::findOrphans($this->mediaDir, $slugs);

        if ($orphans === []) {
            $output->writeln('No orphaned media.');

            return Command::SUCCESS;
        }

        $force = (bool) $input->getOption('force');

        foreach ($orphans as $basename) {
            $output->writeln($basename);

            if ($force) {
                @unlink($this->mediaDir . '/' . $basename);
            }
        }

        if (!$force) {
            $output->writeln('(dry run — use --force to delete)');
        }

        return Command::SUCCESS;
    }
}

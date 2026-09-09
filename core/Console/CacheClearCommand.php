<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cache:clear` — empties the storage cache directory (Twig's compiled
 * template cache lives there). A missing directory is treated as an already
 * cleared cache rather than an error, since a fresh checkout won't have one
 * yet.
 */
#[AsCommand(name: 'cache:clear', description: 'Wipe the storage cache directory contents')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly string $cacheDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
                throw new \RuntimeException(sprintf('Unable to create cache directory: %s', $this->cacheDir));
            }
            $output->writeln('Cache cleared (0 entries removed).');

            return Command::SUCCESS;
        }

        $removed = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            $removed++;
        }
        $output->writeln(sprintf('Cache cleared (%d entries removed).', $removed));

        return Command::SUCCESS;
    }
}

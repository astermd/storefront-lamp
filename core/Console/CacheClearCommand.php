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
 * template cache lives there, and so does `storage/cache/teleforms`, which is
 * what a stale questionnaire is usually being served from).
 *
 * It refuses to report a success it did not have. The count used to rise once
 * per entry *walked* while both removals were `@`-suppressed, so a cache
 * directory owned by the web-server user and cleared by a shell user printed
 * "Cache cleared (12 entries removed)" and removed nothing — leaving an
 * operator to rule out the cache and go looking somewhere else, while the
 * stale definition it was meant to drop went on being served. A missing
 * directory is treated as an already
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
        $survived = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();

            // Still suppressed, because the warning text says less than the
            // path does and this reports both outcomes itself. What changed is
            // that the answer is now read: the count used to be incremented
            // once per entry *walked*, so a directory nothing could delete
            // reported every file in it as removed.
            if ($item->isDir() ? @rmdir($path) : @unlink($path)) {
                $removed++;

                continue;
            }

            // A directory may survive only because something inside it did.
            // Naming the file is what an operator can act on; naming the
            // directory as well would bury it in consequences of itself.
            if (!$item->isDir()) {
                $survived[] = $path;
            }
        }

        $output->writeln(sprintf('Cache cleared (%d %s removed).', $removed, $removed === 1 ? 'entry' : 'entries'));

        if ($survived === []) {
            return Command::SUCCESS;
        }

        // Failure, not a warning. This command is reached for while debugging
        // the very problem a stale cache causes, so a clear that did not clear
        // has to be impossible to mistake for one that did -- and `[20.6]`'s
        // question is which failure, not whether there was one. Usually it is
        // ownership: the cache was written by the web-server user and this is
        // a shell user, and deleting a file needs write permission on the
        // directory holding it rather than on the file.
        $output->writeln('');
        $output->writeln(sprintf('ERROR: %d cache entr%s could not be removed:', count($survived), count($survived) === 1 ? 'y' : 'ies'));
        foreach ($survived as $path) {
            $owner = @fileowner($path);
            $name = is_int($owner) && function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? null) : null;
            $output->writeln(sprintf('  %s%s', $path, $name === null ? '' : ' (owned by ' . $name . ')'));
        }
        $output->writeln('');
        $output->writeln('The cache was NOT fully cleared. Whatever it was holding is still being served.');

        return Command::FAILURE;
    }
}

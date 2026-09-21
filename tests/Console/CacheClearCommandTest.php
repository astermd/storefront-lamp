<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\CacheClearCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cache:clear` is the command an operator reaches for while debugging exactly
 * the kind of problem a stale cache causes, so the one thing it must never do
 * is report a success it did not have.
 *
 * It did. The count was incremented for every entry *walked* rather than every
 * entry removed, and both removals were `@`-suppressed, so a cache directory
 * written by the web-server user and cleared by a shell user printed
 * "Cache cleared (12 entries removed)" and removed nothing — after which the
 * stale definition it was supposed to drop went on being served, and the
 * command had ruled itself out as the cause.
 */
final class CacheClearCommandTest extends TestCase
{
    /** @var list<string> directories made read-only by a case, restored so the temp tree can be cleaned up */
    private array $restoreWritable = [];

    protected function tearDown(): void
    {
        foreach ($this->restoreWritable as $dir) {
            @chmod($dir, 0770);
        }
        $this->restoreWritable = [];
    }

    public function testClearsMissingDirectoryReturnsZeroAndCreatesIt(): void
    {
        $cacheDir = sys_get_temp_dir() . '/cache-' . uniqid();
        self::assertFalse(is_dir($cacheDir));

        $command = new CacheClearCommand($cacheDir);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 entries', $tester->getDisplay());
        self::assertTrue(is_dir($cacheDir));
    }

    public function testClearsPopulatedDirectoryRemovesContentsKeepsDirectory(): void
    {
        $cacheDir = sys_get_temp_dir() . '/cache-populated-' . uniqid();
        mkdir($cacheDir, 0770, true);
        file_put_contents($cacheDir . '/test.cache', 'content');
        mkdir($cacheDir . '/subdir', 0770);
        file_put_contents($cacheDir . '/subdir/nested.cache', 'nested');

        $command = new CacheClearCommand($cacheDir);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertTrue(is_dir($cacheDir));
        self::assertFalse(is_file($cacheDir . '/test.cache'));
        self::assertFalse(is_file($cacheDir . '/subdir/nested.cache'));
        self::assertFalse(is_dir($cacheDir . '/subdir'));
    }

    /**
     * A file the process cannot unlink is reported, named, and fails the
     * command. Removing the write bit from the *directory* is what stops the
     * unlink: permission to delete a file is a property of its parent, not of
     * the file, which is exactly how this happens in the field — the cache
     * directory belongs to the web-server user.
     */
    public function testAFileItCannotRemoveIsNamedAndFailsTheCommand(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can unlink regardless of directory permissions, so there is nothing to refuse');
        }

        $cacheDir = sys_get_temp_dir() . '/cache-locked-' . uniqid();
        mkdir($cacheDir . '/locked', 0770, true);
        file_put_contents($cacheDir . '/locked/stuck.cache', 'content');
        chmod($cacheDir . '/locked', 0500);
        $this->restoreWritable[] = $cacheDir . '/locked';

        $tester = new CommandTester(new CacheClearCommand($cacheDir));

        self::assertSame(1, $tester->execute([]), 'a clear that cleared nothing must not exit 0');
        self::assertStringContainsString('stuck.cache', $tester->getDisplay(), 'the operator is told which entry survived');
        self::assertTrue(is_file($cacheDir . '/locked/stuck.cache'), 'the case did not actually lock anything');
    }

    /** The count is of entries removed, not entries seen. */
    public function testTheCountReportsRemovalsRatherThanEntriesWalked(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can unlink regardless of directory permissions');
        }

        $cacheDir = sys_get_temp_dir() . '/cache-partial-' . uniqid();
        mkdir($cacheDir . '/locked', 0770, true);
        file_put_contents($cacheDir . '/gone.cache', 'content');
        file_put_contents($cacheDir . '/locked/stuck.cache', 'content');
        chmod($cacheDir . '/locked', 0500);
        $this->restoreWritable[] = $cacheDir . '/locked';

        $tester = new CommandTester(new CacheClearCommand($cacheDir));
        $tester->execute([]);

        // One file went; the locked file and the directory holding it did not.
        self::assertStringContainsString('1 entry removed', $tester->getDisplay());
        self::assertFalse(is_file($cacheDir . '/gone.cache'));
    }

    /** The ordinary case still says what it did, and still succeeds. */
    public function testACleanSweepCountsWhatItRemovedAndSucceeds(): void
    {
        $cacheDir = sys_get_temp_dir() . '/cache-clean-' . uniqid();
        mkdir($cacheDir . '/subdir', 0770, true);
        file_put_contents($cacheDir . '/a.cache', 'a');
        file_put_contents($cacheDir . '/subdir/b.cache', 'b');

        $tester = new CommandTester(new CacheClearCommand($cacheDir));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('3 entries removed', $tester->getDisplay(), 'two files and the directory');
    }
}

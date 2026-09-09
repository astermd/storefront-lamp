<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Retention;

use AsterMD\Storefront\Retention\LogDirectoryUnreadable;
use AsterMD\Storefront\Retention\LogSweep;
use PHPUnit\Framework\TestCase;

/**
 * `[30.6]` names debug logs alongside sessions and events, and `[30.9]` says
 * deletion has to reach the copies that are easy to forget. The wire log is
 * exactly such a copy.
 */
final class LogSweepTest extends TestCase
{
    private const int NOW = 1_787_011_200;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/storefront-logs-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        // A test that locks the directory to reach the unreadable branch would
        // otherwise leave its temp directory behind, because glob, unlink and
        // rmdir all need what it took away.
        @chmod($this->dir, 0775);

        foreach ((array) glob($this->dir . '/*') as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($this->dir);
    }

    public function testAFileLastWrittenBeforeTheCutoffIsTaken(): void
    {
        $this->seed('emr-wire-2026-01-01.log', self::NOW - 90 * 86400);

        self::assertSame(1, $this->sweep()->expire($this->cutoff(30)));
        self::assertFileDoesNotExist($this->dir . '/emr-wire-2026-01-01.log');
    }

    public function testAFileStillInsideItsPeriodIsKept(): void
    {
        $this->seed('app.log', self::NOW - 86400);

        self::assertSame(0, $this->sweep()->expire($this->cutoff(30)));
        self::assertFileExists($this->dir . '/app.log');
    }

    public function testCountingNeverDeletes(): void
    {
        $this->seed('emr-wire-2026-01-01.log', self::NOW - 90 * 86400);

        self::assertSame(1, $this->sweep()->countExpirable($this->cutoff(30)));
        self::assertFileExists($this->dir . '/emr-wire-2026-01-01.log');
    }

    /**
     * A deployment that has never written a log, and one whose log directory
     * was removed by an operator, both have nothing to expire. Neither is a
     * failure a scheduler should be told about.
     */
    public function testAMissingDirectoryIsNotAFailure(): void
    {
        $sweep = new LogSweep($this->dir . '/nowhere');

        self::assertSame(0, $sweep->countExpirable($this->cutoff(30)));
        self::assertSame(0, $sweep->expire($this->cutoff(30)));
    }

    /**
     * `.gitignore` is what keeps this directory in the repository at all, and
     * a sweep that deleted it would make the next checkout's storage layout
     * depend on whether the pruner had run.
     */
    public function testADotfileIsNeverTaken(): void
    {
        $this->seed('.gitignore', self::NOW - 90 * 86400);

        self::assertSame(0, $this->sweep()->expire($this->cutoff(30)));
        self::assertFileExists($this->dir . '/.gitignore');
    }

    /**
     * A directory that is there but cannot be listed is a failure, not a zero.
     *
     * The two cases look identical from outside and are opposite in meaning.
     * A *missing* directory is a deployment that has never written a log:
     * there is provably nothing to expire, and the test above says so. A
     * directory that exists and cannot be opened is a sweep that never
     * happened, and answering zero for it is indistinguishable from "nothing
     * was old enough" — while what actually survives is the file `[30.9]`
     * names, holding verbatim card numbers and Social Security Numbers with
     * nothing else scheduled to remove it.
     *
     * `is_dir()` cannot tell them apart: it needs execute permission on the
     * *parent*, not read permission on the directory itself, so a log
     * directory whose mode or ownership drifted after a deployment is
     * simultaneously "there" and unlistable.
     */
    public function testADirectoryThatExistsButCannotBeListedIsAFailureRatherThanAZero(): void
    {
        $this->seed('emr-wire-2026-01-01.log', self::NOW - 90 * 86400);
        $this->lockDirectory();

        $this->expectException(LogDirectoryUnreadable::class);

        $this->sweep()->countExpirable($this->cutoff(30));
    }

    /** The delete refuses on the same terms the count does, so a dry run and an apply agree. */
    public function testTheDeleteAlsoRefusesAnUnlistableDirectory(): void
    {
        $this->seed('emr-wire-2026-01-01.log', self::NOW - 90 * 86400);
        $this->lockDirectory();

        $this->expectException(LogDirectoryUnreadable::class);

        $this->sweep()->expire($this->cutoff(30));
    }

    /**
     * Takes read permission off the log directory while leaving it a
     * directory, which is the state `is_dir()` cannot see.
     *
     * Skipped rather than asserted when the mode does not bite — a process
     * running as root ignores it, and a filesystem without POSIX modes has
     * nothing to take away. Both would make this test pass for the wrong
     * reason, which is worse than not running it.
     */
    private function lockDirectory(): void
    {
        chmod($this->dir, 0100);

        if (@scandir($this->dir) !== false) {
            self::markTestSkipped('This process can list a directory it has no read permission on.');
        }
    }

    public function testASubdirectoryIsNeverTaken(): void
    {
        mkdir($this->dir . '/archive');

        self::assertSame(0, $this->sweep()->expire($this->cutoff(30)));
        self::assertDirectoryExists($this->dir . '/archive');
    }

    private function sweep(): LogSweep
    {
        return new LogSweep($this->dir);
    }

    private function cutoff(int $days): string
    {
        return gmdate('c', self::NOW - $days * 86400);
    }

    private function seed(string $name, int $modifiedAt): void
    {
        file_put_contents($this->dir . '/' . $name, 'line');
        touch($this->dir . '/' . $name, $modifiedAt);
    }
}

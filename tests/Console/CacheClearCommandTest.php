<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\CacheClearCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheClearCommandTest extends TestCase
{
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
}

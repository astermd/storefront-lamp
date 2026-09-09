<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\MigrateCommand;
use AsterMD\Storefront\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateCommandTest extends TestCase
{
    public function testMigratesFreshSqliteDatabase(): void
    {
        $root = sys_get_temp_dir() . '/console-' . uniqid();
        mkdir($root . '/storage/database', 0770, true);
        $factory = new ConnectionFactory(['driver' => 'sqlite', 'database' => 'storage/database/app.sqlite'], $root);
        $command = new MigrateCommand($factory, dirname(__DIR__, 2) . '/database/migrations');

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0001_initial_schema', $tester->getDisplay());

        // Second run: nothing to do, still exit 0.
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Nothing to migrate', $tester->getDisplay());
    }
}

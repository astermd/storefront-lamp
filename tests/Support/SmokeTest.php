<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testAutoloaderAndPhpVersion(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, '8.4.0', '>='));
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/core');
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfg-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/app.php', '<?php return ["name" => "Test", "database" => ["driver" => $env["DB_DRIVER"] ?? "sqlite"]];');
        file_put_contents($this->dir . '/shop.extra.php', '<?php return ["someKey" => "someValue"];');
    }

    public function testLoadsFilesKeyedByBasename(): void
    {
        $config = Config::load($this->dir);
        self::assertSame('Test', $config->get('app.name'));
    }

    public function testDotNotationWithDefault(): void
    {
        $config = Config::load($this->dir);
        self::assertSame('sqlite', $config->get('app.database.driver'));
        self::assertSame('fallback', $config->get('app.missing.key', 'fallback'));
    }

    public function testEnvArrayReachesConfigFiles(): void
    {
        $config = Config::load($this->dir, ['DB_DRIVER' => 'pgsql']);
        self::assertSame('pgsql', $config->get('app.database.driver'));
    }

    public function testDotNotationResolvesDottedFileKeysViaGreedyPrefixMatch(): void
    {
        $config = Config::load($this->dir);

        self::assertSame('someValue', $config->get('shop.extra.someKey'));
        self::assertSame('x', $config->get('shop.extra.missing', 'x'));
        self::assertSame('Test', $config->get('app.name'));
    }
}

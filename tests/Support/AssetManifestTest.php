<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\AssetManifest;
use PHPUnit\Framework\TestCase;

final class AssetManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/am-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testManifestHitReturnsHashedPath(): void
    {
        file_put_contents($this->dir . '/manifest.json', json_encode(['app.css' => 'app.abc12345.css']));
        $manifest = new AssetManifest($this->dir . '/manifest.json');

        self::assertSame('/assets/build/app.abc12345.css', $manifest->path('app.css'));
    }

    public function testManifestMissEntryFallsBackToLogicalName(): void
    {
        file_put_contents($this->dir . '/manifest.json', json_encode(['app.css' => 'app.abc12345.css']));
        $manifest = new AssetManifest($this->dir . '/manifest.json');

        self::assertSame('/assets/build/missing.js', $manifest->path('missing.js'));
    }

    public function testMissingManifestFileIsTolerated(): void
    {
        $manifest = new AssetManifest($this->dir . '/nope.json');

        self::assertSame('/assets/build/x.css', $manifest->path('x.css'));
    }
}

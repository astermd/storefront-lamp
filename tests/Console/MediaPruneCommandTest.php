<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Console\MediaPruneCommand;
use AsterMD\Storefront\Support\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MediaPruneCommandTest extends TestCase
{
    private string $mediaDir;
    private string $configDir;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir() . '/media-prune-' . uniqid();
        $this->configDir = sys_get_temp_dir() . '/media-prune-config-' . uniqid();
        mkdir($this->configDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->mediaDir)) {
            foreach (glob($this->mediaDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->mediaDir);
        }
        foreach (glob($this->configDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->configDir);
    }

    /** One catalog product with slug `abc123-tadalafil`, no overrides. */
    private function catalogProvider(): CatalogProvider
    {
        file_put_contents(
            $this->configDir . '/products.generated.php',
            '<?php return ["channel" => ["id" => "c1", "name" => "Flow 1", "currency" => "USD"], '
            . '"products" => ["abc123-tadalafil" => ["slug" => "abc123-tadalafil", "name" => "Tadalafil"]]];',
        );

        return new CatalogProvider(Config::load($this->configDir));
    }

    public function testEmptyMediaDirectoryPrintsNoOrphanedMedia(): void
    {
        mkdir($this->mediaDir);
        $command = new MediaPruneCommand($this->mediaDir, $this->catalogProvider());
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No orphaned media.', $tester->getDisplay());
    }

    public function testMissingMediaDirectoryPrintsNoOrphanedMedia(): void
    {
        $command = new MediaPruneCommand($this->mediaDir, $this->catalogProvider());
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No orphaned media.', $tester->getDisplay());
    }

    public function testWithoutForceListsOrphansAsDryRunAndDoesNotDelete(): void
    {
        mkdir($this->mediaDir);
        file_put_contents($this->mediaDir . '/abc123-tadalafil.aaaaaaaa.jpg', 'kept'); // matches catalog slug
        file_put_contents($this->mediaDir . '/old-gone.deadbeef.png', 'orphan');

        $command = new MediaPruneCommand($this->mediaDir, $this->catalogProvider());
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('old-gone.deadbeef.png', $display);
        self::assertStringNotContainsString('abc123-tadalafil.aaaaaaaa.jpg', $display);
        self::assertStringContainsString('dry run', $display);
        self::assertStringContainsString('--force', $display);
        self::assertFileExists($this->mediaDir . '/old-gone.deadbeef.png');
    }

    public function testWithForceDeletesOrphansAndPrintsEach(): void
    {
        mkdir($this->mediaDir);
        file_put_contents($this->mediaDir . '/abc123-tadalafil.aaaaaaaa.jpg', 'kept');
        file_put_contents($this->mediaDir . '/old-gone.deadbeef.png', 'orphan');

        $command = new MediaPruneCommand($this->mediaDir, $this->catalogProvider());
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--force' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('old-gone.deadbeef.png', $display);
        self::assertStringNotContainsString('dry run', $display);
        self::assertFileDoesNotExist($this->mediaDir . '/old-gone.deadbeef.png');
        self::assertFileExists($this->mediaDir . '/abc123-tadalafil.aaaaaaaa.jpg');
    }
}

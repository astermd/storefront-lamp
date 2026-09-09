<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Catalog\CatalogBuilder;
use AsterMD\Storefront\Catalog\CatalogValidator;
use AsterMD\Storefront\Catalog\MediaLocalizer;
use AsterMD\Storefront\Console\SyncCommand;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    private string $appConfigDir;

    private string $syncConfigDir;

    private string $mediaDir;

    private string $rootDir;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->appConfigDir = sys_get_temp_dir() . '/sync-app-config-' . uniqid();
        $this->syncConfigDir = sys_get_temp_dir() . '/sync-config-' . uniqid();
        $this->mediaDir = sys_get_temp_dir() . '/sync-media-' . uniqid();
        $this->rootDir = sys_get_temp_dir() . '/sync-root-' . uniqid();
        mkdir($this->appConfigDir);
        mkdir($this->syncConfigDir);
        mkdir($this->rootDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        foreach ([$this->appConfigDir, $this->syncConfigDir, $this->mediaDir] as $dir) {
            self::removeDir($dir);
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? self::removeDir($file) : @unlink($file);
        }

        @rmdir($dir);
    }

    private function config(?string $channelId = 'channel-123'): Config
    {
        $channelIdExport = $channelId === null ? 'null' : "'" . $channelId . "'";
        file_put_contents(
            $this->appConfigDir . '/app.php',
            "<?php return ['emr' => ['base_host' => 'sales.example.test', 'channel_id' => {$channelIdExport}]];",
        );

        return Config::load($this->appConfigDir);
    }

    /** @return array<string, mixed> the full channel-details fixture envelope (`success`/`data`/...) */
    private function fixtureEnvelope(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/channel-details.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> the `data` subtree of the channel-details fixture */
    private function fixtureData(): array
    {
        return $this->fixtureEnvelope()['data'];
    }

    private function tokenRoute(): array
    {
        return [200, [
            'success' => true,
            'data' => [
                'access_token' => 'test-token',
                'access_token_expiry' => '2099-01-01T00:00:00.000Z',
            ],
        ]];
    }

    private function fakeClient(): FakeEmrHttpClient
    {
        return new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => $this->tokenRoute(),
            '/v1/sales/channels/detail/' => [200, $this->fixtureEnvelope()],
            'products/' => [200, 'FAKE-IMAGE-BYTES'],
        ]);
    }

    public function testDryRunPrintsBothDiffsMediaCountAndProviderWarningAndWritesNothing(): void
    {
        $fake = $this->fakeClient();
        $command = new SyncCommand(new ClientFactory($this->config(), $this->rootDir), $this->syncConfigDir, $this->mediaDir, $fake);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('products.generated.php', $display);
        self::assertStringContainsString('channel.generated.php', $display);

        $expectedCatalog = CatalogBuilder::build($this->fixtureData(), 'USD');
        $expectedRemoteImages = 0;
        $expectedMissingProviders = 0;
        foreach ($expectedCatalog['products'] as $product) {
            if (($product['remote_image'] ?? null) !== null) {
                $expectedRemoteImages++;
            }
            foreach ($product['variants'] as $variant) {
                if (($variant['provider'] ?? null) === null) {
                    $expectedMissingProviders++;
                }
            }
        }

        self::assertStringContainsString(sprintf('media: %d images would be localised', $expectedRemoteImages), $display);
        self::assertStringContainsString(sprintf('%d variants lack provider identifiers', $expectedMissingProviders), $display);

        self::assertFileDoesNotExist($this->syncConfigDir . '/products.generated.php');
        self::assertFileDoesNotExist($this->syncConfigDir . '/channel.generated.php');
        self::assertDirectoryDoesNotExist($this->mediaDir);
    }

    public function testApplyWritesBothFilesAndProductsFileRoundTripsToLocalizedBuilderOutput(): void
    {
        $fake = $this->fakeClient();
        $command = new SyncCommand(new ClientFactory($this->config(), $this->rootDir), $this->syncConfigDir, $this->mediaDir, $fake);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--apply' => true]));

        $display = $tester->getDisplay();
        $expectedMissingProviders = 0;
        foreach (CatalogBuilder::build($this->fixtureData(), 'USD')['products'] as $product) {
            foreach ($product['variants'] as $variant) {
                if (($variant['provider'] ?? null) === null) {
                    $expectedMissingProviders++;
                }
            }
        }
        self::assertStringContainsString(sprintf('%d variants lack provider identifiers', $expectedMissingProviders), $display);

        $productsFile = $this->syncConfigDir . '/products.generated.php';
        $channelFile = $this->syncConfigDir . '/channel.generated.php';
        self::assertFileExists($productsFile);
        self::assertFileExists($channelFile);

        // Independently localize the same builder output against a separate media dir
        // (same fake transport => identical content-hashed filenames) to get the
        // expected written catalog without depending on the command's own side effects.
        $expectedMediaDir = sys_get_temp_dir() . '/sync-media-expected-' . uniqid();
        $expectedLocalizer = new MediaLocalizer($this->fakeClient(), $expectedMediaDir, 'https://cdn.astermd.com');
        $expected = $expectedLocalizer->localize(CatalogBuilder::build($this->fixtureData(), 'USD'))['catalog'];

        /** @var array<string, mixed> $actual */
        $actual = require $productsFile;
        self::assertSame($expected, $actual);
        self::removeDir($expectedMediaDir);

        /** @var array<string, mixed> $channel */
        $channel = require $channelFile;
        self::assertSame('vrio', $channel['payment_processor']['provider_category']);
        self::assertSame('6a1c2a565f315cee0e41c395', $channel['channel']['id']);
    }

    public function testApplyRequestsImagesUnderTheAssetEnvironmentSegment(): void
    {
        $fake = $this->fakeClient();
        $command = new SyncCommand(new ClientFactory($this->config(), $this->rootDir), $this->syncConfigDir, $this->mediaDir, $fake);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--apply' => true]));

        self::assertNotEmpty($fake->requests);
        foreach ($fake->requests as $request) {
            $path = (string) $request->getUri()->getPath();
            if (str_contains($path, 'products/')) {
                self::assertStringContainsString('/ui/production/', $path);
            }
        }
    }

    public function testMergeNotReplaceSurvivesHandAddedKeyAlongsideRefreshedPayload(): void
    {
        file_put_contents(
            $this->syncConfigDir . '/channel.generated.php',
            "<?php return ['channel' => ['id' => 'old-id', 'name' => 'Old Name'], "
            . "'payment_processor' => ['provider_category' => 'stale', 'name' => 'Stale', "
            . "'config' => ['debug_log' => true, 'api_key' => 'stale-key']]];",
        );

        $fake = $this->fakeClient();
        $command = new SyncCommand(new ClientFactory($this->config(), $this->rootDir), $this->syncConfigDir, $this->mediaDir, $fake);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--apply' => true]));

        /** @var array<string, mixed> $channel */
        $channel = require $this->syncConfigDir . '/channel.generated.php';

        self::assertTrue($channel['payment_processor']['config']['debug_log']);
        self::assertSame('vrio', $channel['payment_processor']['provider_category']);
        self::assertSame('REDACTED', $channel['payment_processor']['config']['api_key']);
    }

    public function testMissingChannelIdExitsOne(): void
    {
        $command = new SyncCommand(
            new ClientFactory($this->config(null), $this->rootDir),
            $this->syncConfigDir,
            $this->mediaDir,
            new FakeEmrHttpClient([]),
        );
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('no channel id', $tester->getDisplay());
    }

    public function testValidatorErrorCausesApplyToExitTwo(): void
    {
        $fake = $this->fakeClient();
        $validator = static fn (array $catalog): array => ['errors' => ['boom'], 'warnings' => []];
        $command = new SyncCommand(
            new ClientFactory($this->config(), $this->rootDir),
            $this->syncConfigDir,
            $this->mediaDir,
            $fake,
            $validator,
        );
        $tester = new CommandTester($command);

        self::assertSame(2, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('validation error: boom', $tester->getDisplay());
        self::assertFileExists($this->syncConfigDir . '/products.generated.php');
    }

    /**
     * Proves the bin/console-style wiring (`fn (array $catalog): array => (new
     * CatalogValidator())->validate($catalog)`) actually runs the real
     * validator during `--apply` — closing the "validation: skipped" gap. The
     * fixture-built catalog is structurally valid (see CatalogValidatorTest),
     * so apply exits 0 and prints neither a "validation error" nor the
     * "validator not wired" skip message.
     */
    public function testRealValidatorWiredBinConsoleStyleRunsDuringApplyAndExitsZero(): void
    {
        $fake = $this->fakeClient();
        $validator = static fn (array $catalog): array => (new CatalogValidator())->validate($catalog);
        $command = new SyncCommand(
            new ClientFactory($this->config(), $this->rootDir),
            $this->syncConfigDir,
            $this->mediaDir,
            $fake,
            $validator,
        );
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--apply' => true]));

        $display = $tester->getDisplay();
        self::assertStringNotContainsString('validation: skipped', $display);
        self::assertStringNotContainsString('validation error:', $display);
    }

    public function testValidatorSeamSkippedMessageWhenNoneWired(): void
    {
        $fake = $this->fakeClient();
        $command = new SyncCommand(new ClientFactory($this->config(), $this->rootDir), $this->syncConfigDir, $this->mediaDir, $fake);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('validation: skipped (validator not wired)', $tester->getDisplay());
    }
}

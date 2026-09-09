<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    private string $dir;
    private string $rootDir;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }

        $this->dir = sys_get_temp_dir() . '/emr-client-factory-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-client-factory-root-' . uniqid();
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
    }

    private function config(?string $baseHost = null): Config
    {
        $baseHost ??= 'sales.example.test';
        file_put_contents(
            $this->dir . '/app.php',
            sprintf(
                '<?php return ["emr" => ["base_host" => %s, "channel_id" => "channel-123"]];',
                var_export($baseHost, true),
            ),
        );

        return Config::load($this->dir);
    }

    public function testCreateThrowsWhenCredentialsAreBlank(): void
    {
        $factory = new ClientFactory($this->config(), $this->rootDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ASTERMD_CLIENT_ID|ASTERMD_CLIENT_SECRET/');

        $factory->create();
    }

    public function testCreateReturnsClientWiredToInjectedHttpClientAndFixture(): void
    {
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/channel-details.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $fake = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => [200, [
                'success' => true,
                'data' => [
                    'access_token' => 'test-token',
                    'access_token_expiry' => '2099-01-01T00:00:00.000Z',
                ],
            ]],
            '/v1/sales/channels/detail/' => [200, $fixture],
        ]);

        $factory = new ClientFactory($this->config(), $this->rootDir);
        $client = $factory->create($fake);

        $response = $client->channels()->details('channel-123');

        self::assertSame('Flow 1', $response->data()['name']);
        self::assertCount(7, $response->data()['products']);
        self::assertSame('vrio', $response->data()['payment_processor']['provider_category']);

        self::assertSame('channel-123', $factory->channelId());
    }

    public function testAssetEnvironmentDerivesFromBaseHost(): void
    {
        self::assertSame(
            'development',
            (new ClientFactory($this->config('health.api.dev.example.test'), $this->rootDir))->assetEnvironment(),
        );
        self::assertSame(
            'staging',
            (new ClientFactory($this->config('health.api.staging.example.test'), $this->rootDir))->assetEnvironment(),
        );
        self::assertSame(
            'production',
            (new ClientFactory($this->config('health.api.example.test'), $this->rootDir))->assetEnvironment(),
        );
    }
}

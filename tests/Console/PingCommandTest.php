<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\PingCommand;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PingCommandTest extends TestCase
{
    private string $dir;
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

        $this->dir = sys_get_temp_dir() . '/ping-command-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/ping-command-root-' . uniqid();
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

    private function config(): Config
    {
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return Config::load($this->dir);
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

    public function testExitsZeroAndPrintsChannelSummaryOnSuccess(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/channel-details.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $fake = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => $this->tokenRoute(),
            '/v1/sales/channels/detail/' => [200, $fixture],
        ]);

        $command = new PingCommand(new ClientFactory($this->config(), $this->rootDir), $fake);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Flow 1', $display);
        self::assertStringContainsString('7', $display);
        self::assertStringContainsString('vrio', $display);
    }

    public function testExitsOneOnServerError(): void
    {
        $fake = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => $this->tokenRoute(),
            '/v1/sales/channels/detail/' => [500, ['success' => false, 'message' => 'Internal error']],
        ]);

        $command = new PingCommand(new ClientFactory($this->config(), $this->rootDir), $fake);
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('EMR ping failed', $tester->getDisplay());
    }
}

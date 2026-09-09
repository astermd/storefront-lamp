<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Emr\CartMirrorResult;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrCartGateway;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

final class EmrCartGatewayTest extends TestCase
{
    private const TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private string $dir;
    private string $rootDir;
    private string $logFile;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->dir = sys_get_temp_dir() . '/emr-cart-gateway-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-cart-gateway-root-' . uniqid();
        mkdir($this->rootDir);
        $this->logFile = sys_get_temp_dir() . '/emr-cart-gateway-log-' . uniqid() . '.log';
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

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function clientFactory(): ClientFactory
    {
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return new ClientFactory(Config::load($this->dir), $this->rootDir);
    }

    private function log(): OperatorLog
    {
        return new OperatorLog($this->logFile);
    }

    private function items(): array
    {
        return [['product_id' => 'emr-1', 'name' => 'Semaglutide', 'qty' => 1]];
    }

    public function testCreatePostsTheItemsAndReportsOk(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/carts/create' => [200, ['success' => true, 'data' => ['status' => 'open', 'items' => []]]],
        ]);
        $gateway = new EmrCartGateway($this->clientFactory(), $this->log(), $http);

        $result = $gateway->create('sess-1', $this->items());

        self::assertSame(CartMirrorResult::Ok, $result);
        $create = array_values(array_filter(
            $http->requests,
            static fn ($request): bool => str_contains($request->getUri()->getPath(), '/carts/create'),
        ))[0];
        self::assertStringContainsString('/carts/create', $create->getUri()->getPath());
    }

    public function testUpdateReportsNotFoundSoTheCallerCanRecreate(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/carts/update' => [404, ['success' => false]],
        ]);
        $gateway = new EmrCartGateway($this->clientFactory(), $this->log(), $http);

        $result = $gateway->update('sess-1', $this->items());

        self::assertSame(CartMirrorResult::NotFound, $result);
    }

    public function testATransportFailureReportsFailedAndLogs(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/carts/create' => [500, ['success' => false]],
        ]);
        $gateway = new EmrCartGateway($this->clientFactory(), $this->log(), $http);

        $result = $gateway->create('sess-1', $this->items());

        self::assertSame(CartMirrorResult::Failed, $result);
        // Its own event name, not the `cart.mirror_failed` CartMirror writes:
        // one mirror can make two provider calls, and a shared name
        // double-counted every failure.
        self::assertStringContainsString('cart.mirror_request_failed', (string) file_get_contents($this->logFile));
    }

    /**
     * The provider's own words never reach disk. The SDK builds the exception
     * message from the response envelope's `message` field, the mirrored
     * payload carries a product name per line, and key-based redaction cannot
     * see inside a string (`[20.6]`). What is left has to stay diagnosable:
     * the exception class and the HTTP status.
     */
    public function testTheProvidersMessageIsReplacedByTheExceptionClassAndStatus(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/carts/create' => [503, ['success' => false, 'message' => 'Semaglutide is not stocked here.']],
        ]);
        $gateway = new EmrCartGateway($this->clientFactory(), $this->log(), $http);

        $gateway->create('sess-1', $this->items());
        $log = (string) file_get_contents($this->logFile);

        self::assertStringNotContainsString('Semaglutide', $log);
        self::assertStringNotContainsString('not stocked', $log);
        self::assertStringContainsString('ApiException', $log);
        self::assertStringContainsString('"status":503', $log);
    }
}

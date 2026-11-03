<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrCouponGateway;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

final class EmrCouponGatewayTest extends TestCase
{
    private const TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private const VALID_ROUTE = ['/coupons/validate' => [200, [
        'success' => true,
        'data' => ['coupon_id' => '6a01b2c3d4e5f6a7b8c9d0f9', 'discount_cents' => 1000],
    ]]];

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';
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

    public function testTheBuyersEmailNamesThemSoAPatientOnlyCouponCanMatch(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + self::VALID_ROUTE);

        $quote = $this->gateway($http)->quote('SAVE10', [self::line()], ' ada@example.com ');

        self::assertTrue($quote->valid);
        self::assertSame(1000, $quote->discountCents);
        self::assertSame('ada@example.com', $this->validateBody($http)['email'] ?? null);
    }

    public function testNoEmailIsNoKeyRatherThanAnEmptyOne(): void
    {
        foreach ([null, '', '   '] as $email) {
            $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + self::VALID_ROUTE);

            $quote = $this->gateway($http)->quote('SAVE10', [self::line()], $email);

            self::assertTrue($quote->valid, var_export($email, true));
            self::assertArrayNotHasKey('email', $this->validateBody($http), var_export($email, true));
        }
    }

    public function testTheCartIsNamedByTheEmrsOwnIdsAndNeverByAnAmount(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + self::VALID_ROUTE);

        $this->gateway($http)->quote('SAVE10', [self::line()], 'ada@example.com');

        self::assertSame(
            ['code' => 'SAVE10', 'items' => [['product_id' => 'emr-p1', 'variant_id' => 'emr-v1', 'quantity' => 1]], 'email' => 'ada@example.com'],
            $this->validateBody($http),
        );
    }

    private function gateway(FakeEmrHttpClient $http): EmrCouponGateway
    {
        $dir = sys_get_temp_dir() . '/emr-coupon-gateway-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/app.php', '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];');
        $root = sys_get_temp_dir() . '/emr-coupon-gateway-root-' . uniqid();
        mkdir($root);

        return new EmrCouponGateway(new ClientFactory(Config::load($dir), $root), (new CapturedLog())->log, $http);
    }

    private static function line(): OrderLine
    {
        return new OrderLine('semaglutide', 'Semaglutide', null, null, 5000, 1, emrProductId: 'emr-p1', emrVariantId: 'emr-v1');
    }

    /** @return array<string, mixed> */
    private function validateBody(FakeEmrHttpClient $http): array
    {
        foreach ($http->requests as $request) {
            if (str_contains($request->getUri()->getPath(), '/coupons/validate')) {
                return json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        self::fail('no coupon check reached the EMR');
    }
}

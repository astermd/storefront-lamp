<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrCouponGateway;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The coupon check through the real SDK, against a fake of the EMR's
 * `POST /v1/sales/coupons/validate`.
 *
 * The answers below are the sales spec's own (`CouponValidateResponse` and
 * its 404/422 examples), so what is pinned is the contract the EMR documents
 * rather than a shape invented for the test.
 */
final class EmrCouponGatewayTest extends TestCase
{
    private const array TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private const string PLAN = '6a01b2c3d4e5f6a7b8c9d0f1';

    private const string PRODUCT = '6a01b2c3d4e5f6a7b8c9d0a1';

    private const string VARIANT = '6a01b2c3d4e5f6a7b8c9d0a2';

    private const string COUPON = '6a01b2c3d4e5f6a7b8c9d0f9';

    private const string OPPORTUNITY = '6a0f1e2d3c4b5a6978877665';

    private string $dir;

    private string $rootDir;

    private CapturedLog $log;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->dir = sys_get_temp_dir() . '/emr-coupon-gateway-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-coupon-gateway-root-' . uniqid();
        mkdir($this->rootDir);
        $this->log = new CapturedLog();
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

    private function gateway(FakeEmrHttpClient $http): EmrCouponGateway
    {
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return new EmrCouponGateway(new ClientFactory(Config::load($this->dir), $this->rootDir), $this->log->log, $http);
    }

    /** @param array<string, mixed>|string $body */
    private static function answering(int $status, array|string $body): FakeEmrHttpClient
    {
        return new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/coupons/validate' => [$status, $body]]);
    }

    /** @return array<string, mixed> */
    private static function accepted(): array
    {
        return ['success' => true, 'data' => [
            'coupon_id' => self::COUPON,
            'name' => 'Welcome 20% off',
            'discount_type' => 'percent',
            'value' => 20,
            'gross_amount_cents' => 9900,
            'discount_cents' => 1980,
            'amount_cents' => 7920,
            'currency' => 'usd',
        ]];
    }

    /** @return list<OrderLine> a plan line and a one-off line, as the checkout sends them */
    private static function lines(): array
    {
        return [
            new OrderLine('semaglutide', 'Semaglutide 0.25 mg', null, null, 7900, 1, 'rx', planId: self::PLAN),
            new OrderLine('b12', 'B12 Injection Kit', null, null, 2000, 1, 'otc', emrProductId: self::PRODUCT, emrVariantId: self::VARIANT),
        ];
    }

    /** @return array<string, mixed> the body of the one coupon check sent */
    private static function sent(FakeEmrHttpClient $http): array
    {
        $checks = array_values(array_filter(
            $http->requests,
            static fn ($request): bool => str_contains($request->getUri()->getPath(), '/coupons/validate'),
        ));
        self::assertCount(1, $checks);

        return json_decode((string) $checks[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testCodesCanBePricedOnTheInstalledSdk(): void
    {
        self::assertTrue($this->gateway(self::answering(200, self::accepted()))->available());
    }

    public function testAnAcceptedCodeIsWorthWhatTheEmrTakesOffAndNamedByItsCoupon(): void
    {
        $http = self::answering(200, self::accepted());

        $quote = $this->gateway($http)->quote(' WELCOME20 ', self::lines(), 'ada@example.com', self::OPPORTUNITY);

        self::assertTrue($quote->valid);
        self::assertSame('WELCOME20', $quote->code);
        self::assertSame(1980, $quote->discountCents);
        self::assertSame(self::COUPON, $quote->reference);
        self::assertSame([
            'code' => 'WELCOME20',
            'items' => [
                ['plan_id' => self::PLAN, 'quantity' => 1],
                ['product_id' => self::PRODUCT, 'variant_id' => self::VARIANT, 'quantity' => 1],
            ],
            'email' => 'ada@example.com',
            'opportunity_id' => self::OPPORTUNITY,
        ], self::sent($http));
    }

    public function testTheBuyersEmailIsSentWithoutTheSpaceAroundIt(): void
    {
        $http = self::answering(200, self::accepted());

        $this->gateway($http)->quote('WELCOME20', self::lines(), ' ada@example.com ');

        self::assertSame('ada@example.com', self::sent($http)['email'] ?? null);
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function unreadableShoppers(): iterable
    {
        yield 'nothing known yet' => [null, null];
        yield 'an empty email box' => ['', null];
        yield 'a blank email box' => ['   ', null];
        yield 'an email still being typed' => ['ada@', null];
        yield 'an email with no domain' => ['ada@localhost', null];
        yield 'an opportunity that is not an EMR id' => [null, 'opp-123'];
    }

    /**
     * The EMR answers a malformed email or opportunity with a 400 for the
     * whole request, which this reads as a coupon service that cannot answer.
     * A buyer who has half-typed their email is a shopper the EMR cannot
     * name, not a reason no code applies.
     */
    #[DataProvider('unreadableShoppers')]
    public function testAShopperTheEmrCouldNotReadIsLeftUnnamedRatherThanFailingTheCheck(?string $email, ?string $opportunityId): void
    {
        $http = self::answering(200, self::accepted());

        $quote = $this->gateway($http)->quote('WELCOME20', self::lines(), $email, $opportunityId);

        self::assertTrue($quote->valid);
        $body = self::sent($http);
        self::assertArrayNotHasKey('email', $body);
        self::assertArrayNotHasKey('opportunity_id', $body);
        self::assertArrayNotHasKey('patient_id', $body, 'the storefront has no patient id to send');
    }

    public function testAnUnknownCodeIsSaidToBeInvalidInTheStorefrontsWords(): void
    {
        $quote = $this->gateway(self::answering(404, ['success' => false, 'message' => 'There is no coupon with this code']))
            ->quote('NOPE', self::lines());

        self::assertFalse($quote->valid);
        self::assertSame('unknown_code', $quote->reason);
        self::assertSame("That discount code isn't valid.", $quote->notice);
    }

    public function testARefusalIsExplainedInTheEmrsOwnWords(): void
    {
        $quote = $this->gateway(self::answering(422, [
            'success' => false,
            'message' => 'This patient has redeemed the coupon as many times as it allows',
        ]))->quote('WELCOME20', self::lines(), 'ada@example.com');

        self::assertFalse($quote->valid);
        self::assertSame('denied', $quote->reason);
        self::assertSame('This patient has redeemed the coupon as many times as it allows', $quote->notice);
    }

    /** @return iterable<string, array{int}> */
    public static function unanswered(): iterable
    {
        yield 'a malformed request' => [400];
        yield 'a role without the validate permission' => [403];
        yield 'the coupon service failing' => [500];
    }

    /**
     * Not the buyer's to read, and not the log's to keep: the EMR's message
     * for these is its own free text (`[20.6]`).
     */
    #[DataProvider('unanswered')]
    public function testAnEmrThatCannotAnswerIsACodeThatDidNotApplyThisTime(int $status): void
    {
        $quote = $this->gateway(self::answering($status, ['success' => false, 'message' => 'Mongo pool exhausted on sales-7f9c']))
            ->quote('WELCOME20', self::lines());

        self::assertFalse($quote->valid);
        self::assertSame('unavailable', $quote->reason);
        self::assertNull($quote->notice);
        self::assertNotSame([], $this->log->lines(), 'an EMR that cannot answer is worth an operator line');
        self::assertStringNotContainsString('Mongo pool', (string) json_encode($this->log->lines()));
    }

    public function testAnAnswerWithoutADiscountOrCouponIsNotTakenAsOne(): void
    {
        $quote = $this->gateway(self::answering(200, ['success' => true, 'data' => ['name' => 'Welcome 20% off']]))
            ->quote('WELCOME20', self::lines());

        self::assertFalse($quote->valid);
        self::assertSame('unreadable', $quote->reason);
    }

    /**
     * The sales service's `items`: the lines the coupon discounted, named the
     * way they were sent, at their price after it.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function acceptedWithItems(array $items): array
    {
        $answer = self::accepted();
        $answer['data']['items'] = $items;

        return $answer;
    }

    public function testTheDiscountIsSplitAcrossTheLinesTheEmrSaysItDiscounted(): void
    {
        $http = self::answering(200, self::acceptedWithItems([
            ['plan_id' => self::PLAN, 'product_id' => self::PRODUCT, 'variant_id' => null, 'quantity' => 1,
                'unit_amount_cents' => 7900, 'gross_amount_cents' => 7900, 'discount_cents' => 1580, 'amount_cents' => 6320],
            ['plan_id' => null, 'product_id' => self::PRODUCT, 'variant_id' => self::VARIANT, 'quantity' => 1,
                'unit_amount_cents' => 2000, 'gross_amount_cents' => 2000, 'discount_cents' => 400, 'amount_cents' => 1600],
        ]));

        $quote = $this->gateway($http)->quote('WELCOME20', self::lines());

        self::assertSame(1980, $quote->discountCents);
        self::assertSame(['semaglutide' => 1580, 'b12' => 400], $quote->lineDiscounts);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function itemsThatAreNotABreakdown(): iterable
    {
        yield 'a line the cart does not have' => [[
            ['plan_id' => '6a01b2c3d4e5f6a7b8c9d0ff', 'discount_cents' => 1980],
        ]];
        yield 'parts that do not add up to the discount' => [[
            ['plan_id' => self::PLAN, 'discount_cents' => 1580],
        ]];
        yield 'a part with no figure' => [[
            ['plan_id' => self::PLAN, 'discount_cents' => '1980'],
        ]];
    }

    /**
     * The total is what is charged, so a breakdown that disagrees with it is
     * dropped rather than shown beside it; the code still applies.
     *
     * @param list<array<string, mixed>> $items
     */
    #[DataProvider('itemsThatAreNotABreakdown')]
    public function testAnAnswerWhoseLinesAreNotABreakdownOfTheDiscountIsShownAsTheTotalAlone(array $items): void
    {
        $quote = $this->gateway(self::answering(200, self::acceptedWithItems($items)))->quote('WELCOME20', self::lines());

        self::assertTrue($quote->valid);
        self::assertSame(1980, $quote->discountCents);
        self::assertSame([], $quote->lineDiscounts);
        self::assertNotSame([], $this->log->eventsNamed('coupon.unmatched_lines'));
    }

    public function testALineTheEmrCannotNameIsNeverPricedAsPartOfTheCart(): void
    {
        $http = self::answering(200, self::accepted());
        $lines = [...self::lines(), new OrderLine('sharps', 'Sharps container', null, null, 500, 1)];

        $quote = $this->gateway($http)->quote('WELCOME20', $lines);

        self::assertFalse($quote->valid);
        self::assertSame('unsupported', $quote->reason);
        self::assertSame([], $http->requests);
    }
}

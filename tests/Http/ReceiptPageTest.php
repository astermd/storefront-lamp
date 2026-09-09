<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The receipt page renders the order that was actually placed.
 *
 * `tests/Http/FunnelPagesTest.php` already proves the page answers 200 with a
 * `noindex`; what it cannot prove is that the figures on it are the buyer's
 * own, because its journey names a reference with no row behind it. So this
 * case writes real `orders` rows and a real buyer into journey state and reads
 * the rendered page back.
 *
 * The negative assertion is the important one. The template was transcribed
 * from a static mockup full of sample data — an order number, a case id, two
 * different postal addresses, a courier, a card, three money figures — and a
 * transcription that is half finished looks completely finished until one of
 * those strings turns up on a real buyer's receipt.
 */
final class ReceiptPageTest extends TestCase
{
    use TempDatabase;

    /** The journey that has bought something, as {@see FunnelPagesTest} spells it. */
    private const string BUYER_SESSION = '7c2e5d41-9a3b-4f18-8e6d-1b4a7c9e2f55';

    /** The checkout order, and then the upsell charged against the stored handle. */
    private const string CHECKOUT_REFERENCE = '34788';
    private const string UPSELL_REFERENCE = '34791';

    /**
     * Every figure and string the mockup shipped that must not survive the
     * transcription, in one list.
     *
     * A value here failing is not a formatting nit: each one is a fact about
     * somebody who does not exist, on a page a real buyer keeps.
     *
     * @var list<string>
     */
    private const array MOCKUP_SAMPLES = [
        '123456',
        'PMD1493450',
        'john.doe@mail.com',
        '+1 (123) 456-7890',
        '1-23 Main Street',
        '41-25 Kissena Blvd',
        'Flushing',
        '$145.50',
        '$138.00',
        '$7.50',
        'DHL',
        'Ramelteon 8mg',
        'Sleeping Mask',
        'ending in 1234',
    ];

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * The application, plus a journey that placed two orders and a buyer to
     * ship them to.
     *
     * Built the way `FunnelPagesTest::appWithPlacedOrder()` builds its journey —
     * the durable session row written before the first request, because the
     * journey store keeps one state per container — with the order rows those
     * references actually resolve to added, since the receipt is assembled from
     * the rows and a reference with nothing behind it renders a thin page.
     *
     * Two orders rather than one so that the total on the page is a sum and not
     * a copy of a single column.
     *
     * @param int     $discountCents the checkout order's discount
     * @param ?string $promotionCode the code that earned it, if any
     */
    private function appWithReceipt(int $discountCents = 2450, ?string $promotionCode = 'WELCOME10'): \Slim\App
    {
        $pdo = $this->tempPdo();

        // The session row goes in first: `orders.session_uuid` carries a foreign
        // key to it, so an order written before its journey exists is rejected
        // by the schema.
        (new SessionRepository(fn (): \PDO => $pdo))->insert(
            self::BUYER_SESSION,
            [
                'placed_orders' => [self::CHECKOUT_REFERENCE, self::UPSELL_REFERENCE],
                'reconciled' => true,
                'buyer' => [
                    'first_name' => 'Dana',
                    'last_name' => 'Reyes',
                    'email' => 'dana.reyes@example.test',
                    'phone' => '+1 (415) 555-0142',
                    'address_line' => '900 Larkin Avenue',
                    'city' => 'Portland',
                    'territory' => 'OR',
                    'postal_code' => '97205',
                ],
            ],
            null,
        );

        $orders = new OrderRepository(fn (): \PDO => $pdo);

        $orders->insert(
            [
                'session_uuid' => self::BUYER_SESSION,
                'provider_reference' => self::CHECKOUT_REFERENCE,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 24500 - $discountCents,
                'currency' => 'USD',
                'status' => 'placed',
                'buyer_email' => 'dana.reyes@example.test',
                'buyer_name' => 'Dana Reyes',
                'buyer_territory' => 'OR',
                'discount_cents' => $discountCents,
                'promotion_code' => $promotionCode,
                'payment_method' => 'card',
                'card_last_four' => '4242',
            ],
            [
                ['slug' => 'tirzepatide', 'name' => 'Tirzepatide (5mg/mL)', 'kind' => 'rx', 'unit_price_cents' => 24500, 'quantity' => 1],
                ['slug' => 'wellness-journal', 'name' => 'Wellness Journal', 'kind' => 'otc', 'unit_price_cents' => 0, 'quantity' => 2],
            ],
            [],
        );

        $orders->insert(
            [
                'session_uuid' => self::BUYER_SESSION,
                'provider_reference' => self::UPSELL_REFERENCE,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 899,
                'currency' => 'USD',
                'status' => 'placed',
                'is_upsell' => true,
            ],
            [['slug' => 'travel-case', 'name' => 'Travel Case', 'kind' => 'otc', 'unit_price_cents' => 899, 'quantity' => 1]],
            [],
        );

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $pdo,
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::BUYER_SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
    }

    /**
     * The page for the buyer of that journey.
     *
     * One request per test rather than a shared one: reaching this URL completes
     * the journey and tears it down, so a second visit renders the stored
     * snapshot and would be testing the fire-once guard instead of the page.
     */
    private function receiptBody(\Slim\App $app): string
    {
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/thank-you/')
                ->withCookieParams(['amd_session' => self::BUYER_SESSION]),
        );

        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    public function testReceiptRendersTheOrderThatWasPlaced(): void
    {
        $body = $this->receiptBody($this->appWithReceipt());

        self::assertStringContainsString('Order #' . self::CHECKOUT_REFERENCE, $body);
        self::assertStringContainsString('Thank you, <span id="thankyou-name">Dana</span>!', $body);
        self::assertStringContainsString('Tirzepatide (5mg/mL)', $body);
        self::assertStringContainsString('Travel Case', $body);
    }

    public function testReceiptRendersTheBuyersOwnContactAndAddress(): void
    {
        $body = $this->receiptBody($this->appWithReceipt());

        self::assertStringContainsString('dana.reyes@example.test', $body);
        self::assertStringContainsString('+1 (415) 555-0142', $body);
        self::assertStringContainsString('900 Larkin Avenue', $body);
        self::assertStringContainsString('Portland', $body);
        self::assertStringContainsString('OR 97205', $body);
        // Billing is the shipping address in this deployment, so the same block
        // is rendered twice rather than a second set of fields invented.
        self::assertSame(2, substr_count($body, '900 Larkin Avenue'));
        self::assertSame(2, substr_count($body, 'OR 97205'));
        self::assertStringContainsString('Billing address', $body);
        // `[13.36]` is a declared gap: no country is collected, so the address
        // block drops the line rather than assuming one.
        self::assertStringNotContainsString('United States', $body);
    }

    public function testReceiptTotalIsSummedFromTheOrderRows(): void
    {
        // 24500 − 2450 charged on the checkout order, plus 899 on the upsell.
        $body = $this->receiptBody($this->appWithReceipt());

        self::assertStringContainsString('$229.49', $body);
        // The line prices, which are not the charged figure: 24500 + 0 + 899.
        self::assertStringContainsString('$253.99', $body);
        self::assertStringContainsString('Subtotal • 4 items', $body);
        // A free attachment stays on the receipt at 0¢ rather than vanishing.
        self::assertStringContainsString('$0.00', $body);
    }

    public function testReceiptNamesTheCardWithoutGuessingAnIssuer(): void
    {
        $body = $this->receiptBody($this->appWithReceipt());

        self::assertStringContainsString('Card ending in 4242', $body);
        // The mockup hardcoded a Mastercard badge. Nothing records a scheme, so
        // no scheme is drawn.
        self::assertStringNotContainsString('#EB001B', $body);
    }

    public function testReceiptSaysTheCardWasChargedRatherThanAuthorized(): void
    {
        // The provider charges in one step, so the mockup's "you will not be
        // charged unless your prescription is approved" is false here.
        $body = $this->receiptBody($this->appWithReceipt());

        self::assertStringContainsString('Your card has been charged $229.49', $body);
        self::assertStringNotContainsString('authorized', $body);
    }

    public function testDiscountRowRendersOnlyWhenThereIsADiscount(): void
    {
        $withDiscount = $this->receiptBody($this->appWithReceipt());
        self::assertStringContainsString('<span>Discount • WELCOME10</span>', $withDiscount);
        self::assertStringContainsString('−$24.50', $withDiscount);

        $withoutDiscount = $this->receiptBody($this->appWithReceipt(discountCents: 0, promotionCode: null));
        self::assertStringNotContainsString('<span>Discount', $withoutDiscount);
    }

    public function testNoMockupSampleDataSurvivesAnywhereInTheRenderedPage(): void
    {
        $body = $this->receiptBody($this->appWithReceipt());

        foreach (self::MOCKUP_SAMPLES as $sample) {
            self::assertStringNotContainsString(
                $sample,
                $body,
                sprintf('The mockup sample "%s" is still being rendered on the receipt.', $sample),
            );
        }
    }

    public function testAReceiptWithNothingOnItStillRenders(): void
    {
        // No journey at all: the step guard opens for a journey the storefront
        // cannot read, because a bookkeeping failure must not lock a charged
        // buyer out of their own receipt. The page has to answer with what is
        // still true rather than with zeroes or a 500.
        $app = AppFactory::create(dirname(__DIR__, 2), [\PDO::class => $this->tempPdo()]);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/thank-you/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Intake Received', $body);
        self::assertStringContainsString('Thank you!', $body);
        self::assertStringNotContainsString('Order #', $body);
        self::assertStringNotContainsString('Order details', $body);
        self::assertStringNotContainsString('Subtotal', $body);
        self::assertStringNotContainsString('Your card has been charged', $body);
        self::assertStringNotContainsString('google.com/maps', $body);
    }
}

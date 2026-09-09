<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\CheckoutService;
use AsterMD\Storefront\Checkout\EmrCheckoutEventReporter;
use AsterMD\Storefront\Checkout\OrderBumps;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * One buyer, one purchase, walked from the landing page to the receipt through
 * real HTTP requests — and then every way that walk is allowed to end badly.
 *
 * Every other test in this suite proves one collaborator, or one controller
 * method, against arranged state. This one arranges nothing: the cart is
 * filled by posting to `/cart/add/`, the questionnaire by posting to
 * `/intake/save/`, the bump and the discount code by posting the checkout
 * form's own sub-actions, and the order by posting the checkout form. What it
 * covers is the seam between the pieces, which is where a funnel breaks while
 * every unit passing through it is individually correct.
 *
 * Three collaborators are the real implementations rather than nulls, because
 * the assertions this file exists for are about what they wrote: the payment
 * adapter (over {@see FakeVrioTransport} and the recorded fixtures), the
 * database order recorder, and the EMR event reporter (over
 * {@see FakeEmrHttpClient}). Nothing here opens a socket, and nothing here
 * places a live order.
 *
 * The two cases at the bottom are the ones the whole branch's design exists
 * for. A card number reaching a table or the operator log is the failure every
 * decision in §13 and §15 was made to prevent, and both cases first assert
 * that the card they are searching for genuinely reached the provider payload
 * — otherwise they would pass just as happily searching for a string that was
 * never in play.
 */
final class CheckoutFunnelTest extends TestCase
{
    use TempDatabase;

    /** The analytics session the fake gateway already knows, so nothing is ever re-minted mid-walk. */
    private const string SESSION = 'sess-funnel-0123456789ab';

    private const string TELEFORM = '6a8aec0dec745c70f5f3e69a';

    /** The card the happy path pays with, and the string every table is searched for. */
    private const string CARD = '4111111100084444';

    /** A different card for the decline path, so the log search cannot pass on the other one's absence. */
    private const string DECLINED_CARD = '4111111100005555';

    private const string ALERT_TEXT = 'We cannot prescribe this treatment during pregnancy.';

    /** The buyer's own answers, as the questionnaire stores them and checkout prefills them. */
    private const array ANSWERS = [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
        'phone' => '2125551234',
        'street' => '350 5th Avenue',
        'city' => 'San Francisco',
        'state' => 'CA',
        'zip' => '94105',
    ];

    private \PDO $pdo;

    private FakeVrioTransport $transport;

    private FakeEmrHttpClient $emr;

    private CapturedLog $log;

    private string $cacheDir;

    private string $emrRoot;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->log = new CapturedLog();
        $this->transport = new FakeVrioTransport();
        $this->cacheDir = sys_get_temp_dir() . '/checkout-funnel-' . bin2hex(random_bytes(6));
        $this->emrRoot = sys_get_temp_dir() . '/checkout-funnel-emr-' . bin2hex(random_bytes(6));
        mkdir($this->emrRoot . '/storage/cache', 0775, true);
        $this->registerTempDirForCleanup($this->cacheDir);
        $this->registerTempDirForCleanup($this->emrRoot);

        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    // ------------------------------------------------------------- happy path

    public function testAPrescriptionIsBoughtFromTheLandingPageToTheReceipt(): void
    {
        $app = $this->app(['vrio-discount-calculated.json', 'vrio-order-approved.json'], self::bumpConfig());

        // Land, choose a plan, and be routed into the questionnaire rather
        // than at checkout (`[8.3]`, `[8.4]`).
        $token = $this->land($app);
        $added = $this->post($app, '/cart/add/', [
            '_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1',
        ]);
        self::assertSame('/intake/medical/', $added->getHeaderLine('Location'));
        self::assertSame(302, $this->get($app, '/checkout/')->getStatusCode(), 'checkout is shut until the form is done');

        // Answer it, and be let through.
        self::assertSame(200, $this->get($app, '/intake/medical/')->getStatusCode());
        $save = $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => '0', 'pregnancy_status' => 'no'] + self::ANSWERS);
        self::assertSame('/intake/medical/', $save->getHeaderLine('Location'));
        $submitted = $this->post($app, '/intake/submit/', ['_csrf' => $token]);
        self::assertSame('/checkout/', $submitted->getHeaderLine('Location'));
        self::assertTrue($this->state($app)->formCompleted(self::TELEFORM));

        // `[13.5b]`, `[13.5c]`: what the buyer already told the questionnaire
        // is in the boxes, in the `value` attributes and not merely in the
        // page's data.
        $checkout = $this->get($app, '/checkout/');
        $body = (string) $checkout->getBody();
        self::assertSame(200, $checkout->getStatusCode());
        self::assertStringContainsString('value="Ada"', $body);
        self::assertStringContainsString('value="ada@example.test"', $body);
        self::assertStringContainsString('value="350 5th Avenue"', $body);
        self::assertStringContainsString('value="94105"', $body);
        self::assertStringContainsString('<option value="CA" selected>California</option>', $body);
        self::assertStringContainsString('$120.00', $body, 'the chosen plan prices the line');

        // `[27.10]`, `[27.11]`, `[27.12]`: accepting a bump moves the total by
        // the offer's own price, not the catalog's.
        $bump = $this->post($app, '/checkout/bump/', [
            '_csrf' => $token, 'bump_slug' => 'pill-organizer',
        ] + $this->submittedFields());
        self::assertSame(303, $bump->getStatusCode());
        $body = (string) $this->get($app, '/checkout/')->getBody();
        self::assertStringContainsString('Pill Organizer', $body);
        self::assertStringContainsString('$120.99', $body, 'the subtotal moved by the bump price, not the catalog price');

        // `[13.11]`: the provider prices the code and the storefront records
        // the answer. 12.00 + 27.00 is what the recorded calculation returned.
        $promo = $this->post($app, '/checkout/promo/', [
            '_csrf' => $token, 'discount_code' => 'NEW10',
        ] + $this->submittedFields());
        self::assertSame(303, $promo->getStatusCode());
        $body = (string) $this->get($app, '/checkout/')->getBody();
        self::assertStringContainsString('−$39.00', $body, 'the discount the provider calculated');
        self::assertStringContainsString('$81.99', $body, 'and the total it leaves');
        self::assertStringNotContainsString('−$-', $body, '[13.12]: the clamp means no total is ever negative');

        // Pay.
        $placed = $this->post($app, '/checkout/', $this->submission($token));
        self::assertSame(303, $placed->getStatusCode());
        self::assertSame('/upsell/', $placed->getHeaderLine('Location'), 'the one outcome that moves the buyer on');

        // `[15.12]`, `[15.13]`: what a placed order leaves behind to pay for an
        // upsell is the opaque handle the provider issued, and it has to be read
        // back out of the `sessions` row rather than off the object that wrote it
        // — the upsell step is a *later request*, and an in-process read of the
        // same store would pass whether or not anything durable was written.
        //
        // Read here rather than at the end of the walk, because the receipt is
        // where the journey is torn down and the handle is deliberately one of
        // the things that does not survive it (`[4.16]`).
        $handle = (new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo)))
            ->load(self::SESSION)
            ->reusableCredential();

        self::assertNotNull($handle, 'the upsell step has something to charge against');
        self::assertSame(['customer_id' => '13957', 'customer_card_id' => '16764'], $handle->handle);
        self::assertStringNotContainsString(
            self::CARD,
            (string) $this->row('SELECT journey_state FROM sessions')['journey_state'],
            '`[15.8]`: and no card was written durably',
        );

        // The step it names admits them, and then sends them on: no upsell is
        // configured, so the queue is empty and an empty queue goes to the
        // receipt (`[16.6]`).
        $offer = $this->get($app, '/upsell/');
        self::assertSame(303, $offer->getStatusCode(), 'and the step it names admits them');
        self::assertSame('/thank-you/', $offer->getHeaderLine('Location'));
        self::assertSame(200, $this->get($app, '/thank-you/')->getStatusCode());

        // `[18.1]`: the local record, with its lines and its consents.
        $order = $this->row('SELECT * FROM orders');
        self::assertSame('34660', $order['provider_reference']);
        self::assertSame(self::SESSION, $order['session_uuid']);
        self::assertSame('tirzepatide', $order['anchor_slug'], 'the prescription names the order');
        // The money that moved, not the money that was quoted. The recorded
        // approval is a real provider response and fixes `transaction_total`
        // at $120.00, while this walk's own summary came to $81.99 after the
        // bump and the code — so the two genuinely disagree, and the row shows
        // the figure a human reconciling a debit would have to match. The
        // quoted figure is not lost: the gap is an error line of its own.
        self::assertSame(12000, (int) $order['amount_cents']);
        self::assertNotSame(
            [],
            $this->log->eventsNamed('payment.total_mismatch'),
            'a charge that does not match the quote is never silent',
        );
        self::assertSame(3900, (int) $order['discount_cents'], 'and the discount still reached the record');
        self::assertSame('NEW10', $order['promotion_code']);
        self::assertSame('4444', $order['card_last_four'], 'four digits, and only four');

        $lines = $this->rows('SELECT * FROM order_lines ORDER BY slug');
        self::assertSame(
            ['pill-organizer', 'sharps-bin', 'tirzepatide'],
            array_map(static fn (array $line): string => (string) $line['slug'], $lines),
            'the free attachment is on the local record even though the provider never charged for it',
        );
        self::assertSame(0, (int) $this->rows('SELECT * FROM order_lines WHERE slug = \'sharps-bin\'')[0]['sent_to_provider']);

        $consents = $this->rows('SELECT * FROM order_consents ORDER BY consent_key');
        self::assertSame(['marketing', 'terms', 'transactional_sms'], array_map(
            static fn (array $row): string => (string) $row['consent_key'],
            $consents,
        ), '[26.10]: every consent asked is recorded, granted or not');
        self::assertSame(1, (int) $this->rows('SELECT * FROM order_consents WHERE consent_key = \'terms\'')[0]['granted']);
        self::assertSame(0, (int) $this->rows('SELECT * FROM order_consents WHERE consent_key = \'marketing\'')[0]['granted']);

        // The EMR funnel: a create opens the record and the order event
        // updates it, in that order, because an update with no create before
        // it is an event that never lands.
        $paths = $this->emrPaths();
        self::assertStringEndsWith('/checkout-events/create', $paths[0]);
        $update = self::firstIndexEndingWith($paths, '/checkout-events/update/' . self::SESSION);
        self::assertNotNull($update, 'the order event reached the EMR');
        self::assertGreaterThan(0, $update, 'and only after a create had opened the record');
        self::assertNotNull(
            self::firstIndexEndingWith($paths, '/treatments/sync'),
            'the EMR is told about the order the aggregator charged',
        );
        self::assertSame('34660', $this->row('SELECT * FROM orders')['treatment_reference'], 'and the row is stamped with what it was told');

        // `[18.1]`: the append-only local trail. The EMR keeps one record per
        // session and overwrites it, so this table is the only place the whole
        // journey can still be read back in order.
        $trail = array_map(static fn (array $row): string => (string) $row['name'], $this->rows('SELECT name FROM events ORDER BY id'));
        // The order event first, then the treatment sync it triggers. The
        // relative order is the assertion, not the absolute position: the sync
        // is reported from inside the order event, so a trail with the two the
        // other way round would mean a clinician was told about an order the
        // storefront had not finished placing.
        $placed = array_search('checkout.order_placed', $trail, true);
        $synced = array_search('checkout.treatments_synced', $trail, true);
        self::assertIsInt($placed, 'the order reached the local trail');
        self::assertIsInt($synced, 'and so did the sync it triggers');
        self::assertGreaterThan($placed, $synced);
        self::assertSame(
            ['intake.intake_initiated', 'intake.intake_inprogress', 'intake.intake_completed'],
            array_values(array_filter($trail, static fn (string $name): bool => str_starts_with($name, 'intake.'))),
        );
        self::assertContains('checkout.visited', $trail, 'the funnel was opened before the order event updated it');

        // `[13.32]`: what a placed order leaves behind.
        $state = $this->state($app);
        self::assertSame(['34660'], $state->placedOrders);
        self::assertNull($state->promotion, '[13.17]: the code cannot bleed into the upsell flow');
        self::assertSame([], $state->acceptedBumps);
        self::assertSame([], $_SESSION['cart']['lines'], 'the cart is empty');

        // `[4.15]`-`[4.17]`, `[17.7]`: the receipt ended the journey, so the
        // charge authority is gone with everything else the buyer typed —
        // while the receipt itself and the proof there was an order remain, or
        // a refresh would show them nothing and the guard would bounce them.
        $torn = (new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo)))->load(self::SESSION);

        self::assertNull($torn->reusableCredential(), 'the handle does not outlive the journey');
        self::assertSame([], $torn->buyer(), 'nor does the buyer contact');
        self::assertSame([], $torn->formAnswers, 'nor the clinical answers');
        self::assertTrue($torn->isComplete(), 'and the guard flag survives, so nothing re-fires');
        self::assertSame(['34660'], $torn->placedOrders, 'and the receipt stays reachable');
        self::assertNotNull($torn->receipt, 'and there is something on it');
    }

    public function testACartWithNoQuestionnaireGoesStraightToCheckoutAndCollectsEveryFieldItself(): void
    {
        // `[8.3]` and the router that honours it: a product declaring no
        // teleform earns no intake step, so the funnel has nothing to ask and
        // `[13.5g]` makes checkout collect the lot.
        $app = $this->app();
        $token = $this->land($app);

        $added = $this->post($app, '/cart/add/', [
            '_csrf' => $token, 'slug' => 'metabolic-panel', 'variant_id' => 'mp-1', 'quantity' => '1',
        ]);
        self::assertSame('/checkout/', $added->getHeaderLine('Location'), 'no questionnaire, no intake step');

        $checkout = $this->get($app, '/checkout/');
        $body = (string) $checkout->getBody();
        self::assertSame(200, $checkout->getStatusCode());
        self::assertSame([], $this->state($app)->formStatus, 'no form was opened, so none was answered');

        // Every field the questionnaire would have supplied is on the page and
        // empty, because there is nothing to prefill it from.
        foreach (['first_name', 'last_name', 'email', 'phone', 'address_line', 'city', 'postal_code'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $body);
        }
        self::assertStringNotContainsString('value="ada@example.test"', $body);

        $placed = $this->post($app, '/checkout/', $this->submission($token));

        self::assertSame(303, $placed->getStatusCode());
        self::assertSame('/upsell/', $placed->getHeaderLine('Location'));
        self::assertSame('metabolic-panel', $this->row('SELECT * FROM orders')['anchor_slug']);
    }

    public function testAProviderDiscountLargerThanTheCartIsRefusedRatherThanRenderedAsPayNothing(): void
    {
        // The recorded calculation returns $39.00 against a $25.00 cart. The
        // clamp in `Totals` (`[13.12]`) would cap the *display* at −$25.00 and
        // $0.00 — but it cannot reach the charge, because the provider payload
        // carries no order total: $25.00 of real line prices would go out with
        // the code attached and the provider's own arithmetic would decide what
        // is taken. A quote bigger than the cart is also the signature of the
        // two sides pricing different carts, and it fails silently. So the code
        // is refused and the buyer is left looking at the price that will
        // really be charged.
        $app = $this->app(['vrio-discount-calculated.json']);
        $token = $this->land($app);
        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'multivitamin', 'variant_id' => 'mv-1', 'quantity' => '1']);

        $this->post($app, '/checkout/promo/', ['_csrf' => $token, 'discount_code' => 'NEW10'] + $this->submittedFields());
        $body = (string) $this->get($app, '/checkout/')->getBody();

        self::assertStringNotContainsString('−$39.00', $body);
        self::assertStringNotContainsString('−$25.00', $body, 'no discount was applied at all');
        self::assertStringNotContainsString('>$0.00</span>', $body, 'the page never says "Pay $0.00" while real line prices go out');
        self::assertStringContainsString('>$25.00</span>', $body, 'the full price, which is what the provider will charge');
        self::assertNotSame([], $this->log->eventsNamed('checkout.promotion_exceeds_cart'));
    }

    // ------------------------------------------- the paths that stop the buyer

    public function testADeclinedCardKeepsTheBuyerOnCheckoutWithTheCartIntact(): void
    {
        // `[20.2]`, `[13.28]`, `[13.30]`.
        $app = $this->app(['vrio-order-declined.json']);
        $token = $this->walkToCheckout($app);

        $response = $this->post($app, '/checkout/', $this->submission($token));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Failed test transaction', $body, 'the provider’s own reason, verbatim');
        self::assertStringContainsString('Tirzepatide', $body, 'the cart is still on the page');
        self::assertStringContainsString('value="ada@example.test"', $body, 'and the form comes back filled');
        self::assertSame([], $this->rows('SELECT * FROM orders'), 'nothing was recorded as bought');
        self::assertSame([], $this->state($app)->placedOrders);
        self::assertNotSame([], $_SESSION['cart']['lines']);
    }

    public function testADeclineIsNotErasedFromTheEmrFunnelByTheReRenderThatFollowsIt(): void
    {
        // The EMR keeps one checkout record per session and updates `event` in
        // place, and the submit answers a decline by re-rendering the page --
        // which used to open the funnel a second time and overwrite the
        // decline with a visit. The funnel therefore reported zero declines,
        // ever, and the local trail being correct is why nothing caught it.
        $app = $this->app(['vrio-order-declined.json']);
        $token = $this->walkToCheckout($app);

        $this->post($app, '/checkout/', $this->submission($token));

        $funnel = array_values(array_filter(
            $this->emrPaths(),
            static fn (string $path): bool => str_contains($path, '/checkout-events/'),
        ));

        self::assertNotSame([], $funnel);
        self::assertStringEndsWith('/checkout-events/create', $funnel[0], 'the record is opened first');
        self::assertCount(
            1,
            array_filter($funnel, static fn (string $path): bool => str_ends_with($path, '/create')),
            'and opened exactly once, however many times the page renders',
        );
        self::assertStringContainsString(
            '/checkout-events/update/',
            (string) end($funnel),
            'so the decline is the last thing the EMR was told',
        );
    }

    public function testADisqualifiedJourneyCannotReachCheckoutAtAll(): void
    {
        // `[10.45]`, `[10.46]`: the hard stop outranks every other routing
        // consideration, on the GET and on the POST alike.
        $app = $this->app();
        $token = $this->land($app);
        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1']);
        $this->get($app, '/intake/medical/');

        $save = $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => '0', 'pregnancy_status' => 'yes'] + self::ANSWERS);
        self::assertSame('/not-eligible/', $save->getHeaderLine('Location'));

        $checkout = $this->get($app, '/checkout/');
        self::assertSame(302, $checkout->getStatusCode());
        self::assertSame('/not-eligible/', $checkout->getHeaderLine('Location'));

        $submit = $this->post($app, '/checkout/', $this->submission($token));
        self::assertSame(302, $submit->getStatusCode());
        self::assertSame('/not-eligible/', $submit->getHeaderLine('Location'));
        self::assertSame([], $this->transport->requests, 'nothing reached the provider');

        self::assertStringContainsString(self::ALERT_TEXT, (string) $this->get($app, '/not-eligible/')->getBody());
    }

    public function testAnRxLineWithNoChosenPlanCannotReachCheckout(): void
    {
        // `[12.7]`: an unpriced prescription cannot be ordered, and the
        // routing decision sends the visitor home rather than deeper into a
        // funnel this cart still fails.
        $app = $this->app();
        $token = $this->land($app);

        $added = $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'tirzepatide', 'quantity' => '1']);
        self::assertSame('/', $added->getHeaderLine('Location'));

        $checkout = $this->get($app, '/checkout/');
        self::assertSame(302, $checkout->getStatusCode());
        self::assertSame('/', $checkout->getHeaderLine('Location'));

        $submit = $this->post($app, '/checkout/', $this->submission($token));
        self::assertSame(302, $submit->getStatusCode());
        self::assertSame([], $this->transport->requests);
    }

    public function testAnUngrantedBlockingConsentStopsTheOrder(): void
    {
        // `[26.5]`: one of three deliberate exceptions to degrade-silently.
        $app = $this->app();
        $token = $this->walkToCheckout($app);

        $response = $this->post($app, '/checkout/', $this->submission($token, consents: []));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="checkout-error-terms"', $body);
        self::assertStringContainsString(CheckoutService::CONSENT_REQUIRED, $body);
        self::assertSame([], $this->transport->requests, 'the provider was never called');
        self::assertSame([], $this->rows('SELECT * FROM orders'));
    }

    public function testAGeoBlockedTerritoryStopsTheOrderAndNamesTheProduct(): void
    {
        // `[13.6]`, `[13.7]`: the gate is re-run against the territory that
        // was just submitted, the message names the product, and nothing is
        // removed from the cart.
        $app = $this->app();
        $token = $this->walkToCheckout($app);

        $response = $this->post($app, '/checkout/', $this->submission($token, territory: 'NY', postalCode: '10118'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Sharps Bin', $body, 'the blocked product is named');
        self::assertStringContainsString('Tirzepatide', $body, 'and the cart is intact');
        self::assertSame([], $this->transport->requests);
        self::assertSame([], $this->rows('SELECT * FROM orders'));
    }

    // ----------------------------------------- the two branch-wide invariants

    public function testNoCardNumberAppearsAnywhereInTheDatabaseAfterAFullCheckout(): void
    {
        // The single most consequential assertion in this suite. Every table,
        // every column, one substring search — preceded by the proof that the
        // number being searched for is genuinely the one that was paid with,
        // so this cannot pass by looking for a string that was never in play.
        $this->walkAFullCheckout();

        self::assertSame(self::CARD, $this->transport->body(0)['card_number'] ?? null, 'this card really did reach the provider');
        self::assertSame('4444', $this->row('SELECT * FROM orders')['card_last_four']);

        foreach (['sessions', 'orders', 'order_lines', 'order_consents', 'events', 'checkout_attempts'] as $table) {
            $rows = $this->rows('SELECT * FROM ' . $table);
            self::assertNotSame([], $rows, sprintf('the %s table has nothing in it to search', $table));

            foreach ($rows as $row) {
                self::assertStringNotContainsString(
                    self::CARD,
                    json_encode($row, JSON_THROW_ON_ERROR),
                    sprintf('a card number reached the %s table', $table),
                );
            }
        }
    }

    public function testNoCardNumberAppearsInTheOperatorLogAfterADeclinedCheckout(): void
    {
        // A decline logs the provider response in full (`[13.29]`), which is
        // the most likely place for a card to leak. Asserted against the real
        // logger's own file, so the redaction pass that ships is the one under
        // test — a recording double would prove nothing here.
        $this->walkACheckoutThatDeclines();

        self::assertSame(self::DECLINED_CARD, $this->transport->body(0)['card_number'] ?? null, 'this card really did reach the provider');
        self::assertNotSame([], $this->log->eventsNamed('payment.not_placed'), 'the response really was logged in full');

        self::assertStringNotContainsString(self::DECLINED_CARD, $this->log->contents());
        self::assertStringNotContainsString('411122223333', $this->log->contents(), 'nor any leading fragment of it');
    }

    // ---------------------------------------------------------------- the walk

    /** The happy path, up to and including a placed order. */
    private function walkAFullCheckout(): void
    {
        $app = $this->app();
        $token = $this->walkToCheckout($app);

        $placed = $this->post($app, '/checkout/', $this->submission($token));

        self::assertSame(303, $placed->getStatusCode(), 'the walk this assertion depends on really did place an order');
    }

    /** The same walk, ending at the provider's recorded decline. */
    private function walkACheckoutThatDeclines(): void
    {
        $app = $this->app(['vrio-order-declined.json']);
        $token = $this->walkToCheckout($app);

        $declined = $this->post($app, '/checkout/', $this->submission($token, card: self::DECLINED_CARD));

        self::assertSame(200, $declined->getStatusCode());
        self::assertStringContainsString('Failed test transaction', (string) $declined->getBody());
    }

    /** Landing → prescription → questionnaire → checkout, returning the CSRF token. */
    private function walkToCheckout(App $app): string
    {
        $token = $this->land($app);
        $this->post($app, '/cart/add/', [
            '_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1',
        ]);
        $this->get($app, '/intake/medical/');
        $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => '0', 'pregnancy_status' => 'no'] + self::ANSWERS);
        $this->post($app, '/intake/submit/', ['_csrf' => $token]);

        self::assertSame(200, $this->get($app, '/checkout/')->getStatusCode(), 'the walk reached checkout');

        return $token;
    }

    /** The landing page, which mints the CSRF token every later post carries. */
    private function land(App $app): string
    {
        self::assertSame(200, $this->get($app, '/')->getStatusCode());

        return (string) $_SESSION['_csrf'];
    }

    // --------------------------------------------------------------- fixtures

    /**
     * The whole application with a throwaway database, the recorded provider
     * responses queued in the order the walk consumes them, and the three
     * overrides every form-rendering test needs.
     *
     * The order recorder and the event reporter are the real ones. They are
     * what most of this file's assertions read, and a null in either place
     * would leave the walk proving only that nothing threw.
     *
     * @param list<string>         $fixtures provider responses, consumed in order
     * @param array<string, mixed> $bumps    `config/cross-sells.php`'s own shape
     */
    private function app(
        array $fixtures = ['vrio-order-approved.json'],
        array $bumps = ['max_on_page' => 3, 'bumps' => []],
    ): App {
        foreach ($fixtures as $fixture) {
            $this->transport->queueFixture($fixture);
        }

        $this->emr = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => [200, [
                'success' => true,
                'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
            ]],
            '/checkout-events/' => [200, ['success' => true, 'data' => ['_id' => '6a8b39941076171b7c0e2f2c']]],
            '/treatments/sync' => [200, ['success' => true, 'data' => ['reference' => 'trt-1']]],
        ]);

        $transport = $this->transport;
        $emr = $this->emr;
        $emrRoot = $this->emrRoot;
        $log = $this->log->log;

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo = $this->tempPdo(),
            OperatorLog::class => $log,
            // The session the fake gateway already knows about, so the journey
            // keeps one identifier for the whole walk instead of being
            // re-minted on every page view.
            SessionGateway::class => new FakeSessionGateway(
                [self::SESSION => ['opportunity_id' => null, 'events' => []]],
                'mint-never-needed-here',
            ),
            TeleformGateway::class => self::teleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 0),
            ProductCatalog::class => self::catalog(),
            PaymentAdapter::class => static fn (): PaymentAdapter => new VrioAdapter(
                new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
                new VrioApiFactory($transport),
                $log,
                shippingProfileId: 1,
            ),
            OrderBumps::class => static fn (Container $c): OrderBumps => OrderBumps::fromConfig(
                $bumps,
                $c->get(ProductCatalog::class),
                $c->get(OperatorLog::class),
            ),
            // Reporting to the EMR is off under `APP_ENV=test`, so it is
            // switched back on here with a fake transport underneath: the
            // create/update pair and the treatment sync are half of what a
            // full-funnel test is for.
            CheckoutEventReporter::class => static fn (Container $c): CheckoutEventReporter => new EmrCheckoutEventReporter(
                new ClientFactory($c->get(Config::class), $emrRoot),
                $c->get(EventRepository::class),
                $c->get(OrderRepository::class),
                $c->get(OperatorLog::class),
                static fn (): ?string => $c->get(JourneyStore::class)->state()?->attribution?->get('utm_source'),
                'USD',
                true,
                $emr,
                static fn (): ?string => 'Mozilla/5.0 (test)',
            ),
        ]);
    }

    /**
     * One prescription with a questionnaire and a free attachment New York
     * does not allow, one product with no questionnaire at all, a cheap one
     * for the clamp, and the bump.
     */
    private static function catalog(): ProductCatalog
    {
        return new FakeCatalog([
            'tirzepatide' => [
                'slug' => 'tirzepatide',
                'name' => 'Tirzepatide',
                'kind' => 'rx',
                'teleform_id' => self::TELEFORM,
                'attachments' => ['sharps-bin'],
                'variants' => [
                    ['id' => 't-1m', 'name' => '1 Month', 'price_cents' => 14000, 'provider' => ['offer_id' => '337', 'product_id' => '3414']],
                    ['id' => 't-3m', 'name' => '3 Months', 'price_cents' => 12000, 'provider' => ['offer_id' => '337', 'product_id' => '3415']],
                ],
            ],
            'sharps-bin' => [
                'slug' => 'sharps-bin',
                'name' => 'Sharps Bin',
                'kind' => 'free-addon',
                'geo_blocks' => ['NY'],
                'variants' => [],
            ],
            'pill-organizer' => [
                'slug' => 'pill-organizer',
                'name' => 'Pill Organizer',
                'kind' => 'otc',
                'price_cents' => 499,
                'variants' => [['id' => 'po-1', 'name' => 'One', 'price_cents' => 499, 'provider' => ['offer_id' => '338', 'product_id' => '3500']]],
            ],
            'metabolic-panel' => [
                'slug' => 'metabolic-panel',
                'name' => 'Metabolic Panel',
                'kind' => 'otc',
                'price_cents' => 9900,
                'variants' => [['id' => 'mp-1', 'name' => 'One panel', 'price_cents' => 9900, 'provider' => ['offer_id' => '338', 'product_id' => '3501']]],
            ],
            'multivitamin' => [
                'slug' => 'multivitamin',
                'name' => 'Daily Multivitamin',
                'kind' => 'otc',
                'price_cents' => 2500,
                'variants' => [['id' => 'mv-1', 'name' => 'One bottle', 'price_cents' => 2500, 'provider' => ['offer_id' => '339', 'product_id' => '3600']]],
            ],
        ]);
    }

    /**
     * A one-page questionnaire that asks for exactly the contact details
     * checkout wants, mapped to the record paths {@see \AsterMD\Storefront\Checkout\Prefill}
     * reads backwards, plus the one `danger` alert the hard-stop path turns on.
     */
    private static function teleformGateway(): TeleformGateway
    {
        $definition = ['formId' => self::TELEFORM, 'pages' => [
            ['pageId' => 'page_1', 'order' => 0, 'fields' => [
                ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
                ['fieldId' => 'last_name', 'name' => 'last_name', 'type' => 'text', 'label' => 'Last name', 'required' => true],
                ['fieldId' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email address', 'required' => true],
                ['fieldId' => 'phone', 'name' => 'phone', 'type' => 'phone', 'label' => 'Phone number', 'required' => true],
                ['fieldId' => 'street', 'name' => 'street', 'type' => 'text', 'label' => 'Street address', 'required' => true],
                ['fieldId' => 'city', 'name' => 'city', 'type' => 'text', 'label' => 'City', 'required' => true],
                ['fieldId' => 'state', 'name' => 'state', 'type' => 'text', 'label' => 'State', 'required' => true],
                ['fieldId' => 'zip', 'name' => 'zip', 'type' => 'text', 'label' => 'ZIP code', 'required' => true],
                [
                    'fieldId' => 'pregnancy_status', 'name' => 'pregnancy_status', 'type' => 'choice-single',
                    'label' => 'Are you pregnant or planning a pregnancy?', 'required' => true,
                    'properties' => ['options' => [
                        ['label' => 'Yes', 'value' => 'yes'],
                        ['label' => 'No', 'value' => 'no'],
                    ]],
                ],
                [
                    'fieldId' => 'pregnancy_hard_stop', 'name' => 'pregnancy_hard_stop', 'type' => 'alert',
                    'label' => 'Pregnancy notice',
                    'properties' => ['alertType' => 'danger', 'alertText' => self::ALERT_TEXT],
                    'conditions' => [[
                        'conditionId' => 'cond_pregnancy_hard_stop', 'action' => 'show', 'logic' => 'and',
                        'rules' => [['field' => 'pregnancy_status', 'operator' => 'equals', 'value' => 'yes']],
                    ]],
                ],
            ]],
        ]];

        return new class ($definition) implements TeleformGateway {
            /** @param array<string, mixed> $definition */
            public function __construct(private readonly array $definition)
            {
            }

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'tests/checkout-funnel.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: [
                        'first_name' => 'opportunity.first_name',
                        'last_name' => 'opportunity.last_name',
                        'email' => 'opportunity.email',
                        'phone' => 'opportunity.phone',
                        'street' => 'opportunity.address.line1',
                        'city' => 'opportunity.address.city',
                        'state' => 'opportunity.address.state',
                        'zip' => 'opportunity.address.postal_code',
                    ],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return $this->definition;
            }
        };
    }

    /** @return array<string, mixed> */
    private static function bumpConfig(): array
    {
        return ['max_on_page' => 3, 'bumps' => ['tirzepatide' => [[
            'key' => 'pill-organizer',
            'slug' => 'pill-organizer',
            'headline' => 'Add a pill organizer',
            'body' => 'Keeps a week of doses straight.',
            'position' => 1,
            'price_cents_override' => 99,
        ]]]];
    }

    /**
     * The contact half of the checkout form, exactly as the buyer's own intake
     * answers would have filled it.
     *
     * @return array<string, string>
     */
    private function submittedFields(string $territory = 'CA', string $postalCode = '94105'): array
    {
        return [
            'first_name' => self::ANSWERS['first_name'],
            'last_name' => self::ANSWERS['last_name'],
            'email' => self::ANSWERS['email'],
            'phone' => self::ANSWERS['phone'],
            'address_line' => self::ANSWERS['street'],
            'city' => self::ANSWERS['city'],
            'territory' => $territory,
            'postal_code' => $postalCode,
        ];
    }

    /**
     * A complete, payable submission.
     *
     * @param  array<string, string>|null $consents
     * @return array<string, mixed>
     */
    private function submission(
        string $csrf,
        string $card = self::CARD,
        ?array $consents = ['terms' => 'on', 'transactional_sms' => 'on'],
        string $territory = 'CA',
        string $postalCode = '94105',
    ): array {
        return [
            '_csrf' => $csrf,
            'card_number' => $card,
            'card_expiry' => '12 / 30',
            'card_cvc' => '123',
            'consents' => $consents ?? [],
        ] + $this->submittedFields($territory, $postalCode);
    }

    // -------------------------------------------------------------- assertions

    /**
     * Where in $paths the first entry ending in $suffix is, or null.
     *
     * The SDK prefixes every resource path with its service and version, so
     * the endpoint names above are matched as suffixes: pinning the prefix
     * here would make this file fail on an SDK upgrade that has nothing to do
     * with the funnel.
     *
     * @param list<string> $paths
     */
    private static function firstIndexEndingWith(array $paths, string $suffix): ?int
    {
        foreach ($paths as $index => $path) {
            if (str_ends_with($path, $suffix)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Every EMR path the reporter actually called, token exchanges excluded,
     * in order.
     *
     * @return list<string>
     */
    private function emrPaths(): array
    {
        $paths = [];

        foreach ($this->emr->requests as $request) {
            $path = $request->getUri()->getPath();
            if (!str_contains($path, '/auth/api-credentials/token')) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $rows = $this->rows($sql);
        self::assertCount(1, $rows, sprintf('expected exactly one row from: %s', $sql));

        return $rows[0];
    }

    private function state(App $app): JourneyState
    {
        $state = $app->getContainer()?->get(JourneyStore::class)->state();
        self::assertInstanceOf(JourneyState::class, $state);

        return $state;
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle($this->request('GET', $path));
    }

    /** @param array<string, mixed> $body */
    private function post(App $app, string $path, array $body): ResponseInterface
    {
        return $app->handle($this->request('POST', $path)->withParsedBody($body));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams(['amd_session' => self::SESSION])
            // A client address of this file's own, so the flood guard's
            // per-address counters cannot be shared with another test file.
            ->withHeader('X-Forwarded-For', '198.51.100.11');
    }
}

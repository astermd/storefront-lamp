<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\EmrCheckoutEventReporter;
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
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Upsell\Upsells;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * One buyer walked from the landing page to the receipt *through* the upsell
 * queue, and then every way that second half of the walk is allowed to end
 * badly.
 *
 * {@see CheckoutFunnelTest} walks the same funnel with no upsell configured, so
 * its walk stops the moment the order is placed: `config/upsells.php` ships
 * empty, the queue built at checkout is empty, and `/upsell/` redirects
 * straight to the receipt (`[16.6]`). This file configures a layer with two
 * offers in it and walks the part that only exists once one is configured — the
 * queue built at checkout from what was bought, the offer, the charge against
 * the handle the checkout placement returned, the second offer, the answer to
 * it, and the completion step the receipt triggers.
 *
 * What it covers is the seam, not the pieces. {@see \AsterMD\Storefront\Tests\Upsell\UpsellServiceTest}
 * proves the service against arranged state and {@see UpsellPageTest} proves
 * the template; neither can show that the queue a real checkout writes is the
 * queue a later request reads, that the handle survives the round trip through
 * the `sessions` row, or that the completion batch names the upsell as well as
 * the prescription.
 *
 * **Every unhappy case here is a redirect and never a stop.** By the time the
 * offer page is reached the buyer has already paid for the thing they came for,
 * so `[16.11]` makes every failure of an optional add-on a reason to move them
 * on rather than to hold them. That is asserted as a 303 plus a reached receipt
 * in each case, because a 500 in front of someone who has just been charged is
 * the outcome the whole boundary exists to prevent.
 *
 * Because it is the only walk that reaches every step of the funnel, it also
 * carries the two claims that can only be made about a journey as a whole:
 * the local audit trail a complete journey writes, in order, against §18's
 * table, and the crawler surface of every page that journey rendered. Both
 * are asserted from the same walk, so neither can be satisfied by a page the
 * funnel no longer goes through.
 *
 * The payment adapter is the real one over {@see FakeVrioTransport}, the order
 * recorder and the EMR event reporter are the real ones over
 * {@see FakeEmrHttpClient}. Nothing here opens a socket, and nothing here
 * places a live order.
 *
 * **The recordings decide how the failures are produced.** A card-on-file
 * charge cannot be made to decline against the live sandbox — the vaulted card
 * that declined as a raw PAN approves as a vault handle, because the test
 * gateway decides on the submitted PAN and a vault charge submits none — so the
 * declined-upsell case is the recorded decline canned, and the reachable
 * failure of a stale handle is the recorded `customer_card_invalid` refusal.
 * Expecting the sandbox's own behaviour to differ by request shape would give a
 * case that passed for the wrong reason.
 */
final class FullFunnelUpsellTest extends TestCase
{
    use ConfigVariant;
    use TempDatabase;

    /** The analytics session the fake gateway already knows, so nothing is re-minted mid-walk. */
    private const string SESSION = 'sess-upsell-0123456789ab';

    /**
     * What the gateway mints for a browser that arrives carrying no cookie.
     *
     * Every other case here starts with {@see self::SESSION} already in hand,
     * which is how a mid-journey request looks and is why none of them can see
     * the first event of a journey: the session is only *created* on a request
     * that had no cookie to resolve.
     */
    private const string MINTED_SESSION = 'mint-never-needed-here';

    private const string TELEFORM = '6a8aec0dec745c70f5f3e69a';

    private const string CARD = '4111111100084444';

    /** The origin `config/app.php` names, which every canonical and sitemap entry is built on. */
    private const string BASE = 'http://localhost:8080';

    /** The one catalog page the walk passes through. */
    private const string PRODUCT_PATH = '/products/tirzepatide/';

    /**
     * The pages of the walk a crawler is welcome on. Everything else the walk
     * renders is a funnel step, and `[24.6]` keeps every one of them out.
     */
    private const array MARKETING_PATHS = ['/', self::PRODUCT_PATH];

    /** The prescription order the recorded approval places, and the two upsell orders. */
    private const string CHECKOUT_REFERENCE = '34660';
    private const string ACCEPTED_UPSELL_REFERENCE = '34790';

    /** The two configured offers, in configuration order — which is queue order (`[16.2]`). */
    private const string FIRST_OFFER = 'wellness-pack';
    private const string SECOND_OFFER = 'sleep-kit';

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
        $this->cacheDir = sys_get_temp_dir() . '/full-funnel-upsell-' . bin2hex(random_bytes(6));
        $this->emrRoot = sys_get_temp_dir() . '/full-funnel-upsell-emr-' . bin2hex(random_bytes(6));
        mkdir($this->emrRoot . '/storage/cache', 0775, true);
        $this->registerTempDirForCleanup($this->cacheDir);
        $this->registerTempDirForCleanup($this->emrRoot);

        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->pdo = $this->tempPdo();
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

    public function testAnAcceptedAndADeclinedUpsellAreWalkedThroughToTheReceipt(): void
    {
        $this->queueApprovedCheckout();
        $this->queueApprovedUpsell(self::ACCEPTED_UPSELL_REFERENCE);
        $app = $this->app();

        // ---- landing, questionnaire, checkout. The same hops
        // {@see CheckoutFunnelTest} walks, asserted only where this file's own
        // subject depends on them: the upsell step must be shut until an order
        // exists, and the queue must be empty until one is placed.
        $token = $this->land($app);
        $added = $this->post($app, '/cart/add/', [
            '_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1',
        ]);
        self::assertSame('/intake/medical/', $added->getHeaderLine('Location'));

        $tooEarly = $this->get($app, '/upsell/');
        self::assertSame(302, $tooEarly->getStatusCode(), '[16.16]: the offer is not reachable before there is an order');
        self::assertSame('/intake/medical/', $tooEarly->getHeaderLine('Location'), 'and the visitor is sent where they were going');
        self::assertSame([], $this->state($app)->upsellQueue, 'the queue is built at checkout, not at cart time');

        self::assertSame(200, $this->get($app, '/intake/medical/')->getStatusCode());
        $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => '0', 'pregnancy_status' => 'no'] + self::ANSWERS);
        $submitted = $this->post($app, '/intake/submit/', ['_csrf' => $token]);
        self::assertSame('/checkout/', $submitted->getHeaderLine('Location'));
        self::assertSame(200, $this->get($app, '/checkout/')->getStatusCode());

        // ---- the order. `[16.1]`, `[16.2]`: the queue is built once, here,
        // from what was bought, in configuration order.
        $placed = $this->post($app, '/checkout/', $this->submission($token));
        self::assertSame(303, $placed->getStatusCode());
        self::assertSame('/upsell/', $placed->getHeaderLine('Location'));

        $state = $this->state($app);
        self::assertSame([self::CHECKOUT_REFERENCE], $state->placedOrders);
        self::assertSame([self::FIRST_OFFER, self::SECOND_OFFER], $state->upsellQueue);
        self::assertSame(0, $state->upsellCursor);
        self::assertSame([], $state->upsellOutcomes, 'nothing has been answered yet');

        // `[15.12]`, `[15.13]`: read out of the `sessions` row rather than off
        // the object that wrote it, because the upsell charge is a *later
        // request* and an in-process read would pass whether or not anything
        // durable was written.
        self::assertSame(
            ['customer_id' => '13957', 'customer_card_id' => '16764'],
            $this->durableState()->reusableCredential()?->handle,
        );

        // ---- the first offer, and the charge that answers it.
        $offer = $this->get($app, '/upsell/');
        self::assertSame(200, $offer->getStatusCode());
        self::assertStringContainsString('Round Out Your Routine', (string) $offer->getBody());
        // Being shown an offer is recorded — `[16.7]` reports it once per
        // upsell, and the record of having reported it is what makes a refresh
        // silent — but it is not an *answer*, so the cursor has not moved.
        self::assertSame(0, $this->state($app)->upsellCursor);
        self::assertSame(
            [self::FIRST_OFFER => JourneyState::UPSELL_OFFERED],
            $this->state($app)->upsellOutcomes,
        );

        $accepted = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);
        self::assertSame(303, $accepted->getStatusCode());
        self::assertSame('/upsell/', $accepted->getHeaderLine('Location'), 'one more offer is queued');

        $state = $this->state($app);
        self::assertSame([self::CHECKOUT_REFERENCE, self::ACCEPTED_UPSELL_REFERENCE], $state->placedOrders);
        self::assertSame(1, $state->upsellCursor);
        self::assertSame(JourneyState::UPSELL_ACCEPTED, $state->upsellOutcomes[self::FIRST_OFFER]);

        // `[16.8]`: charged against the handle, with no card field riding along.
        $charge = $this->transport->body(1);
        self::assertSame('13957', (string) ($charge['customer_id'] ?? ''));
        self::assertSame('16764', (string) ($charge['customer_card_id'] ?? ''));
        self::assertArrayNotHasKey('card_number', $charge);
        self::assertSame('8.99', $charge['offers'][0]['order_offer_price'] ?? null, 'the figure the page showed');

        // `[16.12]`: the add-on has its own local row, marked as an upsell.
        $upsellRow = $this->row('SELECT * FROM orders WHERE provider_reference = \'' . self::ACCEPTED_UPSELL_REFERENCE . '\'');
        self::assertSame(1, (int) $upsellRow['is_upsell']);
        self::assertSame(899, (int) $upsellRow['amount_cents']);
        self::assertSame(0, (int) $this->row('SELECT * FROM orders WHERE provider_reference = \'' . self::CHECKOUT_REFERENCE . '\'')['is_upsell']);

        // ---- the second offer, declined by the buyer.
        $second = $this->get($app, '/upsell/');
        self::assertSame(200, $second->getStatusCode());
        self::assertStringContainsString('A Kit For Better Nights', (string) $second->getBody());

        $declined = $this->post($app, '/upsell/decline/', ['_csrf' => $token]);
        self::assertSame(303, $declined->getStatusCode());
        self::assertSame('/thank-you/', $declined->getHeaderLine('Location'), 'the queue is spent');

        $state = $this->state($app);
        self::assertSame(2, $state->upsellCursor);
        self::assertSame([
            self::FIRST_OFFER => JourneyState::UPSELL_ACCEPTED,
            self::SECOND_OFFER => JourneyState::UPSELL_DECLINED,
        ], $state->upsellOutcomes);
        self::assertCount(2, $this->transport->requests, 'a decline charges nothing');

        // Read before the receipt, because reaching it is what tears the
        // journey down and the buyer's contact is one of the things that does
        // not survive (`[4.16]`).
        self::assertSame('ada@example.test', $this->durableState()->buyer()['email'] ?? null);

        // ---- the receipt, which is what completes the journey.
        $receipt = $this->get($app, '/thank-you/');
        $body = (string) $receipt->getBody();

        self::assertSame(200, $receipt->getStatusCode());
        self::assertStringContainsString('Order #' . self::CHECKOUT_REFERENCE, $body);
        self::assertStringContainsString('Thank you, <span id="thankyou-name">Ada</span>!', $body);
        self::assertStringContainsString('Tirzepatide', $body, 'the prescription is on the receipt');
        self::assertStringContainsString('Wellness Pack', $body, 'and so is the add-on that was charged for');
        self::assertStringNotContainsString('Sleep Kit', $body, 'the one they declined is not');

        // `[17.5]`: one final funnel event, and only one.
        self::assertSame(
            1,
            count($this->eventsNamed('checkout.completed')),
            'the completion actions fire exactly once',
        );

        // `[17.3]`: the batched sync names every reference this journey placed,
        // so the clinician's record accumulates the add-on rather than losing
        // it. The checkout-time sync of the prescription alone happens too and
        // is deliberately kept — the EMR keys treatments by session, so a
        // repeat is a no-op and a batch upgrades the same record in place —
        // which is what lets a buyer who closes the browser mid-upsell still
        // have their prescription in the EMR.
        self::assertSame(
            [[self::CHECKOUT_REFERENCE], [self::CHECKOUT_REFERENCE, self::ACCEPTED_UPSELL_REFERENCE]],
            $this->treatmentSyncBatches(),
        );

        // `[4.15]`–`[4.17]`, `[17.7]`: the journey is gone and the receipt is not.
        $torn = $this->durableState();
        self::assertNull($torn->reusableCredential(), 'the charge authority does not outlive the journey');
        self::assertSame([], $torn->buyer());
        self::assertSame([], $torn->formAnswers);
        self::assertTrue($torn->isComplete());
        self::assertSame([self::CHECKOUT_REFERENCE, self::ACCEPTED_UPSELL_REFERENCE], $torn->placedOrders);
        self::assertNotNull($torn->receipt);

        // A reload is the same page and fires nothing further.
        $reload = $this->get($app, '/thank-you/');
        self::assertSame(200, $reload->getStatusCode());
        self::assertStringContainsString('Order #' . self::CHECKOUT_REFERENCE, (string) $reload->getBody());
        self::assertSame(1, count($this->eventsNamed('checkout.completed')));
        self::assertCount(2, $this->treatmentSyncBatches(), 'no third sync');
    }

    // ------------------------------- the whole funnel, as one durable record

    /**
     * §18's table, walked: what a complete journey actually wrote to the local
     * `events` table, in the order it wrote it.
     *
     * The taxonomy is a table of triggers and cardinalities, and every row of
     * it is enforced somewhere different — the session in
     * {@see \AsterMD\Storefront\Journey\SessionResolver}, the cart in
     * {@see \AsterMD\Storefront\Emr\CartMirror}, the questionnaire in
     * {@see \AsterMD\Storefront\Forms\RecordingIntakeGateway}, everything from
     * the checkout onward in
     * {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}. Each of
     * those has its own test proving its own rows against arranged state. What
     * none of them can show is the *stream*: that four writers with no
     * knowledge of each other produce one legible account of one journey, that
     * the fire-once rows fire once across the whole walk rather than once per
     * collaborator, and that the order the rows land in is the order the
     * journey happened in. `[18.4]` made this table the storefront's forensic
     * record; a record that is right line by line and wrong as a whole is not
     * one.
     *
     * The walk starts with **no cookie**, which is the only thing that makes
     * the taxonomy's first row reachable: a session is created on a request
     * that had none to resolve, so every case in this file that arrives with
     * {@see self::SESSION} in hand is already past it.
     *
     * Three of the table's rows are deliberately out of reach of one walk and
     * are proved elsewhere: the pre-qualification triple needs a deployment
     * that configures a second questionnaire, and the declined order is a
     * failed placement rather than a step of a journey that completes. The
     * list below is therefore what one *successful* journey writes, not the
     * whole vocabulary.
     */
    public function testACompleteJourneyWritesTheEventTaxonomyInOrder(): void
    {
        $this->queueApprovedCheckout();
        $this->queueApprovedUpsell(self::ACCEPTED_UPSELL_REFERENCE);

        $this->walkTheWholeFunnel($this->app(config: $this->funnelConfig()));

        self::assertSame(
            [
                // Once per session, on the request that had no cookie.
                'session_created',
                // The first cart mutation opens the record; the two plan
                // changes after it update the same one (`[18.2]`).
                'cart.created',
                'cart.updated',
                'cart.updated',
                // Initiated once on the first render, in-progress per page
                // saved, completed on the final submit.
                'intake.intake_initiated',
                'intake.intake_inprogress',
                'intake.intake_completed',
                // Once per journey however many times the page is opened.
                'checkout.visited',
                // The placement, and the sync it triggers from inside itself —
                // which is why the sync can never precede it.
                'checkout.order_placed',
                'checkout.treatments_synced',
                // One offered/answered pair per queued upsell, in queue order.
                'checkout.upsell_offered',
                'checkout.upsell_accepted',
                'checkout.upsell_offered',
                'checkout.upsell_declined',
                // Once per checkout, behind the completion guard, with the
                // batched sync that names every reference the journey placed.
                'checkout.completed',
                'checkout.treatments_synced',
            ],
            $this->trail(),
        );

        // One journey, one file: the trail is keyed by the analytics session,
        // and a row filed under another one is a row no investigation of this
        // journey would ever find.
        self::assertSame(
            [self::MINTED_SESSION],
            array_values(array_unique(array_column(
                $this->rows('SELECT session_uuid FROM events ORDER BY id'),
                'session_uuid',
            ))),
        );

        // The payload column, for the three rows whose value is what they
        // carry rather than that they happened.
        $created = $this->payload('cart.created');
        self::assertCount(1, $created['lines'], 'the cart row carries one entry per line');
        self::assertSame(1, $created['lines'][0]['qty'], 'the cart rows carry quantities');
        self::assertNotSame(
            '',
            (string) $created['lines'][0]['product_ref'],
            'the cart rows identify the product',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'tirzepatide',
            json_encode($created, JSON_THROW_ON_ERROR),
            '[20.14]: a durable row records that something happened, not which prescription it was — '
                . 'and a slug is the prescription, slugified',
        );

        self::assertSame(
            self::CHECKOUT_REFERENCE,
            $this->payload('checkout.order_placed')['references'],
            'the placement names the order it placed',
        );
        self::assertSame(
            self::CHECKOUT_REFERENCE . ',' . self::ACCEPTED_UPSELL_REFERENCE,
            $this->payload('checkout.completed')['references'],
            '[17.3]: the final row accounts for the add-on as well as the prescription',
        );
    }

    /**
     * `[24.6]` across the whole funnel, driven by the pages the buyer actually
     * walked through rather than by a list of prefixes.
     *
     * {@see \AsterMD\Storefront\Tests\Http\SitemapTest} and
     * {@see \AsterMD\Storefront\Tests\Seo\HeadMetadataTest} both assert this
     * rule, and both assert it against a list of funnel prefixes written down
     * in the test — which is the one thing that cannot catch a funnel step
     * nobody added to the list. This case enumerates the opposite side: the
     * two pages a crawler is *welcome* on, and then holds **every other page
     * the walk rendered** to the refusal, whatever it is and however it was
     * reached. A step that stopped being covered by a rule fails here without
     * anyone having to remember to add it.
     *
     * **Indexing is switched on for it.** Under the suite's own configuration
     * `[24.7]`'s master switch refuses everything, so a page that carries no
     * rule of its own would pass this anyway. With the switch off, the only
     * thing left refusing the funnel is the funnel's own policy, which is what
     * is under test.
     */
    public function testNoPageOfTheFunnelIsOfferedToACrawlerWhereIndexingIsAllowed(): void
    {
        $this->queueApprovedCheckout();
        $this->queueApprovedUpsell(self::ACCEPTED_UPSELL_REFERENCE);
        $config = $this->funnelConfig(indexable: true);
        $app = $this->app(config: $config);

        $rendered = $this->walkTheWholeFunnel($app);
        $advertised = self::locations((string) $this->get($app, '/sitemap.xml')->getBody());

        self::assertNotSame([], $advertised, 'the switch really is off, or this asserts nothing');

        foreach ($rendered as $path => $response) {
            $body = (string) $response->getBody();

            if (in_array($path, self::MARKETING_PATHS, true)) {
                // `[23.6]`, `[24.4]`: the pages a crawler is *wanted* on say
                // where they live and are advertised as living there.
                self::assertStringContainsString(
                    '<link rel="canonical" href="' . self::BASE . $path . '"',
                    $body,
                    $path . ' is indexable and must name its canonical URL',
                );
                self::assertStringNotContainsString('name="robots"', $body, $path . ' must not refuse indexing');
                self::assertContains(self::BASE . $path, $advertised, $path . ' must be advertised');

                continue;
            }

            self::assertStringContainsString(
                '<meta name="robots" content="noindex, nofollow" />',
                $body,
                $path . ' is a funnel step and must refuse indexing',
            );
            self::assertSame(
                'noindex, nofollow',
                $response->getHeaderLine('X-Robots-Tag'),
                '[24.2]: ' . $path . ' must refuse in the header as well as in the tag',
            );
            self::assertNotContains(self::BASE . $path, $advertised, $path . ' must not be advertised');
        }
    }

    /**
     * The funnel walked once, all the way, as a browser walks it.
     *
     * Every hop is a real request and every redirect is followed by hand, so
     * nothing here is reached by arranging the state that would have let it be
     * reached. The two plan changes in the middle are a buyer changing their
     * mind, which is the ordinary way a journey produces more than one cart
     * mutation: a prescription's quantity is fixed (`[7.16]`), so a quantity
     * post would be refused and write nothing.
     *
     * @return array<string, ResponseInterface> every page that rendered a
     *     document, keyed by the path it was fetched from
     */
    private function walkTheWholeFunnel(App $app): array
    {
        /** @var array<string, ResponseInterface> $rendered */
        $rendered = [];
        $visit = function (string $path) use ($app, &$rendered): ResponseInterface {
            $response = $this->get($app, $path, self::MINTED_SESSION);

            if ($response->getStatusCode() === 200) {
                $rendered[$path] = $response;
            }

            return $response;
        };
        $answer = fn (string $path, array $body): ResponseInterface => $this->post(
            $app,
            $path,
            $body,
            self::MINTED_SESSION,
        );

        // ---- the landing page, arrived at cold. No cookie, so this request
        // is the one that mints the session the rest of the walk carries.
        $landing = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/')
                ->withHeader('X-Forwarded-For', '198.51.100.19'),
        );
        self::assertSame(200, $landing->getStatusCode());
        self::assertStringContainsString(
            'amd_session=' . self::MINTED_SESSION . ';',
            $landing->getHeaderLine('Set-Cookie'),
            'the walk really did begin without a session',
        );
        $rendered['/'] = $landing;
        $token = (string) $_SESSION['_csrf'];

        // ---- the product page, which is browsing and writes nothing.
        self::assertSame(200, $visit(self::PRODUCT_PATH)->getStatusCode());

        // ---- the cart: one product, then two changes of plan.
        $added = $answer('/cart/add/', [
            '_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1',
        ]);
        self::assertSame('/intake/medical/', $added->getHeaderLine('Location'));
        $answer('/cart/add/', ['_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-1m', 'quantity' => '1']);
        $answer('/cart/add/', ['_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1']);

        // ---- the questionnaire.
        self::assertSame(200, $visit('/intake/medical/')->getStatusCode());
        $answer('/intake/save/', ['_csrf' => $token, 'page' => '0', 'pregnancy_status' => 'no'] + self::ANSWERS);
        self::assertSame('/checkout/', $answer('/intake/submit/', ['_csrf' => $token])->getHeaderLine('Location'));

        // ---- checkout, opened twice before it is paid: the visit is once per
        // journey, so a second look must add nothing.
        self::assertSame(200, $visit('/checkout/')->getStatusCode());
        self::assertSame(200, $visit('/checkout/')->getStatusCode());
        $placed = $answer('/checkout/', $this->submission($token));
        self::assertSame('/upsell/', $placed->getHeaderLine('Location'), 'the walk really did place an order');

        // ---- the offers, the first of them looked at twice for the same
        // reason: being shown an offer again is not being offered it again.
        self::assertSame(200, $visit('/upsell/')->getStatusCode());
        self::assertSame(200, $visit('/upsell/')->getStatusCode());
        $answer('/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);
        self::assertSame(200, $visit('/upsell/')->getStatusCode());
        self::assertSame(
            '/thank-you/',
            $answer('/upsell/decline/', ['_csrf' => $token])->getHeaderLine('Location'),
            'the queue is spent',
        );

        // ---- the receipt, reloaded: completion is fire-once for the whole
        // checkout, not once per view of the page it fires from.
        self::assertSame(200, $visit('/thank-you/')->getStatusCode());
        self::assertSame(200, $visit('/thank-you/')->getStatusCode());

        // Stated rather than left to whatever came back, because a caller that
        // judges the pages this returns would silently judge fewer of them if
        // a step started redirecting instead of rendering.
        self::assertSame(
            ['/', self::PRODUCT_PATH, '/intake/medical/', '/checkout/', '/upsell/', '/thank-you/'],
            array_keys($rendered),
            'every step of the walk drew a page',
        );

        return $rendered;
    }

    /**
     * The shipped configuration with this file's own catalog in it, because
     * the product page and the sitemap are both built from the concrete
     * {@see \AsterMD\Storefront\Catalog\CatalogProvider} rather than from the
     * {@see ProductCatalog} the rest of this file overrides — so a fake cannot
     * stand in for them, and the deployment's real generated file is whatever
     * the last sync wrote and is never the same catalog twice.
     *
     * The override layer is emptied along with it: the shipped one carries a
     * sample lab product, which would appear on the crawler surface and make
     * an assertion about what is advertised depend on a file this walk has
     * nothing to do with.
     */
    private function funnelConfig(bool $indexable = false): Config
    {
        /** @var array<string, mixed> $app */
        $app = require dirname(__DIR__, 2) . '/config/app.php';

        if ($indexable) {
            /** @var array<string, mixed> $seo */
            $seo = $app['seo'];
            $app['seo'] = ['discourage_indexing' => false] + $seo;
        }

        return $this->configWith([
            'app' => $app,
            'products.generated' => [
                'channel' => ['id' => 'test-channel', 'name' => 'Test', 'currency' => 'USD'],
                'products' => self::catalogProducts(),
            ],
            'products.overrides' => ['products' => []],
        ]);
    }

    /**
     * The local trail as a list of names, in the order the rows were written.
     *
     * @return list<string>
     */
    private function trail(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->rows('SELECT name FROM events ORDER BY id'),
        );
    }

    /**
     * The decoded payload of the one row by this name, which also asserts
     * there is exactly one of them.
     *
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode(
            (string) $this->row('SELECT * FROM events WHERE name = ' . $this->pdo->quote($name))['payload'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $payload;
    }

    /**
     * Every `<loc>` in the sitemap, read through an XML parser so a malformed
     * document fails here rather than silently matching nothing.
     *
     * @return list<string>
     */
    private static function locations(string $body): array
    {
        $xml = simplexml_load_string($body);
        self::assertNotFalse($xml, 'sitemap.xml is not well-formed XML');

        $locations = [];
        foreach ($xml->children('http://www.sitemaps.org/schemas/sitemap/0.9')->url as $url) {
            $locations[] = (string) $url->loc;
        }

        return $locations;
    }

    // ------------------------------------------- the ways the offer goes wrong

    public function testADeclinedUpsellChargeAdvancesTheQueueAndStillReachesTheReceipt(): void
    {
        // `[16.11]`. The recorded decline is canned rather than provoked: a
        // card-on-file charge approves in the sandbox whatever the instrument,
        // so a case that tried to produce this live would pass because the
        // queue advanced after a *success*.
        $this->queueApprovedCheckout();
        $this->transport->queueFixture('vrio-order-declined.json');
        $app = $this->app();
        $token = $this->walkToPlacedOrder($app);

        // Following the 303, the way a browser does. The render is what quotes
        // the offer, and an answer to an offer that was never quoted is refused.
        $this->get($app, '/upsell/');
        $answered = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertSame(303, $answered->getStatusCode());
        self::assertSame('/upsell/', $answered->getHeaderLine('Location'), 'forward, to the next offer');

        $state = $this->state($app);
        self::assertSame(JourneyState::UPSELL_CHARGE_DECLINED, $state->upsellOutcomes[self::FIRST_OFFER]);
        self::assertSame(1, $state->upsellCursor);
        self::assertSame([self::CHECKOUT_REFERENCE], $state->placedOrders, 'a declined add-on was never bought');

        $this->assertTheReceiptIsStillReached($app, $token);
    }

    public function testARefusedCredentialHandleAdvancesTheQueueAndGivesTheAttemptKeyBack(): void
    {
        // The reachable card-on-file failure, per the recordings: `success`
        // false, `customer_card_invalid`, no order id and no transaction node
        // at all. It charged nothing and created nothing, so the attempt key
        // has to go back rather than being held as money-may-be-missing.
        $this->queueApprovedCheckout();
        $this->queueRecordedRefusal();
        $app = $this->app();
        $token = $this->walkToPlacedOrder($app);

        // Following the 303, the way a browser does. The render is what quotes
        // the offer, and an answer to an offer that was never quoted is refused.
        $this->get($app, '/upsell/');
        $answered = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertSame(303, $answered->getStatusCode());
        self::assertSame('/upsell/', $answered->getHeaderLine('Location'));

        $state = $this->state($app);
        self::assertSame(JourneyState::UPSELL_CHARGE_DECLINED, $state->upsellOutcomes[self::FIRST_OFFER]);
        self::assertSame(1, $state->upsellCursor);
        self::assertSame([self::CHECKOUT_REFERENCE], $state->placedOrders);

        // The released key leaves no row. The checkout's own key is kept
        // forever, so counting rows is what says the upsell's went back —
        // asserting an empty table would assert the checkout had no key either.
        // The request count is asserted first, because a case where the charge
        // was never attempted would have one attempt row too.
        self::assertCount(2, $this->transport->requests, 'the charge really was attempted');
        self::assertCount(
            1,
            $this->rows('SELECT * FROM checkout_attempts'),
            'the upsell attempt key was released, and only the checkout’s remains',
        );
        self::assertSame([], $this->log->eventsNamed('upsell.attempt_unresolved'));

        $this->assertTheReceiptIsStillReached($app, $token);
    }

    public function testAPlacementThatReturnedNoHandleSkipsEveryOfferWithoutCallingTheProvider(): void
    {
        // `[15.13]`'s honest failure: the adapter minted no handle, so there is
        // nothing to charge against and every offer is skipped. The buyer is
        // never told — a capability their provider lacks is not their problem —
        // and no charge is attempted with no instrument, which is the one thing
        // that would place an unpaid order.
        $this->queueCheckoutWithNoHandle();
        $app = $this->app();
        $token = $this->walkToPlacedOrder($app);

        self::assertNull($this->durableState()->reusableCredential(), 'the arrangement this case needs really did happen');

        $first = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);
        self::assertSame(303, $first->getStatusCode());
        self::assertSame('/upsell/', $first->getHeaderLine('Location'));

        $second = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::SECOND_OFFER]);
        self::assertSame(303, $second->getStatusCode());
        self::assertSame('/thank-you/', $second->getHeaderLine('Location'));

        self::assertSame([
            self::FIRST_OFFER => JourneyState::UPSELL_SKIPPED,
            self::SECOND_OFFER => JourneyState::UPSELL_SKIPPED,
        ], $this->state($app)->upsellOutcomes);
        self::assertCount(1, $this->transport->requests, 'only the checkout ever reached the provider');
        self::assertNotSame([], $this->log->eventsNamed('upsell.no_credential'));

        self::assertSame(200, $this->get($app, '/thank-you/')->getStatusCode());
    }

    public function testAQueueWhoseConfigurationHasBeenEmptiedUnderItSkipsEveryEntry(): void
    {
        // `[16.4]`. The queue is durable journey state written at checkout, so
        // it outlives the configuration that produced it: a deployment that
        // removes an offer between the order and the offer page leaves a queue
        // naming keys that no longer resolve. That is an ordinary skip, and it
        // is modelled by handing the *same database* to a second application
        // with an empty layer — which is what the next request would see.
        $this->queueApprovedCheckout();
        $this->walkToPlacedOrder($this->app());

        self::assertSame([self::FIRST_OFFER, self::SECOND_OFFER], $this->durableState()->upsellQueue);

        $emptied = $this->app($this->layer([]));

        $offer = $this->get($emptied, '/upsell/');
        self::assertSame(303, $offer->getStatusCode(), 'nothing resolves, so there is nothing to render');
        self::assertSame('/thank-you/', $offer->getHeaderLine('Location'));

        self::assertSame([
            self::FIRST_OFFER => JourneyState::UPSELL_SKIPPED,
            self::SECOND_OFFER => JourneyState::UPSELL_SKIPPED,
        ], $this->state($emptied)->upsellOutcomes);
        self::assertCount(1, $this->transport->requests, 'no skipped offer was charged for');

        self::assertSame(200, $this->get($emptied, '/thank-you/')->getStatusCode());
    }

    public function testTheOfferPageIsUnreachableUntilAnOrderHasBeenPlaced(): void
    {
        // The `order_placed` precondition, which is the only guard this step
        // has: `[13.32]` clears the cart the moment an order is placed, so the
        // page cannot be guarded on the cart and the journey's own record of
        // what it bought is the fact left to test.
        $app = $this->app();
        $token = $this->land($app);
        $this->post($app, '/cart/add/', [
            '_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1',
        ]);

        $response = $this->get($app, '/upsell/');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/intake/medical/', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('Round Out Your Routine', (string) $response->getBody());
        self::assertSame([], $this->transport->requests);
    }

    public function testASecondVisitToTheReceiptFiresNothingAndRendersTheSamePage(): void
    {
        // `[17.1]`, `[4.15]`: fire-once is a durable flag rather than a
        // request-scoped one, so a refresh — or a buyer returning to a
        // bookmarked receipt — re-enters the completion step and finds it done.
        // The stored snapshot is why the second page can be the same as the
        // first, since by then the cart, the buyer and the handle are gone.
        $this->queueApprovedCheckout();
        $app = $this->app();
        $token = $this->walkToPlacedOrder($app);
        $this->post($app, '/upsell/decline/', ['_csrf' => $token]);
        $this->post($app, '/upsell/decline/', ['_csrf' => $token]);

        $first = (string) $this->get($app, '/thank-you/')->getBody();
        $eventsAfterFirst = $this->rows('SELECT name FROM events ORDER BY id');
        $syncsAfterFirst = $this->treatmentSyncBatches();

        $second = $this->get($app, '/thank-you/');

        self::assertSame(200, $second->getStatusCode());
        self::assertSame($first, (string) $second->getBody(), 'the same receipt, rebuilt from nothing');
        self::assertSame($eventsAfterFirst, $this->rows('SELECT name FROM events ORDER BY id'), 'no event was reported twice');
        self::assertSame($syncsAfterFirst, $this->treatmentSyncBatches(), 'and no treatment was synced twice');
    }

    public function testASecondAcceptPostChargesNothingFurther(): void
    {
        // The double submit a buyer actually makes: two clicks, or a
        // back-and-resubmit, on the last offer in the queue. The second post
        // finds a spent queue and answers with the receipt, so the add-on is
        // charged for once — which is the property that matters, and the one a
        // 303 alone does not give (the redirect stops a *reload* from
        // reposting, not a second click).
        $this->queueApprovedCheckout();
        $this->queueApprovedUpsell(self::ACCEPTED_UPSELL_REFERENCE);
        $app = $this->app($this->layer([self::FIRST_OFFER => self::firstOffer()]));
        $token = $this->walkToPlacedOrder($app, [self::FIRST_OFFER]);

        // Following the 303, the way a browser does. The render is what quotes
        // the offer, and an answer to an offer that was never quoted is refused.
        $this->get($app, '/upsell/');

        $first = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);
        $second = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertSame('/thank-you/', $first->getHeaderLine('Location'));
        self::assertSame(303, $second->getStatusCode());
        self::assertSame('/thank-you/', $second->getHeaderLine('Location'));

        self::assertCount(2, $this->transport->requests, 'the checkout and one upsell, and nothing more');
        self::assertSame(
            [self::CHECKOUT_REFERENCE, self::ACCEPTED_UPSELL_REFERENCE],
            $this->state($app)->placedOrders,
        );
        self::assertCount(
            1,
            $this->rows('SELECT * FROM orders WHERE is_upsell = 1'),
            'one add-on order, however many times the button was pressed',
        );
    }

    // ---------------------------------------------------------------- the walk

    /**
     * Landing → prescription → questionnaire → placed order, returning the
     * CSRF token every later post carries.
     *
     * @param list<string> $expectedQueue the offers this cart should have earned
     */
    private function walkToPlacedOrder(App $app, array $expectedQueue = [self::FIRST_OFFER, self::SECOND_OFFER]): string
    {
        $token = $this->land($app);
        $this->post($app, '/cart/add/', [
            '_csrf' => $token, 'slug' => 'tirzepatide', 'variant_id' => 't-3m', 'quantity' => '1',
        ]);
        $this->get($app, '/intake/medical/');
        $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => '0', 'pregnancy_status' => 'no'] + self::ANSWERS);
        $this->post($app, '/intake/submit/', ['_csrf' => $token]);

        $placed = $this->post($app, '/checkout/', $this->submission($token));

        self::assertSame('/upsell/', $placed->getHeaderLine('Location'), 'the walk really did place an order');
        self::assertSame($expectedQueue, $this->state($app)->upsellQueue, 'and really did earn the offers');

        return $token;
    }

    /** The landing page, which mints the CSRF token every later post carries. */
    private function land(App $app): string
    {
        self::assertSame(200, $this->get($app, '/')->getStatusCode());

        return (string) $_SESSION['_csrf'];
    }

    /**
     * The claim every unhappy case shares: whatever the add-on did, the buyer
     * still gets the page for the order they did pay for.
     */
    private function assertTheReceiptIsStillReached(App $app, string $token): void
    {
        $next = $this->post($app, '/upsell/decline/', ['_csrf' => $token]);
        self::assertSame('/thank-you/', $next->getHeaderLine('Location'), 'the queue is spent');

        $receipt = $this->get($app, '/thank-you/');

        self::assertSame(200, $receipt->getStatusCode());
        self::assertStringContainsString('Order #' . self::CHECKOUT_REFERENCE, (string) $receipt->getBody());
    }

    // ------------------------------------------------------ provider responses

    /** The recorded approval of the prescription order, handle and all. */
    private function queueApprovedCheckout(): void
    {
        $this->transport->queueFixture('vrio-order-approved.json');
    }

    /**
     * The same approval with both halves of the credential pair stripped, which
     * is the shape a response that never carried one has.
     */
    private function queueCheckoutWithNoHandle(): void
    {
        $data = self::fixtureData('vrio-order-approved.json');
        unset(
            $data['customer_id'],
            $data['order']['customer_id'],
            $data['order']['customer_card_id'],
            $data['order']['customer_card'],
        );

        $this->transport->queue(200, (string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * One approved card-on-file placement, built from the recorded harvest
     * response so every field but the reference and the figure is the
     * provider's own.
     */
    private function queueApprovedUpsell(string $reference, int $totalCents = 899): void
    {
        $data = self::pairedFixtureResponse('vrio-handle-harvest.json');
        $data['order_id'] = (int) $reference;
        $data['transaction_total'] = number_format($totalCents / 100, 2, '.', '');

        $this->transport->queue(200, (string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * The recorded refusal of a vault handle, which creates no order at all.
     *
     * Read out of the `{request, response}` pair by hand: this fixture records
     * the whole exchange rather than a bare envelope, so
     * {@see FakeVrioTransport::queueFixture()} — which extracts `data` from the
     * top level — cannot read it.
     */
    private function queueRecordedRefusal(): void
    {
        /** @var array<string, array{response: array{data: array<string, mixed>}}> $pairs */
        $pairs = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/vrio-cof-refusal.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $first = reset($pairs);
        self::assertIsArray($first);

        $this->transport->queue(200, (string) json_encode($first['response']['data'], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function fixtureData(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $name),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['data'];

        return $data;
    }

    /** @return array<string, mixed> */
    private static function pairedFixtureResponse(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $name),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['response']['data'];

        return $data;
    }

    // --------------------------------------------------------------- fixtures

    /**
     * The whole application over this test's own database, with the three
     * overrides every form-rendering test needs plus a configured upsell layer.
     *
     * The database is built in `setUp()` rather than here so that a case can
     * build a *second* application over the same rows, which is how a
     * configuration change between two requests is modelled.
     */
    public function testTheQuoteSurvivesTheDatabaseRoundTripBetweenTwoSeparateRequests(): void
    {
        // The freeze happens on the GET and is checked on the POST, which are
        // two different requests reading journey state out of the `sessions`
        // JSON column. If it stops round-tripping, every accept sees no quote,
        // refuses, and **no upsell can ever be bought** — silently, because
        // nothing else about the funnel changes.
        $this->queueApprovedCheckout();
        $this->queueApprovedUpsell(self::ACCEPTED_UPSELL_REFERENCE);
        $app = $this->app($this->layer([self::FIRST_OFFER => self::firstOffer()]));
        $token = $this->walkToPlacedOrder($app, [self::FIRST_OFFER]);

        $this->get($app, '/upsell/');

        // Read back from the column rather than from the object that wrote it:
        // an in-process read would pass whether or not anything was persisted.
        self::assertSame(
            [self::FIRST_OFFER => 899],
            $this->durableState()->upsellQuotes,
            'the figure the buyer was shown is in the database, not just in memory',
        );

        $accepted = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertSame('/thank-you/', $accepted->getHeaderLine('Location'));
        self::assertSame(
            JourneyState::UPSELL_ACCEPTED,
            $this->durableState()->upsellOutcomes[self::FIRST_OFFER],
            'an honest buyer who followed the redirect can actually buy the thing',
        );
    }

    public function testAPriceThatMovesRefusesOnceAndThenChargesTheFigureNowOnScreen(): void
    {
        // The whole point of freezing the quote. A deploy lands between the
        // render and the click; the buyer must not be charged the new figure
        // silently, and must not be stuck on the old one either — so the
        // refusal is followed by a render that re-freezes, and the next click
        // charges what is now on screen.
        $this->queueApprovedCheckout();
        $app = $this->app($this->layer([self::FIRST_OFFER => self::firstOffer()]));
        $token = $this->walkToPlacedOrder($app, [self::FIRST_OFFER]);
        $this->get($app, '/upsell/');

        // The deploy: the same offer, repriced.
        $dearer = self::firstOffer();
        $dearer['price_cents_override'] = 1499;
        $repriced = $this->app($this->layer([self::FIRST_OFFER => $dearer]));

        $refused = $this->post($repriced, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertSame('/upsell/', $refused->getHeaderLine('Location'), 'sent back to look, not charged');
        self::assertCount(1, $this->transport->requests, 'only the checkout: nothing new reached the provider');
        self::assertSame(
            JourneyState::UPSELL_OFFERED,
            $this->durableState()->upsellOutcomes[self::FIRST_OFFER],
            'the offer is still open — a moved price is not a decline',
        );

        // The buyer looks again, sees the new figure, and agrees to that one.
        $body = (string) $this->get($repriced, '/upsell/')->getBody();
        self::assertStringContainsString('$14.99', $body);
        self::assertSame([self::FIRST_OFFER => 1499], $this->durableState()->upsellQuotes);

        $this->queueApprovedUpsell(self::ACCEPTED_UPSELL_REFERENCE);
        $this->post($repriced, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertSame(
            '14.99',
            $this->transport->body(1)['offers'][0]['order_offer_price'] ?? null,
            'charged the figure that was on screen when they agreed to it',
        );
    }

    public function testAnAcceptWithNoRenderBeforeItIsRefusedAndTheNextTryWorks(): void
    {
        // A POST with no GET before it: a replayed form, or a request built by
        // hand. There is no quote, so there is no figure the buyer can be shown
        // to have agreed to — refusing is the only honest answer, and it has to
        // be recoverable rather than terminal.
        $this->queueApprovedCheckout();
        $this->queueApprovedUpsell(self::ACCEPTED_UPSELL_REFERENCE);
        $app = $this->app($this->layer([self::FIRST_OFFER => self::firstOffer()]));
        $token = $this->walkToPlacedOrder($app, [self::FIRST_OFFER]);

        $refused = $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertSame('/upsell/', $refused->getHeaderLine('Location'));
        self::assertCount(1, $this->transport->requests, 'only the checkout so far');

        $this->get($app, '/upsell/');
        $this->post($app, '/upsell/accept/', ['_csrf' => $token, 'upsell_key' => self::FIRST_OFFER]);

        self::assertCount(2, $this->transport->requests, 'the checkout and, now, the add-on');
    }

    private function app(?Upsells $upsells = null, ?Config $config = null): App
    {
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

        return AppFactory::create(dirname(__DIR__, 2), ($config === null ? [] : [Config::class => $config]) + [
            \PDO::class => $this->pdo,
            OperatorLog::class => $log,
            SessionGateway::class => new FakeSessionGateway(
                [self::SESSION => ['opportunity_id' => null, 'events' => []]],
                self::MINTED_SESSION,
            ),
            TeleformGateway::class => self::teleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 0),
            ProductCatalog::class => self::catalog(),
            Upsells::class => $upsells ?? $this->layer(self::offers()),
            PaymentAdapter::class => static fn (): PaymentAdapter => new VrioAdapter(
                new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
                new VrioApiFactory($transport),
                $log,
                shippingProfileId: 1,
            ),
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
     * The prescription that earns the offers, and the two products the offers
     * are for.
     *
     * The upsell products are real catalog entries with a provider mapping,
     * because an offer whose product cannot be resolved is skipped rather than
     * shown and an offer with no mapping cannot be charged for.
     */
    private static function catalog(): ProductCatalog
    {
        return new FakeCatalog(self::catalogProducts());
    }

    /**
     * The same three products as a plain array, so a case that needs the
     * *concrete* {@see \AsterMD\Storefront\Catalog\CatalogProvider} — the
     * product detail page and the sitemap both depend on it rather than on the
     * {@see ProductCatalog} interface — can put them in a configuration
     * variant instead of behind a fake. Both readings come from here, so the
     * catalog the funnel walks and the catalog the crawler surface is built
     * from cannot drift apart.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function catalogProducts(): array
    {
        return [
            'tirzepatide' => [
                'slug' => 'tirzepatide',
                'name' => 'Tirzepatide',
                'kind' => 'rx',
                'teleform_id' => self::TELEFORM,
                'variants' => [
                    ['id' => 't-1m', 'name' => '1 Month', 'price_cents' => 14000, 'provider' => ['offer_id' => '337', 'product_id' => '3414']],
                    ['id' => 't-3m', 'name' => '3 Months', 'price_cents' => 12000, 'provider' => ['offer_id' => '337', 'product_id' => '3415']],
                ],
            ],
            self::FIRST_OFFER => [
                'slug' => self::FIRST_OFFER,
                'name' => 'Wellness Pack',
                'kind' => 'otc',
                'price_cents' => 1299,
                'variants' => [['id' => 'wp-1', 'name' => 'One', 'price_cents' => 1299, 'provider' => ['offer_id' => '412', 'product_id' => '3600']]],
            ],
            self::SECOND_OFFER => [
                'slug' => self::SECOND_OFFER,
                'name' => 'Sleep Kit',
                'kind' => 'otc',
                'price_cents' => 2400,
                'variants' => [['id' => 'sk-1', 'name' => 'One', 'price_cents' => 2400, 'provider' => ['offer_id' => '413', 'product_id' => '3601']]],
            ],
        ];
    }

    /**
     * Two offers, both earned by the prescription, in the order they are
     * configured in — which `[16.2]` makes the order they are offered in.
     *
     * The copy is this file's own rather than the mockup's, so an assertion on
     * it cannot pass against a page that ignored the configuration.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function offers(): array
    {
        return [
            self::FIRST_OFFER => self::firstOffer(),
            self::SECOND_OFFER => [
                'slug' => self::SECOND_OFFER,
                'offer_after' => ['tirzepatide'],
                'eyebrow' => 'One last thing',
                'headline' => 'A Kit For Better Nights',
                'body' => 'The rest of the routine.',
                'price_cents_override' => 2400,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function firstOffer(): array
    {
        return [
            'slug' => self::FIRST_OFFER,
            'offer_after' => ['tirzepatide'],
            'eyebrow' => 'A word before you go',
            'headline' => 'Round Out Your Routine',
            'body' => 'Configured body copy for the offer.',
            'price_cents_override' => 899,
        ];
    }

    /** @param array<string, array<string, mixed>> $offers */
    private function layer(array $offers): Upsells
    {
        return Upsells::fromConfig(
            ['upsells' => $offers],
            self::catalog(),
            $this->log->log,
        );
    }

    /**
     * A one-page questionnaire asking for exactly the contact details checkout
     * wants, mapped to the record paths {@see \AsterMD\Storefront\Checkout\Prefill}
     * reads backwards.
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
                    identifier: 'tests/full-funnel-upsell.json',
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

    /**
     * A complete, payable submission.
     *
     * @return array<string, mixed>
     */
    private function submission(string $csrf): array
    {
        return [
            '_csrf' => $csrf,
            'card_number' => self::CARD,
            'card_expiry' => '12 / 30',
            // Not 123: the buyer in this fixture has the phone number
            // 2125551234, so any containment assertion built on that needle
            // would pass because nothing collided with it rather than because
            // anything was withheld.
            'card_cvc' => '806',
            'consents' => ['terms' => 'on', 'transactional_sms' => 'on'],
            'first_name' => self::ANSWERS['first_name'],
            'last_name' => self::ANSWERS['last_name'],
            'email' => self::ANSWERS['email'],
            'phone' => self::ANSWERS['phone'],
            'address_line' => self::ANSWERS['street'],
            'city' => self::ANSWERS['city'],
            'territory' => 'CA',
            'postal_code' => '94105',
        ];
    }

    // ------------------------------------------------------------- assertions

    /**
     * The order references each `treatments/sync` call carried, in order.
     *
     * @return list<list<string>>
     */
    private function treatmentSyncBatches(): array
    {
        $batches = [];

        foreach ($this->emr->requests as $request) {
            if (!str_contains($request->getUri()->getPath(), '/treatments/sync')) {
                continue;
            }

            $body = $request->getBody();
            $body->rewind();

            /** @var array<string, mixed> $payload */
            $payload = json_decode($body->getContents(), true, 512, JSON_THROW_ON_ERROR);

            $references = [];
            foreach (self::referencesIn($payload) as $reference) {
                $references[] = $reference;
            }

            $batches[] = $references;
        }

        return $batches;
    }

    /**
     * Every string in a sync payload that looks like an order reference.
     *
     * The SDK owns the field name, and pinning it here would make this file
     * fail on an upgrade that has nothing to do with the funnel — so the whole
     * payload is flattened and the references are read out of it.
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private static function referencesIn(array $payload): array
    {
        $found = [];

        foreach ($payload as $value) {
            if (is_array($value)) {
                foreach (self::referencesIn($value) as $nested) {
                    $found[] = $nested;
                }

                continue;
            }

            if (is_string($value) && preg_match('/^\d{4,}$/', $value) === 1) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /** @return list<array<string, mixed>> */
    private function eventsNamed(string $name): array
    {
        return $this->rows('SELECT * FROM events WHERE name = ' . $this->pdo->quote($name));
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

    /** The journey as the container currently holds it. */
    private function state(App $app): JourneyState
    {
        $state = $app->getContainer()?->get(JourneyStore::class)->state();
        self::assertInstanceOf(JourneyState::class, $state);

        return $state;
    }

    /**
     * The journey as the `sessions` row holds it — which is what the *next*
     * request would read, and the only reading that proves anything durable
     * was written.
     */
    private function durableState(): JourneyState
    {
        return (new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo)))->load(self::SESSION);
    }

    private function get(App $app, string $path, string $session = self::SESSION): ResponseInterface
    {
        return $app->handle($this->request('GET', $path, $session));
    }

    /** @param array<string, mixed> $body */
    private function post(App $app, string $path, array $body, string $session = self::SESSION): ResponseInterface
    {
        return $app->handle($this->request('POST', $path, $session)->withParsedBody($body));
    }

    private function request(string $method, string $path, string $session = self::SESSION): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams(['amd_session' => $session])
            // A client address of this file's own, so the flood guard's
            // per-address counters cannot be shared with another test file.
            ->withHeader('X-Forwarded-For', '198.51.100.19');
    }
}

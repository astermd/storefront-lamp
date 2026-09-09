<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Checkout\NullCheckoutEventReporter;
use AsterMD\Storefront\Checkout\NullOrderRecorder;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Http\Controller\UpsellController;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Upsell\UpsellQueue;
use AsterMD\Storefront\Upsell\UpsellService;
use AsterMD\Storefront\Upsell\Upsells;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Twig\Loader\ArrayLoader;

/**
 * The upsell step's HTTP contract: which status, which `Location`, and which
 * template gets which variable.
 *
 * **Driven directly rather than through {@see \AsterMD\Storefront\Bootstrap\AppFactory}**,
 * and that is a deliberate narrowing rather than a shortcut. The pipeline
 * concerns the real routes bring with them — the session cookie a POST must
 * carry, the CSRF token the middleware checks — are middleware behaviour with
 * their own coverage, and the full-funnel walk exercises them end to end. What
 * is left once those are set aside is this class's own contract, and driving the
 * controller directly is the only way to assert it without a passing test also
 * depending on eight other things being right.
 *
 * The template is a stub rather than the shipped `pages/upsell.twig` for the
 * same reason: {@see UpsellPageTest} asserts what the page renders, so
 * asserting it here too would mean one change breaking two tests for one
 * reason. What is pinned here is the contract *between* them — the template
 * name, the variable name, and the fields the page is given to render.
 */
final class UpsellControllerTest extends TestCase
{
    use TempDatabase;

    private const string SESSION_UUID = '7c1e4a92-3b5d-4f08-9a2c-6e8b0d4f1a33';

    private const string TEMPLATE = 'pages/upsell.twig';

    private \PDO $pdo;

    private JourneyStore $journeys;

    private JourneyState $journey;

    private CartStore $carts;

    private FakeVrioTransport $transport;

    private CapturedLog $log;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->log = new CapturedLog();
        $this->transport = new FakeVrioTransport();
        $this->pdo = $this->tempPdo();
        $this->journeys = new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo));
        $this->journey = $this->journeys->load(self::SESSION_UUID);
        $this->carts = new CartStore($this->journeys);

        $this->journey->storeBuyer([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'buyer@example.com',
            'phone' => '2125551234',
            'address_line' => '350 5th Avenue',
            'city' => 'New York',
            'territory' => 'NY',
            'postal_code' => '10001',
        ]);
        $this->journey->recordPlacedOrder('34788');
        $this->journey->upsellQueue = ['wellness-pack'];
        $this->journey->storeReusableCredential(
            PaymentCredential::stored(['customer_id' => '13996', 'customer_card_id' => '16815']),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAGetWithAnEmptyQueueIsASeeOtherToTheReceipt(): void
    {
        // `[16.6]`. A 303 rather than a 302 so a reload replays the GET the
        // buyer is being sent to.
        $this->journey->upsellQueue = [];

        $response = $this->controller()->show($this->get(), $this->response());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/thank-you/', $response->getHeaderLine('Location'));
    }

    public function testAGetWithAQueueRendersTheOfferPage(): void
    {
        $response = $this->controller()->show($this->get(), $this->response());

        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('Enhance Your Wellness Journey', $body);
        self::assertStringContainsString('Wellness Pack', $body);
        self::assertStringContainsString('899', $body);
        self::assertStringContainsString(UpsellService::ACCEPT_PATH, $body);
        self::assertStringContainsString(UpsellService::DECLINE_PATH, $body);
    }

    public function testAnAcceptPostIsASeeOtherAndNotARenderedPage(): void
    {
        $this->queuePlacement('34790');
        $this->controller()->show($this->get(), $this->response());

        $response = $this->controller()->accept($this->post(['upsell_key' => 'wellness-pack']), $this->response());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/thank-you/', $response->getHeaderLine('Location'), 'the only offer was answered');
        self::assertSame('', (string) $response->getBody(), 'a charge is never replayable by a reload');
        self::assertSame(JourneyState::UPSELL_ACCEPTED, $this->journey->upsellOutcomes['wellness-pack']);
    }

    public function testADeclinePostIsASeeOtherAndChargesNothing(): void
    {
        $response = $this->controller()->decline($this->post(), $this->response());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/thank-you/', $response->getHeaderLine('Location'));
        self::assertSame([], $this->transport->requests);
        self::assertSame(JourneyState::UPSELL_DECLINED, $this->journey->upsellOutcomes['wellness-pack']);
    }

    public function testNeitherPostReadsTheOfferToChargeFromTheRequestBody(): void
    {
        // The absence that matters most here, and nothing else covers it: a
        // posted key must never be the browser telling the server which add-on
        // to charge for. A stale page, or a hand-made request, could otherwise
        // name an offer this journey never reached — including one further down
        // the queue the buyer has not been shown or priced.
        //
        // The body may only *confirm*. A key that disagrees with the cursor is
        // refused outright rather than honoured, so the attack and the honest
        // stale tab get the same safe answer: nothing is charged, and the buyer
        // is put back on the offer that is actually current.
        $this->queuePlacement('34790');
        $this->journey->upsellQueue = ['wellness-pack', 'sleep-kit'];
        $this->controller()->show($this->get(), $this->response());

        $post = $this->post(['upsell_key' => 'sleep-kit', 'key' => 'sleep-kit', 'slug' => 'sleep-kit']);
        $response = $this->controller()->accept($post, $this->response());

        self::assertSame([], $this->transport->requests, 'nothing was charged');
        self::assertSame(
            ['wellness-pack' => JourneyState::UPSELL_OFFERED],
            $this->journey->upsellOutcomes,
            'and the body did not answer an offer of its own choosing',
        );
        self::assertSame(0, $this->journey->upsellCursor, 'the queue did not advance');
        self::assertSame('/upsell/', $response->getHeaderLine('Location'));
        self::assertSame(UpsellService::OFFER_MOVED_NOTICE, $this->carts->takeNotice());
    }

    public function testAnAcceptThatConfirmsNoOfferAtAllIsRefusedRatherThanChargedAgainstTheCursor(): void
    {
        // The confirmation is worth nothing if it can be skipped by leaving it
        // out. The reachable trigger is a deploy straddle: a page rendered
        // before the hidden field shipped, cached or simply left open, whose
        // accept button posts a body with no key in it at all — and the cursor
        // has moved on since.
        //
        // What the buyer is looking at is Wellness Pack at $8.99. What the
        // cursor points at is Sleep Kit at $24.00, an offer that page never
        // showed and never priced.
        $this->queuePlacement('34790');
        $this->journey->upsellQueue = ['wellness-pack', 'sleep-kit'];
        $this->controller()->show($this->get(), $this->response());
        $this->controller()->decline($this->post(['upsell_key' => 'wellness-pack']), $this->response());
        // The second offer really has been rendered, so its frozen quote is in
        // place — which is why the quote check cannot be what stops this.
        $this->controller()->show($this->get(), $this->response());
        $this->carts->takeNotice();

        $response = $this->controller()->accept($this->post(), $this->response());

        self::assertSame([], $this->transport->requests, 'the offer nobody was shown was not charged');
        self::assertSame(
            JourneyState::UPSELL_OFFERED,
            $this->journey->upsellOutcomes['sleep-kit'] ?? null,
            'and it is still merely offered, not answered',
        );
        self::assertSame(1, $this->journey->upsellCursor, 'the queue did not advance past it');
        self::assertSame('/upsell/', $response->getHeaderLine('Location'), 'the buyer is put back on what is current');
        self::assertSame(UpsellService::OFFER_MOVED_NOTICE, $this->carts->takeNotice());
    }

    public function testADeclineThatConfirmsNoOfferStillAnswersTheCurrentOne(): void
    {
        // The asymmetry is deliberate and is the reason the accept case above
        // cannot trap anybody. A refusal redirects to `/upsell/`, which renders
        // server-side from the shipped template — so the very next click does
        // carry the key. But if a deployment's own theme dropped the field,
        // requiring it on both answers would leave the buyer with no way off
        // this page at all. Declining costs an offer rather than money, so it
        // stays the escape hatch it already is (`[16.9]`).
        $response = $this->controller()->decline($this->post(), $this->response());

        self::assertSame('/thank-you/', $response->getHeaderLine('Location'), 'there is always a way onward');
        self::assertSame(JourneyState::UPSELL_DECLINED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame([], $this->transport->requests);
    }

    public function testAStaleTabsDeclineSkipsNothing(): void
    {
        // Declining costs no money, which is exactly why this is easy to miss —
        // and it still costs the buyer an offer. Two tabs on offer A: one
        // declines it, the cursor moves to B, and the other tab's decline would
        // have refused B, which was never rendered and never seen.
        $this->journey->upsellQueue = ['wellness-pack', 'sleep-kit'];
        $this->controller()->show($this->get(), $this->response());
        $this->controller()->decline($this->post(['upsell_key' => 'wellness-pack']), $this->response());

        $response = $this->controller()->decline($this->post(['upsell_key' => 'wellness-pack']), $this->response());

        self::assertArrayNotHasKey('sleep-kit', $this->journey->upsellOutcomes, 'the unseen offer survives');
        self::assertSame(1, $this->journey->upsellCursor, 'and the queue did not skip past it');
        self::assertSame('/upsell/', $response->getHeaderLine('Location'));
        self::assertSame(UpsellService::OFFER_MOVED_NOTICE, $this->carts->takeNotice());
    }
    public function testTheOfferOnScreenIsAnsweredWhenTheBodyAgreesWithIt(): void
    {
        // The other half of the case above: a confirmation that matches is not
        // treated as a selection either — it simply does not get in the way of
        // the ordinary answer.
        $this->queuePlacement('34790');
        $this->journey->upsellQueue = ['wellness-pack', 'sleep-kit'];
        $this->controller()->show($this->get(), $this->response());

        $this->controller()->accept($this->post(['upsell_key' => 'wellness-pack']), $this->response());

        self::assertSame(
            JourneyState::UPSELL_ACCEPTED,
            $this->journey->upsellOutcomes['wellness-pack'] ?? null,
        );
        self::assertSame('412', $this->transport->body(0)['offers'][0]['offer_id'] ?? null);
    }

    public function testARefusedFloodFlashesItsNoticeAndKeepsTheBuyerOnTheOffer(): void
    {
        $response = $this->controller(rateLimit: 0)->accept($this->post(), $this->response());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/upsell/', $response->getHeaderLine('Location'));
        self::assertSame(UpsellService::RATE_LIMITED_NOTICE, $this->carts->takeNotice());
        self::assertSame([], $this->transport->requests);
    }

    public function testAnAnsweredOfferFlashesNothing(): void
    {
        $this->queuePlacement('34790');
        $this->controller()->show($this->get(), $this->response());
        $this->carts->takeNotice();

        $this->controller()->accept($this->post(['upsell_key' => 'wellness-pack']), $this->response());

        self::assertNull($this->carts->takeNotice(), 'a placed add-on has nothing to tell the buyer');
    }

    // ---------------------------------------------------------------- setup

    private function controller(int $rateLimit = 8): UpsellController
    {
        $config = Config::load(dirname(__DIR__, 2) . '/config', $_ENV);

        $service = new UpsellService(
            journeys: $this->journeys,
            queue: new UpsellQueue($this->upsells(), $this->log->log),
            adapter: new VrioAdapter(
                new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
                new VrioApiFactory($this->transport),
                $this->log->log,
                shippingProfileId: 1,
            ),
            attempts: new CheckoutAttemptRepository(fn (): \PDO => $this->pdo, 'sqlite'),
            limiter: new DatabaseRateLimiter(
                new RateLimitRepository(fn (): \PDO => $this->pdo, 'sqlite'),
                ['upsell.accept' => ['limit' => $rateLimit, 'window_seconds' => 300]],
                $this->log->log,
            ),
            orders: new NullOrderRecorder(),
            events: new NullCheckoutEventReporter(),
            guard: new PostChargeGuard($this->log->log),
            flow: FlowDefinition::fromConfig($config),
            config: $config,
            log: $this->log->log,
        );

        return new UpsellController($service, $this->carts, FlowDefinition::fromConfig($config));
    }

    private function get(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/upsell/')
            ->withAttribute('view', self::twig());
    }

    /** @param array<string, string> $body */
    private function post(array $body = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', UpsellService::ACCEPT_PATH)
            ->withAttribute('view', self::twig())
            ->withParsedBody($body);
    }

    private function response(): \Psr\Http\Message\ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * A stub for the page under the name the controller renders, echoing the
     * view model's fields so the test can see which ones arrived.
     */
    private static function twig(): Twig
    {
        return new Twig(new ArrayLoader([
            self::TEMPLATE => '{{ upsell.headline }}|{{ upsell.productName }}|{{ upsell.priceCents }}'
                . '|{{ upsell.acceptPath }}|{{ upsell.declinePath }}|{{ upsell.position }} of {{ upsell.total }}',
        ]));
    }

    private function upsells(): Upsells
    {
        return Upsells::fromConfig(
            ['upsells' => [
                'wellness-pack' => [
                    'slug' => 'wellness-pack',
                    'offer_after' => ['tirzepatide'],
                    'headline' => 'Enhance Your Wellness Journey',
                    'price_cents_override' => 899,
                ],
                'sleep-kit' => ['slug' => 'sleep-kit', 'offer_after' => ['tirzepatide']],
            ]],
            new FakeCatalog([
                'wellness-pack' => [
                    'slug' => 'wellness-pack',
                    'name' => 'Wellness Pack',
                    'kind' => 'otc',
                    'price_cents' => 1299,
                    'variants' => [
                        ['id' => 'wp-1m', 'price_cents' => 1299, 'provider' => ['offer_id' => '412', 'product_id' => '3600']],
                    ],
                ],
                'sleep-kit' => [
                    'slug' => 'sleep-kit',
                    'name' => 'Sleep Kit',
                    'kind' => 'otc',
                    'price_cents' => 2400,
                    'variants' => [
                        ['id' => 'sk-1', 'price_cents' => 2400, 'provider' => ['offer_id' => '413', 'product_id' => '3601']],
                    ],
                ],
            ]),
            $this->log->log,
        );
    }

    /** One approved placement, built from the recorded harvest response. */
    private function queuePlacement(string $reference): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/vrio-handle-harvest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['response']['data'];

        $data['order_id'] = (int) $reference;
        $data['transaction_total'] = '8.99';

        $this->transport->queue(200, (string) json_encode($data, JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\CheckoutService;
use AsterMD\Storefront\Checkout\EmrCheckoutEventReporter;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrVerificationGateway;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Emr\VerificationGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\NullTeleformGateway;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Payment\Vrio\VrioOutcome;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\DeadAfterChargePdo;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Every way checkout is allowed to fail, and the one way it is not.
 *
 * `[20.1]` and `[20.2]` divide the world in two. Tracking, analytics,
 * reporting and third-party checks may fail without the buyer ever knowing:
 * the shop stays open, the order still goes through, and an operator finds a
 * log line. Payment and eligibility stop the buyer, plainly and with a reason.
 * Every case below asserts a **200 or a 303, never a 500** — a stack trace in
 * front of someone holding a card is the failure this whole boundary exists to
 * prevent.
 *
 * The one deliberate exception has its own case here, and it is the reason
 * this file is worth reading before changing anything in the submit path: when
 * the database is unreachable, checkout **refuses rather than charging**. A
 * charge nobody can reconcile to a local record is worse for the buyer than a
 * checkout that asks them to try again in a moment, so the guard sits at the
 * idempotency claim — the last write before money moves — and the provider is
 * never called at all.
 *
 * Nothing here reaches the network. The provider is the real adapter over
 * {@see FakeVrioTransport}, the EMR is the real reporter and the real
 * verification gateway over {@see FakeEmrHttpClient}, and the outage cases
 * point the connection factory at a port nothing is listening on so the
 * attempt fails immediately rather than hanging the suite.
 */
final class CheckoutDegradationTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = 'sess-degrade-0123456789ab';

    private const string CARD = '4111111100084444';

    /** A cart line with no questionnaire and a provider mapping, so nothing but the failure under test can stop it. */
    private const string SLUG = 'wellness-panel';

    private ?\PDO $pdo = null;

    private FakeVrioTransport $transport;

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
        $this->cacheDir = sys_get_temp_dir() . '/checkout-degradation-' . bin2hex(random_bytes(6));
        $this->emrRoot = sys_get_temp_dir() . '/checkout-degradation-emr-' . bin2hex(random_bytes(6));
        mkdir($this->emrRoot . '/storage/cache', 0775, true);
        $this->registerTempDirForCleanup($this->cacheDir);
        $this->registerTempDirForCleanup($this->emrRoot);

        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET', 'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_DATABASE'] as $key) {
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

    // ------------------------------------------------------------- the EMR

    public function testTheOrderStillPlacesWhenTheEmrIsUnreachable(): void
    {
        // `[20.1]`: reporting is not the storefront. The buyer's money moves,
        // the local record is written, and the two EMR calls that failed are
        // the operator's problem rather than theirs.
        $this->seedCart();
        $app = $this->app(emrRoutes: [
            '/checkout-events/' => [500, ['success' => false, 'message' => 'upstream unavailable']],
            '/treatments/sync' => [500, ['success' => false, 'message' => 'upstream unavailable']],
        ]);
        $token = $this->land($app);

        $rendered = $this->get($app, '/checkout/');
        self::assertSame(200, $rendered->getStatusCode(), 'the page renders with the EMR down');

        $placed = $this->post($app, '/checkout/', $this->submission($token));
        self::assertSame(303, $placed->getStatusCode());
        self::assertSame('/upsell/', $placed->getHeaderLine('Location'));

        $order = $this->row('SELECT * FROM orders');
        self::assertSame('34660', $order['provider_reference'], 'the local record was still written');
        self::assertNull($order['treatment_reference'], 'and it is exactly the row a reconciliation sweep looks for');

        $failures = array_map(
            static fn (array $line): string => (string) ($line['context']['event'] ?? ''),
            $this->log->eventsNamed('checkout.event_report_failed'),
        );
        self::assertContains('checkout_visited', $failures);
        self::assertContains('order_placed', $failures);
        self::assertNotSame([], $this->log->eventsNamed('checkout.treatment_sync_failed'));
    }

    // -------------------------------------------------------- the database

    public function testTheCheckoutPageStillRendersFromThePhpSessionCartWhenTheDatabaseIsUnreachable(): void
    {
        // The visitor's live cart lives in the PHP session and the durable
        // copy is a mirror, so the summary a buyer is looking at needs no
        // database at all (`[20.1]`).
        $this->seedCart();
        $app = $this->appWithNoDatabase();

        $response = $this->get($app, '/checkout/');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Wellness Panel', $body);
        self::assertStringContainsString('$99.00', $body, 'priced from the session cart, not from a row');
        self::assertStringContainsString('id="checkout-pay"', $body);
    }

    public function testTheSubmitPathRefusesRatherThanChargingWhenTheDatabaseIsUnreachable(): void
    {
        // The deliberate exception to degrade-silently. The idempotency claim
        // is the last write before money moves, so an attempt that cannot be
        // claimed is refused: a charge nobody can reconcile to a local record
        // is worse for the buyer than being asked to try again.
        $this->seedCart();
        $app = $this->appWithNoDatabase();
        $token = $this->land($app);

        $response = $this->post($app, '/checkout/', $this->submission($token));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), 'refused, not crashed');
        self::assertStringContainsString(CheckoutService::UNRECORDABLE_NOTICE, $body);
        self::assertSame([], $this->transport->requests, 'the card was never presented to the provider');
        self::assertStringContainsString('Wellness Panel', $body, 'and the cart is untouched, so a retry costs nothing');

        $refusal = $this->log->eventsNamed('checkout.attempt_unrecordable');
        self::assertCount(1, $refusal, 'the refusal is stated in the operator log, or an outage looks like a decline');
        self::assertSame('error', $refusal[0]['level'] ?? null);
    }

    public function testAChargedOrderReachesTheReceiptWhenThePostChargeWriteCannotBeMade(): void
    {
        // The one place the refusal above cannot help: the provider has
        // already taken the money when the database goes away. Everything from
        // the charge onward has to fail safe, because a 500 in front of a buyer
        // whose card was just debited leaves no local record of the order at
        // all -- the exact outcome "refuse rather than charge" exists to make
        // impossible.
        $this->seedCart();
        $app = $this->app(breakPostChargeWrite: true);
        $token = $this->land($app);

        $placed = $this->post($app, '/checkout/', $this->submission($token));

        self::assertSame(303, $placed->getStatusCode(), 'the buyer is sent onward, not shown a stack trace');
        self::assertSame('/upsell/', $placed->getHeaderLine('Location'));
        self::assertSame('34660', $this->row('SELECT * FROM orders')['provider_reference'], 'and the order really was recorded');

        $lost = $this->log->eventsNamed('checkout.post_charge_write_failed');
        self::assertNotSame([], $lost, 'the write nobody could make is a reconciliation item');
        self::assertSame('error', $lost[0]['level'] ?? null);
    }

    // ------------------------------------------------------ the analytics session

    public function testAnOrderPlacesWithNoAnalyticsSessionAtAllAndSaysSoInTheLog(): void
    {
        // `[20.8]`: analytics being unavailable must not stop a
        // purchase, and a synthetic identifier is forbidden — so the order
        // goes out with no session on it and the gap is stated rather than
        // filled in.
        $this->seedCart();
        $app = $this->app(mintUuid: null, withCookie: false);
        $token = $this->land($app, withCookie: false);

        $placed = $this->post($app, '/checkout/', $this->submission($token), withCookie: false);

        self::assertSame(303, $placed->getStatusCode());
        self::assertNull($app->getContainer()?->get(JourneyStore::class)->sessionUuid());
        self::assertArrayNotHasKey('session_id', $this->transport->body(0), 'no invented identifier reached the provider');
        self::assertSame('34660', $this->row('SELECT * FROM orders')['provider_reference']);
        self::assertNull($this->row('SELECT * FROM orders')['session_uuid']);
        self::assertNotSame([], $this->log->eventsNamed('checkout.placed_without_session'));
    }

    // ------------------------------------------------------------ verification

    public function testValidationPassesOnTheLocalChecksAloneWhenVerificationAnswers403(): void
    {
        // This deployment's credential is refused for the whole
        // `verification()` resource. A check that cannot answer must cost the
        // buyer a second opinion, never the order (`[20.1]`), so the refusal
        // is an info line and the local shape checks stand on their own.
        $this->seedCart();
        $app = $this->app(verification: true, emrRoutes: [
            '/extensions/email-verify' => [403, ['success' => false, 'message' => 'You do not have permission to perform this action']],
        ]);
        $token = $this->land($app);

        $placed = $this->post($app, '/checkout/', $this->submission($token));

        self::assertSame(303, $placed->getStatusCode(), 'a refused check did not become a refused order');
        self::assertSame('34660', $this->row('SELECT * FROM orders')['provider_reference']);

        $unavailable = $this->log->eventsNamed('verification.unavailable');
        self::assertNotSame([], $unavailable, 'the check really was attempted and really did fail');
        self::assertSame('info', $unavailable[0]['level'] ?? null, '[20.10]: an expected refusal is not an alert');
        self::assertStringNotContainsString('ada@example.test', $this->log->contents(), 'and nothing about the buyer reached the log');
    }

    public function testAnInvalidFieldIsStillRefusedWhenVerificationCannotAnswer(): void
    {
        // The other half of the claim above: the local checks are not standing
        // down while the EMR is refusing, they are doing the whole job. Without
        // this, "the order went through" would not distinguish "validation
        // passed locally" from "validation gave up".
        $this->seedCart();
        $app = $this->app(verification: true, emrRoutes: [
            '/extensions/email-verify' => [403, ['success' => false, 'message' => 'You do not have permission to perform this action']],
        ]);
        $token = $this->land($app);

        $response = $this->post($app, '/checkout/', $this->submission($token, postalCode: '123'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="checkout-error-postal_code"', (string) $response->getBody());
        self::assertSame([], $this->transport->requests);
    }

    // --------------------------------------------------------------- the provider

    public function testAProviderTimeoutIsADeclineWithTheGenericMessageAndTheCartIntact(): void
    {
        // `[13.31]`: a transport failure is a decline, not an error page. The
        // provider's client reports it as a `curlError` key rather than by
        // throwing, and the message shown is ours — an exception string can
        // carry anything, including the request that produced it.
        $this->seedCart();
        $app = $this->app(queueTimeout: true);
        $token = $this->land($app);

        $response = $this->post($app, '/checkout/', $this->submission($token));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(VrioOutcome::GENERIC_DECLINE, $body);
        self::assertStringContainsString('Wellness Panel', $body, 'the cart is intact, so the buyer can simply try again');
        self::assertStringContainsString('value="ada@example.test"', $body);
        self::assertSame([], $this->rows('SELECT * FROM orders'), 'nothing was recorded as bought');
        self::assertStringNotContainsString(self::CARD, $body);
        self::assertStringNotContainsString(self::CARD, $this->log->contents());
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * The whole application over a throwaway database, with the collaborators
     * a degradation case needs to be able to break.
     *
     * @param array<string, array{0: int, 1: array<string, mixed>|string}> $emrRoutes overrides, matched before the healthy ones
     */
    private function app(
        array $emrRoutes = [],
        bool $verification = false,
        bool $queueTimeout = false,
        ?string $mintUuid = self::SESSION,
        bool $withCookie = true,
        string $fixture = 'vrio-order-approved.json',
        bool $breakPostChargeWrite = false,
    ): App {
        if ($queueTimeout) {
            // The provider's client reports a network failure as a key on the
            // decoded envelope rather than as an exception, which is the shape
            // `VrioOutcome` reads.
            $this->transport->queue(0, '', 'Operation timed out after 30000 milliseconds');
        } else {
            $this->transport->queueFixture($fixture);
        }

        $emr = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => [200, [
                'success' => true,
                'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
            ]],
        ] + $emrRoutes + [
            '/checkout-events/' => [200, ['success' => true, 'data' => ['_id' => '6a8b39941076171b7c0e2f2c']]],
            '/treatments/sync' => [200, ['success' => true, 'data' => ['reference' => 'trt-1']]],
            '/extensions/email-verify' => [200, ['success' => true, 'data' => ['result' => 'valid']]],
        ]);

        $overrides = $this->sharedOverrides();
        $overrides[\PDO::class] = $this->pdo = $this->tempPdo();
        $overrides[SessionGateway::class] = $withCookie
            ? new FakeSessionGateway([self::SESSION => ['opportunity_id' => null, 'events' => []]], $mintUuid)
            : new FakeSessionGateway([], $mintUuid);

        $emrRoot = $this->emrRoot;
        $overrides[CheckoutEventReporter::class] = static fn (Container $c): CheckoutEventReporter => new EmrCheckoutEventReporter(
            new ClientFactory($c->get(Config::class), $emrRoot),
            $c->get(EventRepository::class),
            $c->get(OrderRepository::class),
            $c->get(OperatorLog::class),
            static fn (): ?string => null,
            'USD',
            true,
            $emr,
            static fn (): ?string => 'Mozilla/5.0 (test)',
        );

        if ($breakPostChargeWrite) {
            // Only the completing write, so the claim still succeeds and the
            // card really is presented to the provider -- a wholly unreachable
            // database refuses earlier and proves something else entirely.
            $attempts = DeadAfterChargePdo::alongside($this->pdo);
            $overrides[CheckoutAttemptRepository::class] = new CheckoutAttemptRepository(
                static fn (): \PDO => $attempts,
                'sqlite',
            );
        }

        if ($verification) {
            $overrides[VerificationGateway::class] = static fn (Container $c): VerificationGateway => new EmrVerificationGateway(
                new ClientFactory($c->get(Config::class), $emrRoot),
                $c->get(OperatorLog::class),
                $emr,
            );
        }

        return AppFactory::create(dirname(__DIR__, 2), $overrides);
    }

    /**
     * The same application with the connection factory pointed at a port
     * nothing can be listening on, so the first query inside the request fails
     * immediately rather than hanging the suite.
     *
     * `\PDO::class` is deliberately *not* overridden: the point of these cases
     * is what the real lazy connection does when it cannot open.
     */
    private function appWithNoDatabase(): App
    {
        foreach (['DB_DRIVER' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'nothing_here'] as $key => $value) {
            $_ENV[$key] = $value;
        }

        $this->transport->queueFixture('vrio-order-approved.json');

        $overrides = $this->sharedOverrides();
        $overrides[SessionGateway::class] = new FakeSessionGateway([], self::SESSION);

        return AppFactory::create(dirname(__DIR__, 2), $overrides);
    }

    /**
     * What every case here shares: the real payment adapter over a fake
     * transport, a catalog with one mapped line, and the three overrides any
     * form-rendering test needs.
     *
     * @return array<string, mixed>
     */
    private function sharedOverrides(): array
    {
        $transport = $this->transport;
        $log = $this->log->log;

        return [
            OperatorLog::class => $log,
            TeleformGateway::class => new NullTeleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 0),
            ProductCatalog::class => new FakeCatalog([self::SLUG => [
                'slug' => self::SLUG,
                'name' => 'Wellness Panel',
                'kind' => 'otc',
                'price_cents' => 9900,
                'variants' => [['id' => 'wp-1', 'name' => 'One panel', 'price_cents' => 9900, 'provider' => ['offer_id' => '337', 'product_id' => '3414']]],
            ]]),
            PaymentAdapter::class => static fn (): PaymentAdapter => new VrioAdapter(
                new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
                new VrioApiFactory($transport),
                $log,
                shippingProfileId: 1,
            ),
        ];
    }

    /** One priced line with a plan chosen, so nothing but the failure under test can stop the order. */
    private function seedCart(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => self::SLUG, 'name' => 'Wellness Panel', 'kind' => 'otc',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 9900, 'variant_id' => 'wp-1',
        ]]];
    }

    private function land(App $app, bool $withCookie = true): string
    {
        self::assertSame(200, $this->get($app, '/', $withCookie)->getStatusCode());

        return (string) $_SESSION['_csrf'];
    }

    /**
     * A complete, payable submission.
     *
     * @return array<string, mixed>
     */
    private function submission(string $csrf, string $postalCode = '94105'): array
    {
        return [
            '_csrf' => $csrf,
            'card_number' => self::CARD,
            'card_expiry' => '12 / 30',
            'card_cvc' => '123',
            'consents' => ['terms' => 'on'],
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'phone' => '2125551234',
            'address_line' => '350 5th Avenue',
            'city' => 'San Francisco',
            'territory' => 'CA',
            'postal_code' => $postalCode,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        self::assertInstanceOf(\PDO::class, $this->pdo, 'this case has no database to read');
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

    private function get(App $app, string $path, bool $withCookie = true): ResponseInterface
    {
        return $app->handle($this->request('GET', $path, $withCookie));
    }

    /** @param array<string, mixed> $body */
    private function post(App $app, string $path, array $body, bool $withCookie = true): ResponseInterface
    {
        return $app->handle($this->request('POST', $path, $withCookie)->withParsedBody($body));
    }

    private function request(string $method, string $path, bool $withCookie): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            // A client address of this file's own, so the flood guard's
            // per-address counters cannot be shared with another test file.
            ->withHeader('X-Forwarded-For', '198.51.100.23');

        return $withCookie ? $request->withCookieParams(['amd_session' => self::SESSION]) : $request;
    }
}

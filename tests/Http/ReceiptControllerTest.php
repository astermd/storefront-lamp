<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\EmrCheckoutEventReporter;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Completion\Completion;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\NullTeleformGateway;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Http\Controller\PageController;
use AsterMD\Storefront\Http\Controller\ReceiptController;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The receipt page, over real HTTP requests through the whole middleware
 * pipeline.
 *
 * The point of doing it this way rather than calling {@see Completion} directly
 * is the second request. `wipeForCompletion()` clears almost everything a
 * journey holds, and the one field that keeps this page reachable at all --
 * `placedOrders` -- survives it only because the wipe deliberately leaves it
 * alone. A unit test asserting the field is still set proves the assignment; it
 * cannot prove that the step guard, which is what actually strands a buyer,
 * still lets them back in. So the assertion is a second GET that has to return
 * 200 after the journey has been torn down, and the durable row read back from
 * the database after it.
 *
 * `/thank-you/` is still wired to {@see PageController} in the route table, so
 * that binding is swapped for the controller under test rather than the routes
 * being rewritten -- the whole pipeline is what is being exercised, and only
 * this one path is visited.
 */
final class ReceiptControllerTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private \PDO $pdo;

    private CapturedLog $log;

    private string $cacheDir;

    private string $emrRoot;

    protected function setUp(): void
    {
        $this->log = new CapturedLog();
        $this->cacheDir = sys_get_temp_dir() . '/receipt-cache-' . bin2hex(random_bytes(6));
        $this->emrRoot = sys_get_temp_dir() . '/receipt-emr-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0775, true);
        mkdir($this->emrRoot . '/storage/cache', 0775, true);
        $this->registerTempDirForCleanup($this->cacheDir);
        $this->registerTempDirForCleanup($this->emrRoot);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheReceiptRendersAndTheJourneyIsTornDownAroundIt(): void
    {
        $app = $this->app();
        $this->seedPurchase(['34788', '34790']);

        self::assertSame(200, $this->get($app, '/thank-you/')->getStatusCode());

        $stored = $this->storedJourney();

        self::assertSame(['34788', '34790'], $stored['receipt']['references'] ?? null, '[4.17]');
        self::assertSame(16900, $stored['receipt']['paid_total_cents'] ?? null, '[16.10] over the rows');
        self::assertNotNull($stored['completed_at'] ?? null, '[17.1]');
        self::assertTrue($stored['session_retired'] ?? false, '[4.18]');
        self::assertSame([], $stored['buyer'] ?? null, '[4.16]');
        self::assertSame(['34788', '34790'], $stored['placed_orders'] ?? null, 'and the proof of purchase stays');
    }

    public function testTheDurableBlobKeepsNoAnswersAndNoChargeAuthorityOnceTheJourneyIsOver(): void
    {
        // The absence no mutation of this code could ever reveal, and the one
        // that matters most: `journey_state` is a durable column, the receipt
        // is the one thing `[4.17]` keeps in it, and everything the journey
        // carried on the way there -- clinical answers, the reusable credential
        // handle, the buyer's contact block -- has to be gone from the row
        // itself (`[4.16]`), not merely absent from the page.
        $app = $this->app();
        $this->seedPurchase(['34788']);

        $this->get($app, '/thank-you/');

        $stored = $this->storedJourney();
        $encoded = (string) json_encode($stored);

        self::assertSame([], $stored['form_answers'] ?? null, 'no clinical answers survive');
        self::assertNull($stored['payment_handle'] ?? null, 'and no charge authority does either');
        self::assertStringNotContainsString('pregnancy_status', $encoded);
        self::assertStringNotContainsString('13996', $encoded);
        self::assertSame([], $stored['buyer'] ?? null, 'the working buyer block goes with them');
        self::assertSame(['34788'], $stored['receipt']['references'] ?? null, 'and the receipt is still there');
        // The contact details are the deliberate exception, and the only one:
        // the receipt has to render after the wipe and there is no street
        // address in the `orders` table to rebuild a parcel label from. What is
        // kept is what a receipt shows; what is dropped is everything a *next*
        // journey could inherit.
        self::assertSame('buyer@example.com', $stored['receipt']['buyer_email'] ?? null);
        self::assertSame('123 Main St', $stored['receipt']['shipping']['address_line'] ?? null);
    }

    public function testARefreshAfterTheTeardownStillReachesTheReceiptAndFiresNothingAgain(): void
    {
        // The step guard on `/thank-you/` requires a placed order, and the wipe
        // runs between these two requests. A 302 here is a buyer bounced off
        // the record of the purchase they just made.
        $app = $this->app();
        $this->seedPurchase(['34788']);

        $this->get($app, '/thank-you/');
        $second = $this->get($app, '/thank-you/');

        self::assertSame(200, $second->getStatusCode());
        self::assertStringContainsString('noindex', (string) $second->getBody());
        self::assertSame(
            1,
            $this->countLocalEvent('checkout.completed'),
            '[17.1], [18.1]: once per checkout however often the page is loaded',
        );
    }

    public function testAVisitorWhoBoughtNothingIsRoutedAwayRatherThanShownAnEmptyReceipt(): void
    {
        // Not this controller's decision -- `[4.5]`'s step guard owns it -- and
        // the assertion is here because the empty view model exists for the
        // *other* case: a journey that did buy something the storefront cannot
        // prove. Those two must not be confused.
        $app = $this->app();
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);

        self::assertSame(302, $this->get($app, '/thank-you/')->getStatusCode());
        self::assertSame(0, $this->countLocalEvent('checkout.completed'));
    }

    // --------------------------------------------------------------- fixtures

    /** @param list<string> $references */
    private function seedPurchase(array $references): void
    {
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(
            self::SESSION,
            [
                'placed_orders' => $references,
                // PHI and a charge authority, both of which `[4.16]` says must
                // not survive the journey they belong to.
                'form_answers' => ['medical' => ['pregnancy_status' => 'no']],
                'payment_handle' => ['kind' => 'stored_instrument', 'handle' => ['customer_id' => '13996']],
                'buyer' => [
                    'email' => 'buyer@example.com',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'address_line' => '123 Main St',
                    'city' => 'New York',
                    'territory' => 'NY',
                    'postal_code' => '10001',
                ],
            ],
            null,
        );

        $orders = new OrderRepository(fn (): \PDO => $this->pdo);
        $amounts = [12000, 4900];
        foreach ($references as $index => $reference) {
            $orders->insert(
                [
                    'session_uuid' => self::SESSION,
                    'provider_reference' => $reference,
                    'anchor_slug' => 'tirzepatide',
                    'amount_cents' => $amounts[$index] ?? 4900,
                    'currency' => 'USD',
                    'status' => 'placed',
                    'payment_method' => 'card',
                    'card_last_four' => '4444',
                    'is_upsell' => $index > 0,
                ],
                [[
                    'slug' => 'tirzepatide',
                    'name' => 'Tirzepatide',
                    'kind' => 'rx',
                    'unit_price_cents' => $amounts[$index] ?? 4900,
                    'quantity' => 1,
                ]],
                [],
            );
        }
    }

    /** @return array<string, mixed> the `journey_state` column as it was persisted */
    private function storedJourney(): array
    {
        $row = (new SessionRepository(fn (): \PDO => $this->pdo))->find(self::SESSION);
        self::assertNotNull($row);

        return (array) $row['journey_state'];
    }

    private function countLocalEvent(string $name): int
    {
        return count(array_filter(
            (new EventRepository(fn (): \PDO => $this->pdo))->namesFor(self::SESSION),
            static fn (string $event): bool => $event === $name,
        ));
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
                ->withCookieParams(['amd_session' => self::SESSION])
                ->withHeader('X-Forwarded-For', '198.51.100.31'),
        );
    }

    /**
     * The whole application, with the receipt controller in the route table's
     * `PageController` slot and the reporter's EMR half switched off.
     *
     * The reporter is the real one because `[18.1]`'s local trail is what the
     * fire-once assertion counts, and a null reporter would leave that
     * assertion counting nothing. Its EMR half is off, which is what keeps the
     * run offline without also silencing the trail.
     */
    private function app(): App
    {
        $emrRoot = $this->emrRoot;
        $log = $this->log->log;

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo = $this->tempPdo(),
            OperatorLog::class => $log,
            TeleformGateway::class => new NullTeleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
            SessionGateway::class => new FakeSessionGateway(
                [self::SESSION => ['opportunity_id' => null, 'events' => []]],
                'mint-never-needed-here',
            ),
            CheckoutEventReporter::class => static fn (Container $c): CheckoutEventReporter => new EmrCheckoutEventReporter(
                new ClientFactory($c->get(Config::class), $emrRoot),
                $c->get(EventRepository::class),
                $c->get(OrderRepository::class),
                $c->get(OperatorLog::class),
                static fn (): ?string => $c->get(JourneyStore::class)->state()?->attribution?->get('utm_source'),
                'USD',
                false,
            ),
            Completion::class => static fn (Container $c): Completion => new Completion(
                $c->get(JourneyStore::class),
                $c->get(OrderRepository::class),
                $c->get(CheckoutEventReporter::class),
                new PostChargeGuard($c->get(OperatorLog::class)),
                $c->get(OperatorLog::class),
                (string) $c->get(Config::class)->get('payment.currency', 'USD'),
            ),
            PageController::class => static fn (Container $c): ReceiptController => new ReceiptController(
                $c->get(Completion::class),
            ),
        ]);
    }
}

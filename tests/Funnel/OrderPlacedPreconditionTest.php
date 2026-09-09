<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The guard on the two steps that come after a purchase, driven through the
 * whole application against the real `config/funnel.php`.
 *
 * Guarding these pages is less obvious than it looks: `[13.32]` clears the
 * cart the moment an order is placed, so from the checkout page onwards a
 * buyer and a stranger have exactly the same empty cart. The placed order
 * recorded on the journey is the first fact that tells them apart, and it is
 * the only thing this precondition can stand on.
 */
final class OrderPlacedPreconditionTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    /** The journey minted for a visitor arriving without a cookie: someone else, with no order of their own. */
    private const string STRANGER = '9c2d7e41-05b3-4a18-8f6d-3b7c1e9a4d52';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheReceiptIsUnreachableWithoutAPlacedOrder(): void
    {
        $response = $this->app()->handle($this->get('/thank-you/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testTheUpsellIsUnreachableWithoutAPlacedOrder(): void
    {
        $response = $this->app()->handle($this->get('/upsell/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testTheReceiptIsReachableOnceAnOrderHasBeenPlaced(): void
    {
        $app = $this->app();
        $this->seedPlacedOrder();

        self::assertSame(200, $app->handle($this->get('/thank-you/'))->getStatusCode());
    }

    public function testTheUpsellIsReachableOnceAnOrderHasBeenPlaced(): void
    {
        $app = $this->app();
        $this->seedPlacedOrder();

        // Admitted by the guard, then answered by the step itself. With no
        // upsell configured there is nothing to offer, and an offer step with
        // an empty queue sends the buyer to their receipt (`[16.6]`) rather
        // than rendering a page about nothing. The distinction that matters
        // here is *who* redirected: the guard would name the step it thinks
        // the visitor belongs on, and this names the receipt.
        $response = $app->handle($this->get('/upsell/'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/thank-you/', $response->getHeaderLine('Location'));
    }

    public function testAJourneyWithNeitherAnOrderNorACartLandsOnHomeRatherThanLooping(): void
    {
        // The case the guard's fail-closed branch makes worth checking: with
        // an empty cart every funnel step is unsatisfied, so a redirect to
        // "the first unmet step" would bounce forever. The routing decision
        // answers home, and home carries no requirements.
        $app = $this->app();

        $response = $app->handle($this->get('/thank-you/'));

        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertSame(200, $app->handle($this->get('/'))->getStatusCode());
    }

    /**
     * The fact is read from this journey's own durable state, not from "an
     * order exists somewhere", so a shared link cannot carry it.
     *
     * The visitor here arrives with no cookie and gets a journey of their own
     * minted — which is what the case is about. It used to arrive with no
     * *journey at all*, and passed because an absent journey was read as
     * "no order placed". That is no longer true, and rightly: an absent
     * journey means the storefront could not answer the question, and
     * `order_placed` now opens rather than closes on that (`[20.1]`). Minting
     * a second journey is the stranger this test always meant to describe.
     */
    public function testAPlacedOrderOnSomeoneElsesJourneyDoesNotOpenTheReceipt(): void
    {
        $app = $this->app();
        $this->seedPlacedOrder();

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/thank-you/'),
        );

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * `[13.32]` clears the cart the moment an order is placed, so a buyer who
     * posts the checkout form a second time — a reload of the POST, an
     * impatient double-click, the back button — arrives with an empty cart and
     * fails `cart_not_empty`. The guard then asks the routing decision where
     * to send them, and "home" is the wrong answer for someone whose card has
     * just been charged: it hands them the front page with an empty drawer and
     * no sign that anything happened.
     *
     * Fixed at the routing decision rather than in the checkout handler
     * because the handler is never reached — the guard has already redirected,
     * and a browser turns that into a GET.
     */
    public function testAReSubmittedCheckoutLandsOnTheReceiptRatherThanHome(): void
    {
        $app = $this->app();
        $this->seedPlacedOrder();
        $token = $this->csrfToken($app);

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/checkout/')
                ->withParsedBody(['_csrf' => $token])
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/thank-you/', $response->getHeaderLine('Location'));

        // And the destination holds: `receipt` requires `order_placed`, which
        // this journey satisfies, so the redirect does not bounce.
        self::assertSame(200, $app->handle($this->get('/thank-you/'))->getStatusCode());
    }

    // ---------------------------------------------------------------- fixtures

    /** Seeds the CSRF token with a GET, exactly as every other full-app POST test does. */
    private function csrfToken(App $app): string
    {
        $app->handle($this->get('/'));

        return (string) $_SESSION['_csrf'];
    }

    private function app(): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo = $this->tempPdo(),
            // The session is known server-side, so the cookie resolves to this
            // journey and is read back from the database rather than re-minted
            // — without that there is no journey at all and every "a placed
            // order opens the page" case would pass vacuously. A request
            // arriving with no cookie mints a journey of its own instead of
            // going journey-less, so "a stranger" means a different journey
            // rather than the absence of one, which is a different question
            // and has a different answer.
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
                mintUuid: self::STRANGER,
            ),
        ]);
    }

    /**
     * Puts a placed order in durable journey state, the way a completed
     * checkout would have.
     *
     * Written before the first request rather than between two, because the
     * journey store keeps one state per container: a second request against
     * the same app reads the copy the first one loaded, not the database.
     */
    private function seedPlacedOrder(): void
    {
        (new SessionRepository(fn (): \PDO => $this->pdo))
            ->insert(self::SESSION, ['placed_orders' => ['34660'], 'reconciled' => true], null);
    }

    private function get(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withCookieParams(['amd_session' => self::SESSION]);
    }
}

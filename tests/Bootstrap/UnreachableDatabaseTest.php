<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Bootstrap;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * What the storefront still does with no database at all.
 *
 * Two separate promises are covered here. The first is that bootstrap must not
 * connect: the connection is opened on first use inside a request, which is
 * what keeps an outage inside the error boundary, because `AppFactory::create()`
 * runs before the error middleware exists and anything that throws there is an
 * unhandled fatal — no themed page, no request id, and `/health/` can never
 * report `database: false` precisely when an operator needs it.
 *
 * The second is larger and is what `[20.1]` actually asks for: the shop stays
 * open. The visitor's cart lives in the PHP session and the durable mirror is
 * a copy, so browsing, the drawer, the step guard and every cart mutation must
 * all keep working while the database is down. Only the durable half — the
 * analytics session, the journey row, the EMR mirror keyed by it — degrades,
 * silently and with a log line.
 */
final class UnreachableDatabaseTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $_SESSION = [];

        // A driver/port pair nothing can be listening on: the connection
        // attempt fails immediately rather than hanging the suite.
        foreach (['DB_DRIVER' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'nothing_here'] as $key => $value) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }
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

    /** The one-line shape every other full-app cart test seeds, kept here so these cases stay readable. */
    private function seedCart(string $kind = 'otc'): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => 'seeded', 'name' => 'Seeded Product', 'kind' => $kind,
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => 'v1',
        ]]];
    }

    /** @param array<string, mixed> $body */
    private function post(App $app, string $path, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body),
        );
    }

    public function testBootstrapDoesNotThrowWhenTheDatabaseIsUnreachable(): void
    {
        self::assertInstanceOf(App::class, AppFactory::create(dirname(__DIR__, 2)));
    }

    public function testAPageStillRendersRatherThanFatallingBeforeTheErrorBoundary(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testHealthReportsTheDatabaseAsDownWithA503(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/health/'));
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($payload['checks']['database']);
        self::assertSame('degraded', $payload['status']);
    }

    /**
     * A guarded step's preconditions are evaluated against the visitor's real
     * cart, which lives in the PHP session and needs no database at all. So
     * the guard does not stand down during an outage — it keeps working, and
     * someone holding a cart still reaches checkout. That is the stronger
     * reading of `[20.1]`: not "renders unguarded" but "is not affected".
     */
    public function testAGuardedPageStillAdmitsARealCartWhenTheDatabaseIsUnreachable(): void
    {
        $this->seedCart();
        $app = AppFactory::create(dirname(__DIR__, 2));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The other half of the same claim: the guard still *refuses* an empty
     * cart while the database is down. A degraded guard would have let this
     * through, so asserting only the case above would not distinguish "the
     * guard works" from "the guard gave up".
     */
    public function testAGuardedPageStillTurnsAnEmptyCartAwayWhenTheDatabaseIsUnreachable(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    /**
     * The mini-cart drawer is published as a global on every page, so it is
     * the one piece of cart state a database outage could plausibly take the
     * whole site down with. It shows the real cart instead: the working copy
     * is in the PHP session, and the durable mirror it is missing is exactly
     * the part nobody needs to render a drawer.
     */
    public function testTheCartDrawerStillShowsTheRealCartWhenTheDatabaseIsUnreachable(): void
    {
        $this->seedCart();
        $app = AppFactory::create(dirname(__DIR__, 2));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Seeded Product', $body);
        self::assertStringContainsString('aria-label="Open cart, 1 item"', $body);
    }

    /**
     * The blocker this suite exists to pin: a cart mutation posted while the
     * database is unreachable is an ordinary accepted mutation. It answers
     * with the same 303 to the routing decision it always does, and the line
     * is in the cart on the very next request — because
     * {@see \AsterMD\Storefront\Journey\CartStore} writes the PHP session
     * first and treats the durable copy as a mirror, and
     * {@see \AsterMD\Storefront\Repository\SessionRepository} does not connect
     * until something asks it to read or write. A storefront that cannot take
     * an order because its analytics store is down is precisely what `[20.1]`
     * forbids.
     */
    public function testAnAddPostSucceedsAndSticksWhenTheDatabaseIsUnreachable(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2), [
            // The real config/products.generated.php a sync last wrote no
            // longer carries a `semaglutide` slug; the sample seed still does.
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
        ]);
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        $token = (string) $_SESSION['_csrf'];

        $response = $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'semaglutide']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));

        $next = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame(200, $next->getStatusCode());
        self::assertStringContainsString('aria-label="Open cart, 1 item"', (string) $next->getBody());
    }

    /**
     * The other two mutations answer the same way, so "the cart works with no
     * database" is not a claim about `add` alone.
     */
    public function testRemoveAndQuantityAlsoAnswerWithA303WhenTheDatabaseIsUnreachable(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        $token = (string) $_SESSION['_csrf'];
        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'semaglutide']);

        $quantity = $this->post($app, '/cart/quantity/', [
            '_csrf' => $token, 'slug' => 'semaglutide', 'quantity' => 2, 'return_to' => '/treatments/',
        ]);
        $remove = $this->post($app, '/cart/remove/', [
            '_csrf' => $token, 'slug' => 'semaglutide', 'return_to' => '/treatments/',
        ]);

        self::assertSame(303, $quantity->getStatusCode());
        self::assertSame(303, $remove->getStatusCode());

        $next = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertStringContainsString('aria-label="Open cart, 0 items"', (string) $next->getBody());
    }

    /**
     * The two post-purchase pages are the ones an outage must not take away,
     * and they are the two whose guard cannot be answered from the PHP session:
     * `[13.32]` clears the cart the moment an order is placed, so
     * `order_placed` reads durable journey state and an unreachable database
     * makes that state *unknowable* rather than known-false. The precondition
     * therefore opens (`[20.1]`), and what the visitor gets is a receipt with
     * nothing on it — which is the honest answer for a journey the storefront
     * cannot read, and is not a 500 in front of someone who may have just been
     * charged.
     *
     * The offer page redirects rather than rendering, for the same reason: with
     * no journey there is no queue, and `[16.6]` sends a spent queue on to the
     * receipt.
     */
    public function testTheOfferAndReceiptPagesBothAnswerWhenTheDatabaseIsUnreachable(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));

        $offer = $app->handle($this->asBuyer('GET', '/upsell/'));
        $receipt = $app->handle($this->asBuyer('GET', '/thank-you/'));

        self::assertSame(303, $offer->getStatusCode());
        self::assertSame('/thank-you/', $offer->getHeaderLine('Location'));

        self::assertSame(200, $receipt->getStatusCode());
        $body = (string) $receipt->getBody();
        self::assertStringContainsString('Thank you!', $body);
        self::assertStringNotContainsString('Order #', $body, 'nothing can be read, so nothing is claimed');
    }

    /**
     * The two answers to an offer, posted during the same outage.
     *
     * Both are reachable: a buyer already looking at the offer page when the
     * database goes down still has two buttons, and pressing either must move
     * them on rather than showing them a stack trace. Neither can charge
     * anything — the journey carrying the credential handle is exactly what
     * cannot be read — so both are the same redirect, and the accept never
     * reaches the provider.
     */
    public function testBothAnswersToAnOfferAnswerWithARedirectWhenTheDatabaseIsUnreachable(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $app->handle($this->asBuyer('GET', '/'));
        $token = (string) $_SESSION['_csrf'];

        $accepted = $app->handle($this->asBuyer('POST', '/upsell/accept/')->withParsedBody(['_csrf' => $token]));
        $declined = $app->handle($this->asBuyer('POST', '/upsell/decline/')->withParsedBody(['_csrf' => $token]));

        self::assertSame(303, $accepted->getStatusCode());
        self::assertSame('/thank-you/', $accepted->getHeaderLine('Location'));
        self::assertSame(303, $declined->getStatusCode());
        self::assertSame('/thank-you/', $declined->getHeaderLine('Location'));
    }

    /**
     * A request carrying the session cookie a buyer mid-journey would have, so
     * the resolution these cases degrade past is one that was genuinely
     * attempted.
     */
    private function asBuyer(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams(['amd_session' => 'sess-outage-0123456789ab']);
    }

    /**
     * The durable half is the part that degrades, and it must leave a line
     * behind: an operator looking at a storefront that is selling normally
     * needs the log to say that no analytics session could be resolved.
     */
    public function testTheAnalyticsSessionStillDegradesSilentlyAndAudibly(): void
    {
        $logFile = sys_get_temp_dir() . '/unreachable-database-session-log-' . bin2hex(random_bytes(6)) . '.log';
        $app = AppFactory::create(dirname(__DIR__, 2), [
            OperatorLog::class => new OperatorLog($logFile),
        ]);

        try {
            $response = $app->handle(
                (new ServerRequestFactory())->createServerRequest('GET', '/')
                    ->withCookieParams(['amd_session' => 'sess-1234567890abcdef']),
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('session.resolve_failed', (string) file_get_contents($logFile));
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }
}

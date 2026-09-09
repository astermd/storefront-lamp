<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Which requests are allowed to mint an analytics session.
 *
 * Minting is not a local row: it is an outbound EMR call on the request's
 * critical path, and the session it creates is only reachable afterwards
 * through the cookie the response carries. A request that does not render one
 * of this storefront's pages leaves through the error handler, so no cookie is
 * ever added — the session is created and orphaned in the same breath, and
 * nothing can ever resume it.
 *
 * Unguarded that is unbounded rather than untidy. Every missing asset, every
 * mistyped URL and every scanner probing for `/wp-login.php` would create one,
 * and a deployment with no `public/favicon.ico` would mint a session per fresh
 * visitor before they saw a single page. That was live behaviour, found by
 * reading a wire log that showed two creates for one visit.
 */
final class SessionMintingScopeTest extends TestCase
{
    use TempDatabase;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @return array{0: App, 1: FakeSessionGateway} */
    private function app(): array
    {
        $gateway = new FakeSessionGateway(mintUuid: 'e2b1c9d4-5f60-4a7b-8c9d-0e1f2a3b4c5d');

        $app = AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            SessionGateway::class => $gateway,
        ]);

        return [$app, $gateway];
    }

    private function get(App $app, string $path): int
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path),
        )->getStatusCode();
    }

    /**
     * The defect, in the shape it was found: a page that does not exist.
     */
    public function testAPageThatDoesNotExistMintsNoSession(): void
    {
        [$app, $gateway] = $this->app();

        self::assertSame(404, $this->get($app, '/this-page-does-not-exist-zqx/'));
        self::assertSame([], $gateway->createCalls, 'a 404 reached the EMR and created a session');
    }

    /**
     * The one that made it happen on every fresh visit.
     *
     * There is no `public/favicon.ico`, so the browser's automatic request for
     * it falls through to PHP. Nobody types this URL; the browser issues it on
     * its own, which is what turned a leak into one-per-visitor.
     */
    public function testTheBrowsersAutomaticFaviconRequestMintsNoSession(): void
    {
        [$app, $gateway] = $this->app();

        self::assertSame(404, $this->get($app, '/favicon.ico'));
        self::assertSame([], $gateway->createCalls);
    }

    /**
     * A path that exists but not for this method.
     *
     * `/checkout/` answers both GET and POST, so the case worth pinning is a
     * POST-only route asked for with GET: routing resolves the *path* but not
     * the request, and a probe that only asked "is this path known" would let
     * it through.
     */
    public function testAKnownPathAskedWithTheWrongMethodMintsNoSession(): void
    {
        [$app, $gateway] = $this->app();

        $status = $this->get($app, '/intake/submit/');

        self::assertNotSame(200, $status);
        self::assertSame([], $gateway->createCalls);
    }

    /**
     * The absence that keeps the three above honest.
     *
     * A guard that mints for nothing would pass every test in this file. This
     * is the one that fails if the probe is made too strict, and it is why the
     * probe answers "yes" when it cannot decide: a resolver that throws must
     * cost a spurious session, never switch attribution off for the whole
     * site.
     */
    public function testARealPageStillMintsExactlyOneSession(): void
    {
        [$app, $gateway] = $this->app();

        self::assertSame(200, $this->get($app, '/'));
        self::assertCount(1, $gateway->createCalls, 'a real page must still start a journey');
    }

    /**
     * And the health endpoint stays excluded, as it always was.
     *
     * It is a route and it resolves, so the route probe alone would admit it —
     * the `exclude_paths` rule is what keeps a monitoring check from creating
     * a visitor every minute. Asserted here because the two rules now sit next
     * to each other and one could be mistaken for a replacement of the other.
     */
    public function testTheHealthEndpointIsStillExcludedFromMinting(): void
    {
        [$app, $gateway] = $this->app();

        $this->get($app, '/health/');

        self::assertSame([], $gateway->createCalls);
    }
}

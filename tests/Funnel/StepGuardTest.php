<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The step guard exercised through the whole app, because it depends on the
 * real middleware order (it sits between {@see \AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware}
 * and {@see \AsterMD\Storefront\Http\Middleware\TemplateGlobalsMiddleware}) and
 * on the real `config/funnel.php` rather than a hand-built {@see \AsterMD\Storefront\Funnel\FlowDefinition}.
 */
final class StepGuardTest extends TestCase
{
    use TempDatabase;

    /** A journey known to the fake gateway, so a cookie naming it is read back from the database rather than re-minted. */
    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [\PDO::class => $this->tempPdo(), ...$overrides]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function seedCart(array $lines): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function rxLine(string $slug, ?string $variantId): array
    {
        return [
            'slug' => $slug, 'name' => ucfirst($slug), 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => $variantId,
        ];
    }

    /** @return array<string, mixed> */
    private static function otcLine(string $slug = 'otc-item'): array
    {
        return [
            'slug' => $slug, 'name' => ucfirst($slug), 'kind' => 'otc',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 2500, 'variant_id' => null,
        ];
    }

    public function testCheckoutWithAnEmptyCartRedirectsHome(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testIntakeWithAnEmptyCartRedirectsHome(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/intake/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testIntakeIsReachableWithARxInTheCart(): void
    {
        $this->seedCart([self::rxLine('tadalafil', null)]);

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/intake/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Start My Assessment', (string) $response->getBody());
    }

    public function testCheckoutIsUnreachableUntilEveryRxLineHasAPlan(): void
    {
        $this->seedCart([self::rxLine('tadalafil', null)]);

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));

        // A line that cannot be priced is not a funnel problem the next step
        // can fix, so the routing decision answers home, where the mini-cart
        // drawer shows the cart intact.
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testCheckoutIsReachableWithAChosenPlan(): void
    {
        $this->seedCart([self::rxLine('tadalafil', 'tadalafil-1m')]);

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnAccessoryOnlyCartReachesCheckoutDirectly(): void
    {
        $this->seedCart([self::otcLine()]);

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * `home` carries no requirement in the shipped config, so this never
     * exercises the self-redirect check itself — see
     * {@see \AsterMD\Storefront\Tests\Http\Middleware\StepGuardMiddlewareTest::testAFailingRequirementNeverRedirectsAStepToItself()}
     * for a unit test that does. This one only pins that an ordinary,
     * unguarded home request is never redirected at all.
     */
    public function testAnUnguardedHomeRequestIsNeverRedirected(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    /**
     * `[8.6]`. The medical form can be completed without ever passing the
     * eligibility gate — `/intake/submit/` is not a funnel step and so is not
     * guarded — so checkout has to demand pre-qualification in its own right.
     * Demanding it only of the intake steps leaves the eligibility
     * questionnaire skippable by anyone who deep-links past them.
     */
    public function testCheckoutIsUnreachableWhileTheEligibilityFormIsOutstanding(): void
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $pdo))->insert(
            self::SESSION,
            ['reconciled' => true, 'form_status' => ['tf-medical' => 'completed']],
            null,
        );

        $app = $this->app([
            \PDO::class => $pdo,
            ProductCatalog::class => new FakeCatalog([
                'prequal-rx' => [
                    'slug' => 'prequal-rx', 'name' => 'Prequal Rx', 'kind' => 'rx', 'emr_product_id' => null,
                    'requires_prequalification' => true,
                    'prequalification_teleform_id' => 'tf-eligibility',
                    'teleform_id' => 'tf-medical',
                    'variants' => [['id' => 'prequal-rx-1m', 'name' => 'Monthly', 'price_cents' => 5000]],
                ],
            ]),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
        $this->seedCart([self::rxLine('prequal-rx', 'prequal-rx-1m')]);

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/checkout/')
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/intake/eligibility/', $response->getHeaderLine('Location'));
    }

    /**
     * `[20.1]`. A buyer who has just paid must reach their receipt through a
     * database blip. Session resolution is what an outage actually breaks
     * here: it leaves no journey at all, and a guard that reads "no journey"
     * as "no order" turns a five-second outage into a buyer who was charged
     * and shown the front page — or, with a prescription still in the session
     * cart, marched back to the questionnaire.
     *
     * The cart-backed requirements are untouched by this and must stay that
     * way; {@see \AsterMD\Storefront\Tests\Bootstrap\UnreachableDatabaseTest}
     * owns that half.
     */
    public function testTheReceiptAndUpsellStayReachableWhenTheJourneyCannotBeLoaded(): void
    {
        $restore = [];
        foreach (['DB_DRIVER' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'nothing_here'] as $key => $value) {
            $restore[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }

        try {
            // A prescription whose questionnaire the outage has made
            // unverifiable, so "fail closed everywhere" would route these two
            // pages at the intake step rather than merely home.
            $this->seedCart([self::rxLine('semaglutide', 'semaglutide-3m')]);
            $app = AppFactory::create(dirname(__DIR__, 2), [
                // The sample seed's semaglutide declares a teleform id; the
                // real config/products.generated.php a sync last wrote does not.
                ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            ]);

            // The receipt renders; the offer step admits the request and then
            // answers it with the receipt, because an unloadable journey has no
            // queue to step through. Both are the guard standing aside, which
            // is what this case exists to prove — so the offer step is checked
            // by where it sends the buyer, not by a status it no longer has a
            // reason to return.
            $receipt = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/thank-you/'));
            self::assertSame(200, $receipt->getStatusCode(), 'Expected 200 for /thank-you/ during an outage');

            $upsell = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/upsell/'));
            self::assertSame(303, $upsell->getStatusCode(), 'Expected /upsell/ to be admitted during an outage');
            self::assertSame(
                '/thank-you/',
                $upsell->getHeaderLine('Location'),
                'the offer step answered, so the guard did not refuse it',
            );
        } finally {
            foreach ($restore as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /**
     * The other direction, and the reason the two questions are answered
     * differently: an outage does not make an uncollected questionnaire safe
     * (`[8.6]`), so checkout stays shut while the receipt opens.
     */
    public function testCheckoutStaysShutWhenTheJourneyCannotBeLoaded(): void
    {
        $restore = [];
        foreach (['DB_DRIVER' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'nothing_here'] as $key => $value) {
            $restore[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }

        try {
            $this->seedCart([self::rxLine('semaglutide', 'semaglutide-3m')]);
            $app = AppFactory::create(dirname(__DIR__, 2), [
                // The sample seed's semaglutide declares a teleform id; the
                // real config/products.generated.php a sync last wrote does not.
                ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            ]);

            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));

            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/intake/medical/', $response->getHeaderLine('Location'));
        } finally {
            foreach ($restore as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /**
     * `/thank-you/` and `/upsell/` are deliberately absent: they now require a
     * placed order, and {@see \AsterMD\Storefront\Tests\Funnel\OrderPlacedPreconditionTest}
     * owns both sides of that guard.
     *
     * `/verify/` left this list when identity verification became a funnel
     * step (`[22.13]`). It was only ever unguarded because it was unlisted,
     * and an unlisted path is one {@see \AsterMD\Storefront\Funnel\FlowDefinition}
     * answers null for — which is the absence of a guard, not a decision to
     * leave one off. {@see testVerifyIsGuardedLikeEveryOtherStep} owns it.
     */
    public function testUnguardedPagesAreUntouched(): void
    {
        $app = $this->app();

        foreach (['/treatments/', '/not-eligible/', '/health/'] as $path) {
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));

            self::assertSame(200, $response->getStatusCode(), "Expected 200 for {$path}");
        }
    }

    /**
     * The other half of the line above: `/verify/` is guarded, and an empty
     * cart is turned away from it exactly as it is from every other funnel
     * step.
     *
     * This is the assertion that catches the page being reachable with nothing
     * in the cart.
     */
    public function testVerifyIsGuardedLikeEveryOtherStep(): void
    {
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/verify/'),
        );

        self::assertSame(302, $response->getStatusCode());
    }
}

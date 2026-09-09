<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\CartGateway;
use AsterMD\Storefront\Emr\CartMirrorResult;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Http\Controller\CartController;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\FakeCartGateway;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

/**
 * Full-app coverage of the cart HTTP surface (`/cart/add/`, `/cart/remove/`,
 * `/cart/quantity/`) plus the `cart` Twig global that `TemplateGlobalsMiddleware`
 * publishes for the drawer.
 *
 * Assertions about the view-model's *shape* — counts, per-line fields, the
 * continue link — read it off the Twig environment ({@see self::cartAfterGet()}),
 * because that is the contract between the middleware and any template, and
 * scraping HTML for it would couple these cases to one page's markup.
 * Anything the buyer is supposed to *see* is asserted against rendered output
 * instead: {@see \AsterMD\Storefront\Tests\Http\MiniCartTest} covers the
 * drawer, and the notice cases below cover the pages a mutation actually
 * lands on, because a global that is published but never rendered is exactly
 * the bug those cases exist to catch.
 */
final class CartControllerTest extends TestCase
{
    use TempDatabase;

    /** The one journey these cases resolve to; known to the fake gateway, so the cookie reads it back rather than re-minting. */
    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @param array<string, mixed> $overrides merged over a temp PDO, the same seam every other full-app test uses */
    /**
     * Defaults `ProductCatalog::class` to the theme's original sample seed
     * (`SampleCatalog`) rather than whatever `bin/console theme:sync --apply`
     * currently has synced into the real `config/products.generated.php` —
     * the cases below assert against specific sample slugs, prices and
     * teleform ids, so they inject that catalog explicitly instead of
     * depending on it accidentally. Any `$overrides` entry for
     * `ProductCatalog::class` (the otc/mirrorable/bundle catalogs below) wins
     * over this default.
     */
    private function app(array $overrides = []): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            ...$overrides,
        ]);
    }

    /** Seeds the CSRF token with a GET, exactly as {@see \AsterMD\Storefront\Tests\Http\CsrfTest} does. */
    private function csrfToken(App $app): string
    {
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        return (string) $_SESSION['_csrf'];
    }

    /**
     * Performs a GET against $path and returns whatever the `cart` Twig
     * global held afterwards — the published view-model, read the same way a
     * template would once one is wired to it.
     *
     * @return array<string, mixed>
     */
    private function cartAfterGet(App $app, string $path = '/'): array
    {
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        $globals = $app->getContainer()?->get(Twig::class)->getEnvironment()->getGlobals() ?? [];

        return is_array($globals['cart'] ?? null) ? $globals['cart'] : [];
    }

    private function post(App $app, string $path, array $body, ?string $sessionUuid = null): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body);
        if ($sessionUuid !== null) {
            $request = $request->withCookieParams(['amd_session' => $sessionUuid]);
        }

        return $app->handle($request);
    }

    /**
     * One `otc` product with no bundle, no `requires_prequalification`: the
     * shipped seed has no `otc` product at all, so anything that needs one
     * (routing to checkout, quantity changes) supplies it through the
     * `ProductCatalog::class` container alias rather than the real catalog.
     */
    private function otcCatalog(): FakeCatalog
    {
        return new FakeCatalog([
            'accessory' => [
                'slug' => 'accessory',
                'name' => 'Accessory',
                'kind' => 'otc',
                'emr_product_id' => null,
                'variants' => [['id' => 'accessory-1', 'name' => 'Standard', 'price_cents' => 1999]],
            ],
        ]);
    }

    /**
     * One `otc` product carrying an `emr_product_id` — the shipped seed sets
     * `emr_product_id => null` on every product, so a test asserting mirror
     * calls has to supply one of its own; this is not a change to the seed.
     */
    private function mirrorableCatalog(): FakeCatalog
    {
        return new FakeCatalog([
            'mirrored' => [
                'slug' => 'mirrored',
                'name' => 'Mirrored Product',
                'kind' => 'otc',
                'emr_product_id' => 'emr-mirrored-1',
                'variants' => [['id' => 'mirrored-1', 'name' => 'Standard', 'price_cents' => 2500]],
            ],
        ]);
    }

    /** A parent that bundles a child, so removing the child directly must be refused. */
    private function bundleCatalog(): FakeCatalog
    {
        return new FakeCatalog([
            'parent-product' => [
                'slug' => 'parent-product',
                'name' => 'Parent Product',
                'kind' => 'otc',
                'emr_product_id' => null,
                'variants' => [['id' => 'parent-1', 'name' => 'Standard', 'price_cents' => 3000]],
                'bundles' => ['child-product'],
            ],
            'child-product' => [
                'slug' => 'child-product',
                'name' => 'Child Product',
                'kind' => 'lab',
                'emr_product_id' => null,
                'variants' => [['id' => 'child-1', 'name' => 'Standard', 'price_cents' => 1000]],
            ],
        ]);
    }

    public function testAddingAProductPutsItInTheCartAndRedirectsToTheRoutingDecision(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'semaglutide',
            'variant_id' => 'semaglutide-3m',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/intake/medical/', $response->getHeaderLine('Location'));

        $cart = $this->cartAfterGet($app);
        self::assertSame(1, $cart['count']);
    }

    /**
     * The product page's "Proceed to Checkout" button posts `intent=checkout`
     * to skip the funnel-optimal routing decision — unlike
     * {@see self::testAddingAProductPutsItInTheCartAndRedirectsToTheRoutingDecision()},
     * whose Rx line has the same outstanding questionnaire but no `intent`,
     * and is routed to `/intake/medical/` instead.
     */
    public function testAddingWithCheckoutIntentSkipsStraightToCheckout(): void
    {
        $app = $this->journeyApp([]);
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'med-1',
            'variant_id' => 'med-1-1m',
            'intent' => 'checkout',
        ], self::SESSION);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));
    }

    /**
     * `intent=checkout` only names a redirect target — it does not bypass
     * `[8.1]`'s guard. A visitor sent to `/checkout/` with an outstanding
     * questionnaire still gets bounced to it on arrival, exactly as a bare
     * deep link to `/checkout/` would be.
     */
    public function testCheckoutIntentStillBouncesOffAnUnsatisfiedQuestionnaireOnArrival(): void
    {
        $app = $this->journeyApp([]);
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'med-1',
            'variant_id' => 'med-1-1m',
            'intent' => 'checkout',
        ], self::SESSION);

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/checkout/')
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/intake/medical/', $response->getHeaderLine('Location'));
    }

    public function testAddingAnAccessoryRoutesToCheckout(): void
    {
        $app = $this->app([ProductCatalog::class => $this->otcCatalog()]);
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'accessory',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));
    }

    public function testAddingWithoutACsrfTokenIs419(): void
    {
        $app = $this->app();
        $this->csrfToken($app);

        $response = $this->post($app, '/cart/add/', [
            'slug' => 'semaglutide',
        ]);

        self::assertSame(419, $response->getStatusCode());

        $cart = $this->cartAfterGet($app);
        self::assertSame(0, $cart['count']);
    }

    public function testARejectedAddFlashesTheReasonAndRedirectsBack(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'not-a-product',
            'return_to' => '/products/semaglutide/',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/products/semaglutide/', $response->getHeaderLine('Location'));

        $cart = $this->cartAfterGet($app, '/products/semaglutide/');
        self::assertSame(CartRules::UNKNOWN_PRODUCT, $cart['notice']);
    }

    /**
     * `[8.0h]` allows a second prescription to replace the first *because* the
     * replacement is visible. An accepted add redirects to the funnel's next
     * step, which for a prescription with no plan chosen yet is the home page,
     * so that is where the sentence has to appear — and reading it off the Twig
     * global would not have noticed that no template there rendered it.
     */
    public function testTheReplacementNoticeIsVisibleOnThePageAnAcceptedAddLandsOn(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'ramelteon']);
        $response = $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'semaglutide']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));

        $landing = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        $body = (string) $landing->getBody();

        self::assertSame(200, $landing->getStatusCode());
        self::assertStringContainsString(
            sprintf(CartRules::RX_REPLACED, 'Ramelteon', 'Semaglutide'),
            $body,
        );
        self::assertStringContainsString('role="status"', $body);
    }

    /**
     * The rejected half of the same claim, asserted against rendered output on
     * a marketing page — the drawer that used to be the only render site is
     * `hidden` until script opens it.
     */
    public function testARejectedAddsNoticeIsVisibleOnTheMarketingPageItReturnsTo(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'not-a-product',
            'return_to' => '/treatments/',
        ]);

        $landing = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/treatments/'));
        $body = (string) $landing->getBody();

        self::assertStringContainsString(CartRules::UNKNOWN_PRODUCT, $body);
        self::assertSame(1, substr_count($body, CartRules::UNKNOWN_PRODUCT));
    }

    public function testTheNoticeIsShownOnceOnly(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'not-a-product',
            'return_to' => '/products/semaglutide/',
        ]);

        $this->cartAfterGet($app, '/products/semaglutide/');
        $second = $this->cartAfterGet($app, '/products/semaglutide/');

        self::assertNull($second['notice']);
    }

    public function testRemovingALineRedirectsBackToWhereTheBuyerWas(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'semaglutide']);

        $response = $this->post($app, '/cart/remove/', [
            '_csrf' => $token,
            'slug' => 'semaglutide',
            'return_to' => '/treatments/',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/treatments/', $response->getHeaderLine('Location'));

        $cart = $this->cartAfterGet($app);
        self::assertSame(0, $cart['count']);
    }

    public function testRemovingABundledChildIsRefusedWithTheParentNamed(): void
    {
        $app = $this->app([ProductCatalog::class => $this->bundleCatalog()]);
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'parent-product']);

        $response = $this->post($app, '/cart/remove/', [
            '_csrf' => $token,
            'slug' => 'child-product',
            'return_to' => '/treatments/',
        ]);

        self::assertSame(303, $response->getStatusCode());

        $cart = $this->cartAfterGet($app);
        $slugs = array_column($cart['lines'], 'slug');
        self::assertContains('child-product', $slugs);
        self::assertSame(
            sprintf(CartRules::CHILD_LOCKED, 'Child Product', 'Parent Product'),
            $cart['notice'],
        );
    }

    public function testQuantityUpdatesTheLine(): void
    {
        $app = $this->app([ProductCatalog::class => $this->otcCatalog()]);
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'accessory']);

        $response = $this->post($app, '/cart/quantity/', [
            '_csrf' => $token,
            'slug' => 'accessory',
            'quantity' => 2,
            'return_to' => '/treatments/',
        ]);

        self::assertSame(303, $response->getStatusCode());

        $cart = $this->cartAfterGet($app);
        self::assertSame(2, $cart['count']);
    }

    /**
     * `[return_to sanitisation]`: an unchecked `Location` is an open
     * redirect, so every value here must fall back to `/` rather than being
     * echoed back verbatim.
     */
    public function testAnOpenRedirectInReturnToIsRefused(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $malicious = [
            'https://evil.example/',
            '//evil.example/',
            "/\\evil",
            'javascript:alert(1)',
            "/ok\nEvil-Header: 1",
            '',
        ];

        foreach ($malicious as $returnTo) {
            $response = $this->post($app, '/cart/remove/', [
                '_csrf' => $token,
                'slug' => 'not-a-product',
                'return_to' => $returnTo,
            ]);

            self::assertSame(
                '/',
                $response->getHeaderLine('Location'),
                sprintf('return_to=%s must fall back to "/".', $returnTo),
            );
        }
    }

    public function testEveryAcceptedMutationMirrorsToTheEmr(): void
    {
        $sessionGateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        $cartGateway = new FakeCartGateway();
        $app = $this->app([
            ProductCatalog::class => $this->mirrorableCatalog(),
            SessionGateway::class => $sessionGateway,
            CartGateway::class => $cartGateway,
        ]);
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'mirrored',
        ], 'sess-1234567890abcdef');

        self::assertCount(1, $cartGateway->createCalls);
        self::assertCount(0, $cartGateway->updateCalls);

        $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'mirrored',
        ], 'sess-1234567890abcdef');

        self::assertCount(1, $cartGateway->createCalls);
        self::assertCount(1, $cartGateway->updateCalls);
    }

    public function testARejectedMutationNeverMirrors(): void
    {
        $cartGateway = new FakeCartGateway();
        $app = $this->app([
            SessionGateway::class => new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef'),
            CartGateway::class => $cartGateway,
        ]);
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'not-a-product',
        ], 'sess-1234567890abcdef');

        self::assertCount(0, $cartGateway->createCalls);
        self::assertCount(0, $cartGateway->updateCalls);
    }

    /** `[7.12]` */
    public function testAMirrorFailureDoesNotBreakTheAdd(): void
    {
        $cartGateway = new FakeCartGateway(createResult: CartMirrorResult::Failed);
        $app = $this->app([
            ProductCatalog::class => $this->mirrorableCatalog(),
            SessionGateway::class => new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef'),
            CartGateway::class => $cartGateway,
        ]);
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'mirrored',
        ], 'sess-1234567890abcdef');

        self::assertSame(303, $response->getStatusCode());

        $cart = $this->cartAfterGet($app);
        self::assertSame(1, $cart['count']);
    }

    /**
     * The default test container binds the Null gateways and no cookie names
     * a session, which is exactly the visitor `AttributionMiddleware` never
     * mints one for on a POST: the cart must still save to the PHP session
     * and mutate correctly even though the mirror is skipped entirely.
     */
    public function testAddingWithNoAnalyticsSessionStillWorks(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'semaglutide',
        ]);

        self::assertSame(303, $response->getStatusCode());

        $cart = $this->cartAfterGet($app);
        self::assertSame(1, $cart['count']);
    }

    /**
     * `Url::canonicalizePath()` lowercases and trailing-slashes the whole
     * string, which is correct for a real path but wrong applied to a query
     * string riding along with it — `safePath()` must split the value at the
     * first `?` and canonicalise only the path half.
     */
    public function testAReturnToWithAQueryStringKeepsItsCaseAndGainsNoTrailingSlash(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/remove/', [
            '_csrf' => $token,
            'slug' => 'not-a-product',
            'return_to' => '/products/Semaglutide?ref=ABC',
        ]);

        self::assertSame('/products/semaglutide/?ref=ABC', $response->getHeaderLine('Location'));
    }

    public function testAControlCharacterInsideTheReturnToQueryStillFallsBackToRoot(): void
    {
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/cart/remove/', [
            '_csrf' => $token,
            'slug' => 'not-a-product',
            'return_to' => "/products/semaglutide/?ref=1\nEvil-Header: 1",
        ]);

        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    /**
     * Both funnel steps `lineViewModel()` resolves a continue link from
     * (`intake`, `checkout`) exist in the shipped `config/funnel.php` today,
     * so this is unreachable in production — but the cart global is
     * published on every page, and a future edit that renames or drops one
     * of those steps must not turn a cart-drawer detail into a sitewide 500.
     * A `FlowDefinition` missing `checkout` is substituted through the
     * container to force that gap.
     */
    public function testAMissingFlowStepLeavesTheLineWithNoContinueLinkRatherThanFatalling(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => 'seeded', 'name' => 'Seeded Product', 'kind' => 'otc',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => null,
        ]]];

        $flow = new FlowDefinition([
            'home' => ['path' => '/', 'requires' => []],
            'intake' => ['path' => '/intake/', 'requires' => []],
        ]);
        $app = $this->app([FlowDefinition::class => $flow]);

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());

        $cart = $this->cartAfterGet($app);
        self::assertNull($cart['lines'][0]['continue_url']);
        self::assertNull($cart['lines'][0]['continue_label']);
    }

    public function testANonNumericQuantityIsRejectedWithoutChangingTheCart(): void
    {
        $app = $this->app([ProductCatalog::class => $this->otcCatalog()]);
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'accessory']);

        $response = $this->post($app, '/cart/quantity/', [
            '_csrf' => $token,
            'slug' => 'accessory',
            'quantity' => 'abc',
            'return_to' => '/treatments/',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/treatments/', $response->getHeaderLine('Location'));

        $cart = $this->cartAfterGet($app);
        self::assertSame(1, $cart['count']);
        self::assertSame(CartController::INVALID_QUANTITY, $cart['notice']);
    }

    /**
     * The drawer is published on every page, so a `CartStore` that cannot be
     * built at all must cost the buyer a drawer, not the site. The database is
     * no longer able to cause that — {@see \AsterMD\Storefront\Repository\SessionRepository}
     * connects on first query — so the failure is forced through the container
     * instead, which is the only way left to reach the boundary
     * `TemplateGlobalsMiddleware::resolveCartStore()` exists to hold.
     */
    public function testACartStoreThatCannotBeBuiltLeavesAnEmptyDrawerRatherThanASitewide500(): void
    {
        $logFile = sys_get_temp_dir() . '/cart-globals-unavailable-' . bin2hex(random_bytes(6)) . '.log';
        $app = $this->app([
            OperatorLog::class => new OperatorLog($logFile),
            CartStore::class => static fn (): CartStore => throw new \RuntimeException('store unavailable'),
        ]);

        try {
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Your cart is empty.', (string) $response->getBody());
            self::assertStringContainsString('cart.globals_unavailable', (string) file_get_contents($logFile));
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }

    /** A stepper genuinely sending `0` must still remove the line — only a garbled request is refused. */
    public function testAGenuineZeroQuantityStillRemovesTheLine(): void
    {
        $app = $this->app([ProductCatalog::class => $this->otcCatalog()]);
        $token = $this->csrfToken($app);

        $this->post($app, '/cart/add/', ['_csrf' => $token, 'slug' => 'accessory']);

        $response = $this->post($app, '/cart/quantity/', [
            '_csrf' => $token,
            'slug' => 'accessory',
            'quantity' => 0,
            'return_to' => '/treatments/',
        ]);

        self::assertSame(303, $response->getStatusCode());

        $cart = $this->cartAfterGet($app);
        self::assertSame(0, $cart['count']);
    }

    // ---- the routing decision is asked with the journey in hand -------------

    /**
     * `[8.1]`. The routing decision is completion-aware, and it can only be
     * that with the journey in hand. Asked without one, it answers with a
     * questionnaire the visitor has already finished — so adding a $12
     * accessory would march a buyer back through the medical intake they
     * completed a moment earlier.
     */
    public function testAnAcceptedAddNeverRoutesBackToAFinishedQuestionnaire(): void
    {
        $app = $this->journeyApp(['form_status' => ['tf-medical' => 'completed']]);
        $token = $this->csrfToken($app);
        $this->addTheRxLine($app, $token);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'accessory',
        ], self::SESSION);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));
    }

    /**
     * The same argument pointed at the other branch the journey owns: a
     * disqualified visitor is owed the page that explains why (`[10.46]`), and
     * only the journey knows they are one.
     */
    public function testAnAcceptedAddOnADisqualifiedJourneyRoutesToTheTerminalPage(): void
    {
        $app = $this->journeyApp([
            'form_status' => ['tf-medical' => 'completed'],
            'disqualified_rule' => 'bmi_low_hard_stop_notice',
            'disqualified_teleform' => 'tf-medical',
        ]);
        $token = $this->csrfToken($app);
        $this->addTheRxLine($app, $token);

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'accessory',
        ], self::SESSION);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/not-eligible/', $response->getHeaderLine('Location'));
    }

    /**
     * A full app whose cookie resolves to a journey seeded with $journeyState,
     * over a catalog of one prescription that collects `tf-medical` and one
     * accessory that collects nothing.
     *
     * The database has to be built before the app so the row can be written
     * into the same file the container will read from; the shipped seed has no
     * `otc` product at all, hence the catalog override the other cases here
     * already use.
     *
     * @param array<string, mixed> $journeyState
     */
    private function journeyApp(array $journeyState): App
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $pdo))
            ->insert(self::SESSION, ['reconciled' => true, ...$journeyState], null);

        return $this->app([
            \PDO::class => $pdo,
            ProductCatalog::class => new FakeCatalog([
                'med-1' => [
                    'slug' => 'med-1', 'name' => 'Med One', 'kind' => 'rx', 'emr_product_id' => null,
                    'teleform_id' => 'tf-medical',
                    'variants' => [['id' => 'med-1-1m', 'name' => 'Monthly', 'price_cents' => 5000]],
                ],
                'accessory' => [
                    'slug' => 'accessory', 'name' => 'Accessory', 'kind' => 'otc', 'emr_product_id' => null,
                    'variants' => [['id' => 'accessory-1', 'name' => 'Standard', 'price_cents' => 1999]],
                ],
            ]),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
    }

    /**
     * Puts the prescription in the cart over HTTP rather than by writing
     * `$_SESSION` directly.
     *
     * {@see CartStore} memoises the cart it resolved for the life of the
     * container, and these cases drive several requests through one app — so a
     * cart seeded into the session after the first request is never read. The
     * add is the storefront's own way of filling a cart in any case.
     */
    private function addTheRxLine(App $app, string $token): void
    {
        $this->post($app, '/cart/add/', [
            '_csrf' => $token,
            'slug' => 'med-1',
            'variant_id' => 'med-1-1m',
        ], self::SESSION);
    }
}

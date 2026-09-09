<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\CheckoutService;
use AsterMD\Storefront\Checkout\Consents;
use AsterMD\Storefront\Checkout\EmrCheckoutEventReporter;
use AsterMD\Storefront\Checkout\NullCheckoutEventReporter;
use AsterMD\Storefront\Emr\ClientFactory as EmrClientFactory;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Checkout\NullOrderRecorder;
use AsterMD\Storefront\Checkout\OrderBumps;
use AsterMD\Storefront\Checkout\OrderRecorder;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Upsell\Upsells;
use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\NullVerificationGateway;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Emr\VerificationGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\NullTeleformGateway;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Http\Controller\CheckoutController;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Payment\RedeclaredAdapter;
use AsterMD\Storefront\Tests\Support\CapturedLog;
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
 * The checkout HTTP surface, driven through the whole application the way
 * {@see CartControllerTest} drives the cart and {@see IntakeControllerTest}
 * drives the questionnaire.
 *
 * The application is the real one — `AppFactory::create()` with the container
 * overrides {@see self::app()} names, and nothing else substituted, so what is
 * under test is the shipped wiring rather than a hand-assembled imitation of
 * it. The payment adapter is likewise the real one, driven over
 * {@see FakeVrioTransport} and the recorded fixtures, so a placement exercises
 * the whole payload assembly with no packet leaving the process — a test that
 * reached the provider would place a real order.
 *
 * Two of these cases are here for what they would have caught rather than what
 * they describe. The state `<select>` is asserted for its two-letter option
 * values, because the design mockup carried none and a form posting
 * "New York" where the server accepts "NY" fails validation for every buyer in
 * the state. And the re-rendered form after a decline is asserted for what it
 * does *not* contain: a card echoed into a `value` attribute lives on in the
 * browser's back-forward cache and in every proxy that logs a response body.
 */
final class CheckoutControllerTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = 'sess-1234567890abcdef';

    private const string CARD = '4111111100084444';

    private \PDO $pdo;

    private FakeVrioTransport $transport;

    private CapturedLog $log;

    private string $cacheDir;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cacheDir = sys_get_temp_dir() . '/checkout-controller-' . bin2hex(random_bytes(6));
        $this->log = new CapturedLog();
        $this->transport = new FakeVrioTransport();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function testTheCheckoutPageRendersPrefilledValuesInItsInputs(): void
    {
        // `[13.5b]`/`[13.5c]`: what the journey already knows is in the boxes,
        // in the `value` attributes rather than only in the page's data.
        $this->seedCart();
        $app = $this->app();
        $this->seedBuyer($app);

        $response = $app->handle($this->get('/checkout/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="ada@example.com"', $body);
        self::assertStringContainsString('value="350 5th Avenue"', $body);
        self::assertStringContainsString('content="noindex', $body, '[24.6]: a funnel step is never indexed');
    }

    public function testTheConsentCheckboxesRenderUnchecked(): void
    {
        // `[26.2]`: consent is an explicit affirmative action. The mockup
        // pre-checked both of its boxes; nothing here ever does.
        $this->seedCart();

        $body = (string) $this->app()->handle($this->get('/checkout/'))->getBody();

        self::assertStringContainsString('name="consents[terms]"', $body);
        self::assertMatchesRegularExpression('/name="consents\[terms\]"[^>]*\/>/', $body);
        self::assertDoesNotMatchRegularExpression('/name="consents\[[a-z_]+\]"[^>]*checked/', $body);
    }

    public function testTheStateSelectOffersEveryTerritoryTheServerAcceptsAsItsTwoLetterCode(): void
    {
        // The mockup's options carried no `value` at all, so every one of them
        // would have posted its display name. `BuyerValidator` accepts two
        // letters, so a buyer in New York could never have completed an order.
        $this->seedCart();

        $body = (string) $this->app()->handle($this->get('/checkout/'))->getBody();

        self::assertStringContainsString('<option value="NY">New York</option>', $body);
        self::assertStringContainsString('<option value="CA">California</option>', $body);
        self::assertStringContainsString('<option value="PR">Puerto Rico</option>', $body);
    }

    public function testThePrefilledTerritoryIsTheSelectedOption(): void
    {
        $this->seedCart();
        $app = $this->app();
        $this->seedBuyer($app);

        $body = (string) $app->handle($this->get('/checkout/'))->getBody();

        self::assertStringContainsString('<option value="CA" selected>California</option>', $body);
    }

    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $this->seedCart();

        $response = $this->post($this->app(), '/checkout/', $this->submission(csrf: 'wrong'));

        self::assertSame(419, $response->getStatusCode());
        self::assertSame([], $this->transport->requests, 'nothing reached the provider');
    }

    public function testARateLimitedPostIsRefusedWith429(): void
    {
        // `[13.8]`: refused before anything costs money, and answered with a
        // status a client can back off on rather than a 200 that looks fine.
        $this->seedCart();
        $app = $this->app(rateLimit: 0);

        $response = $this->post($app, '/checkout/', $this->submission(csrf: $this->csrfToken($app)));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([], $this->transport->requests);
    }

    public function testADeclinedPostReRendersWithTheProvidersOwnReasonAndTheFormStillFilled(): void
    {
        // `[13.28]`: the reason is shown verbatim. `[13.30]`: the cart is
        // intact, the buyer stays on checkout, and the form re-fills.
        $this->seedCart();
        $app = $this->app('vrio-order-declined.json');

        $response = $this->post($app, '/checkout/', $this->submission(csrf: $this->csrfToken($app)));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Failed test transaction', $body);
        self::assertStringContainsString('value="ada@example.com"', $body);
        self::assertStringContainsString('value="350 5th Avenue"', $body);
    }

    public function testTheCardNumberNeverAppearsInTheReRenderedFormAfterADecline(): void
    {
        // The buyer's contact details are preserved so the form re-fills
        // ([13.30]) -- the card is not a contact detail. Echoing it into a
        // value attribute puts it in the browser's back-forward cache and in
        // any proxy that logs response bodies.
        $this->seedCart();
        $app = $this->app('vrio-order-declined.json');

        $response = $this->post($app, '/checkout/', $this->submission(csrf: $this->csrfToken($app)));

        self::assertStringNotContainsString(self::CARD, (string) $response->getBody());
    }

    public function testNoCardNumberReachesTheOperatorLogAfterADeclinedPost(): void
    {
        // Redaction is key-based and cannot see a number inside a free-text
        // string, so this asserts against the real logger's own file.
        $this->seedCart();
        $app = $this->app('vrio-order-declined.json');

        $this->post($app, '/checkout/', $this->submission(csrf: $this->csrfToken($app)));

        self::assertStringNotContainsString(self::CARD, $this->log->contents());
    }

    public function testAnUngrantedBlockingConsentReRendersWithTheMessageBesideItsControl(): void
    {
        // `[26.5]`: the order stops, and the buyer is told which control it was.
        $this->seedCart();
        $app = $this->app();

        $response = $this->post($app, '/checkout/', $this->submission(csrf: $this->csrfToken($app), consents: []));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="checkout-error-terms"', $body);
        self::assertStringContainsString(CheckoutService::CONSENT_REQUIRED, $body);
        self::assertSame([], $this->transport->requests);
    }

    public function testAGeoBlockedPostNamesTheBlockedProductAndKeepsTheCartIntact(): void
    {
        // `[13.6]`, `[13.7]`: the gate is re-run against the submitted
        // territory, the message names the products, and nothing is removed.
        $this->seedCart();
        $app = $this->app();

        $response = $this->post($app, '/checkout/', $this->submission(
            csrf: $this->csrfToken($app),
            territory: 'NY',
            postalCode: '10118',
        ));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Blocked Thing', $body);
        self::assertStringContainsString('Tirzepatide', $body, 'the cart is still on the page');
        self::assertSame([], $this->transport->requests);
    }

    public function testAnInvalidFieldReRendersWithItsMessageAndNeverReachesTheProvider(): void
    {
        $this->seedCart();
        $app = $this->app();

        $response = $this->post($app, '/checkout/', $this->submission(
            csrf: $this->csrfToken($app),
            postalCode: '123',
        ));

        self::assertStringContainsString('id="checkout-error-postal_code"', (string) $response->getBody());
        self::assertSame([], $this->transport->requests);
    }

    public function testASuccessfulPostRedirectsOffCheckout(): void
    {
        // `[13.30]` in the negative: a placed order is the one outcome that
        // moves the buyer on, and a 303 means a reload replays the GET rather
        // than the payment.
        $this->seedCart();
        $app = $this->app();

        $response = $this->post($app, '/checkout/', $this->submission(csrf: $this->csrfToken($app)));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/upsell/', $response->getHeaderLine('Location'));
        self::assertCount(1, $this->transport->requests);
    }

    public function testTogglingABumpRedirectsBackToCheckoutAndPreservesTypedContactDetails(): void
    {
        // `[27.10]`: the summary updates without leaving the page and without
        // losing anything already typed. `[27.11]`: the acceptance goes through
        // CartRules like any other add.
        $this->seedCart();
        $app = $this->app(bumps: self::bumpConfig());

        $response = $this->post($app, '/checkout/bump/', [
            '_csrf' => $this->csrfToken($app),
            'bump_slug' => 'pill-organizer',
        ] + $this->carriedFields());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));

        $body = (string) $app->handle($this->get('/checkout/'))->getBody();
        self::assertStringContainsString('value="ada@example.com"', $body, 'nothing typed was lost');
        self::assertStringContainsString('Pill Organizer', $body, 'the bump is now a cart line');
    }

    public function testAnAcceptedBumpIsChargedAtItsOverriddenPriceAndNotTheCatalogPrice(): void
    {
        // `[27.12]`: the reduced price applies to that line only and does not
        // alter the catalog.
        $this->seedCart();
        $app = $this->app(bumps: self::bumpConfig());

        $this->post($app, '/checkout/bump/', [
            '_csrf' => $this->csrfToken($app),
            'bump_slug' => 'pill-organizer',
        ] + $this->carriedFields());

        $body = (string) $app->handle($this->get('/checkout/'))->getBody();

        self::assertStringContainsString('$120.99', $body, 'the subtotal moved by the bump price, not the catalog price');
    }

    public function testAcceptingAndWithdrawingABumpBothReachTheLocalTrail(): void
    {
        // `[27.13]`. Local only, and deliberately: the EMR's checkout-event
        // vocabulary has no case for a bump, and a bump is an ordinary line on
        // the main charge rather than a second order — so the funnel record
        // that matters is the one the placement writes. Before this, the two
        // decisions were recorded nowhere at all.
        $this->seedCart();
        $app = $this->app(bumps: self::bumpConfig(), recordEvents: true);

        $accept = ['_csrf' => $this->csrfToken($app), 'bump_slug' => 'pill-organizer'] + $this->carriedFields();
        $this->post($app, '/checkout/bump/', $accept);
        $this->post($app, '/checkout/bump/', ['_csrf' => $this->csrfToken($app)] + $accept);

        $trail = array_column(
            $this->pdo->query('SELECT name, payload FROM events ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
            'name',
        );

        self::assertSame(
            ['checkout.bump_accepted', 'checkout.bump_withdrawn'],
            array_values(array_filter($trail, static fn (string $name): bool => str_starts_with($name, 'checkout.bump'))),
            'the accept and the withdrawal are distinguishable, not one repeated event',
        );
    }

    public function testThePromoControlIsAbsentWhenTheAdapterDeclaresNoPromotionSupport(): void
    {
        // `[13.18]`, `[14.4]`: the storefront adapts its UI rather than the
        // capability failing at runtime.
        $this->seedCart();

        $body = (string) $this->app(promotions: false)->handle($this->get('/checkout/'))->getBody();

        self::assertStringNotContainsString('name="discount_code"', $body);
    }

    public function testThePromoControlIsPresentWhenTheAdapterDoesSupportPromotions(): void
    {
        $this->seedCart();

        $body = (string) $this->app()->handle($this->get('/checkout/'))->getBody();

        self::assertStringContainsString('name="discount_code"', $body);
    }

    public function testSwitchingThePlanRepricesTheRxLineAndReturnsToCheckout(): void
    {
        // `[12.4]`, `[12.7]`: a variant is a plan, so choosing one is what
        // prices the line.
        $this->seedCart();
        $app = $this->app();

        $response = $this->post($app, '/checkout/plan/', [
            '_csrf' => $this->csrfToken($app),
            'slug' => 'tirzepatide',
            'variant_id' => 't-1m',
        ] + $this->carriedFields());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));

        $body = (string) $app->handle($this->get('/checkout/'))->getBody();
        self::assertStringContainsString('$140.00', $body);
    }

    public function testNoKeystrokeCanPlaceAnOrder(): void
    {
        // Implicit submission clicks a form's first submit button in tree
        // order, whatever field the cursor is in. With one form spanning the
        // page that would be Pay Now, so a buyer typing a discount code and
        // pressing Enter would be charged the full amount -- the discount box
        // is exactly where people press Enter. The first submit button is
        // therefore the promo apply, which is what Enter in that box should do
        // and is harmless anywhere else. Paying stays a deliberate click.
        $this->seedCart();

        $body = (string) $this->app()->handle($this->get('/checkout/'))->getBody();

        $firstSubmit = strpos($body, 'type="submit"');
        $payNow = strpos($body, 'id="checkout-pay"');

        self::assertIsInt($firstSubmit);
        self::assertIsInt($payNow);
        self::assertLessThan($payNow, $firstSubmit, 'Pay Now must not be the default button');
        self::assertStringContainsString(
            '<button type="submit" formaction="/checkout/promo/" class="sr-only"',
            $body,
            'the default button applies the discount code',
        );
    }

    public function testTheWholePageIsOneFormAndEverySubActionIsAFormactionButtonOnIt(): void
    {
        // The shape is the fix. A form cannot be nested inside another form, so
        // sibling forms in the summary column could only ever post the values
        // the server had already rendered — and a bump toggle would write those
        // stale values back over whatever the buyer had typed since. One form
        // spanning both columns, with each sub-action naming its own target
        // through `formaction`, is what makes every sub-action post what is in
        // the boxes right now, with no script involved ([27.10], [8.7]).
        //
        // The count is asserted, not just the presence: it is what stops a
        // sibling form reappearing here unnoticed.
        $this->seedCart();

        $body = (string) $this->app(bumps: self::bumpConfig())->handle($this->get('/checkout/'))->getBody();

        self::assertSame(1, substr_count($body, '<form'), 'the checkout page is exactly one form');
        self::assertSame(1, substr_count($body, 'name="_csrf"'), 'which carries the CSRF token once');
        self::assertStringContainsString('action="/checkout/" id="checkout-form"', $body);

        // Each sub-action redirects that one form, and names its target on the
        // button so only the row actually clicked is submitted.
        self::assertStringContainsString('formaction="/checkout/plan/" name="variant_id" value="t-1m"', $body);
        self::assertStringContainsString('formaction="/checkout/promo/"', $body);
        self::assertStringContainsString('formaction="/checkout/bump/" name="bump_slug" value="pill-organizer"', $body);

        // The workaround the single form replaces is gone, wholesale.
        self::assertStringNotContainsString('data-carry-field', $body);
    }

    public function testASubActionStoresWhatWasPostedRatherThanWhatThePageWasRenderedWith(): void
    {
        // The defect the single form fixes, pinned: the buyer had corrected the
        // address the page was rendered with and had not submitted the order
        // yet when they added a bump. What the sub-action posts is what the
        // re-rendered page must come back with ([27.10]).
        $this->seedCart();
        $app = $this->app(bumps: self::bumpConfig());
        $this->seedBuyer($app);

        $response = $this->post($app, '/checkout/bump/', [
            '_csrf' => $this->csrfToken($app),
            'bump_slug' => 'pill-organizer',
            'email' => 'grace@example.com',
            'address_line' => '1 Navy Yard',
        ] + $this->carriedFields());

        self::assertSame(303, $response->getStatusCode());

        $body = (string) $app->handle($this->get('/checkout/'))->getBody();

        self::assertStringContainsString('value="grace@example.com"', $body, 'the typed email survived the toggle');
        self::assertStringContainsString('value="1 Navy Yard"', $body, 'and so did the typed address');
        self::assertStringNotContainsString('value="ada@example.com"', $body, 'the rendered-with value did not win');
        self::assertStringNotContainsString('value="350 5th Avenue"', $body);
        self::assertStringContainsString('Pill Organizer', $body, 'and the bump is a cart line');
    }

    public function testOnlyThePlacementSubmitCarriesTheDoubleSubmitHook(): void
    {
        // theme/js/checkout.js keeps the buyer from clicking Pay Now twice by
        // eye, and it finds that one button by a hook attribute rather than by
        // position or class. That makes this a markup contract: the hook has to
        // sit on the placement submit and on nothing else.
        //
        // Both halves matter. Lose it from Pay Now and the enhancement silently
        // stops running. Let it spread to a `formaction` button and the busy
        // state fires on a promo apply or a plan switch -- sub-actions that are
        // meant to stay live so the buyer can keep editing ([27.10], [8.7]).
        $this->seedCart();

        $body = (string) $this->app(bumps: self::bumpConfig())->handle($this->get('/checkout/'))->getBody();

        self::assertSame(1, substr_count($body, 'data-checkout-submit'), 'exactly one button is the placement submit');
        self::assertMatchesRegularExpression(
            '/<button type="submit" id="checkout-pay"[^>]*\sdata-checkout-submit\b/',
            $body,
            'and it is Pay Now',
        );

        // Every sub-action button, hook-free. Each is matched from its own
        // `formaction` to the end of its tag, so a hook added anywhere inside
        // one of them fails here.
        preg_match_all('/<button[^>]*\sformaction="[^"]*"[^>]*>/', $body, $subActions);

        self::assertGreaterThanOrEqual(4, count($subActions[0]), 'promo apply, promo remove, plan switch and bump are all here');

        foreach ($subActions[0] as $subAction) {
            self::assertStringNotContainsString('data-checkout-submit', $subAction, "a sub-action must not be guarded: {$subAction}");
        }

        // The hidden promo apply stays first in tree order, so implicit
        // submission still cannot place an order -- adding the hook must not
        // have reordered anything.
        self::assertLessThan(
            strpos($body, 'data-checkout-submit'),
            strpos($body, '<button type="submit" formaction="/checkout/promo/" class="sr-only"'),
            'the default button is still the promo apply, not the guarded Pay Now',
        );
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * The whole application with a throwaway database, the recorded provider
     * response queued, and every checkout collaborator bound by hand.
     *
     * @param array<string, mixed> $bumps `config/cross-sells.php`'s own shape
     * @param bool $recordEvents bind the real reporter with its EMR half off, so a case can read
     *                           `[18.1]`'s local trail; the default stays the null one, because most
     *                           cases here are about the page and an event write would be noise
     */
    private function app(
        string $fixture = 'vrio-order-approved.json',
        int $rateLimit = 8,
        bool $promotions = true,
        array $bumps = ['max_on_page' => 3, 'bumps' => []],
        bool $recordEvents = false,
    ): App {
        $this->transport->queueFixture($fixture);
        $catalog = self::catalog();
        $transport = $this->transport;
        $log = $this->log->log;

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo = $this->tempPdo(),
            OperatorLog::class => $log,
            // The three overrides a form-rendering test needs: no gateway may
            // reach the EMR, and the definition cache must not outlive the run.
            TeleformGateway::class => new NullTeleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
            SessionGateway::class => new FakeSessionGateway(mintUuid: '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30'),
            ProductCatalog::class => $catalog,
            VerificationGateway::class => new NullVerificationGateway(),
            OrderRecorder::class => new NullOrderRecorder(),
            CheckoutEventReporter::class => static fn (Container $c): CheckoutEventReporter => $recordEvents
                ? new EmrCheckoutEventReporter(
                    $c->get(EmrClientFactory::class),
                    $c->get(EventRepository::class),
                    $c->get(OrderRepository::class),
                    $log,
                    static fn (): ?string => null,
                    'USD',
                    // The EMR half off: the local trail is the storefront's own
                    // record and is written either way, and no test may reach a
                    // network.
                    false,
                )
                : new NullCheckoutEventReporter(),
            PaymentAdapter::class => static function () use ($transport, $log, $promotions): PaymentAdapter {
                $adapter = new VrioAdapter(
                    new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
                    new VrioApiFactory($transport),
                    $log,
                    shippingProfileId: 1,
                );

                return $promotions
                    ? $adapter
                    : new RedeclaredAdapter($adapter, AdapterCapabilities::STRATEGY_RAW_CARRY_FORWARD, false);
            },
            Consents::class => static fn (Container $c): Consents => Consents::fromConfig(
                (array) $c->get(Config::class)->get('consent', []),
            ),
            OrderBumps::class => static fn (Container $c): OrderBumps => OrderBumps::fromConfig(
                $bumps,
                $c->get(ProductCatalog::class),
                $c->get(OperatorLog::class),
            ),
            CheckoutAttemptRepository::class => static fn (Container $c): CheckoutAttemptRepository => new CheckoutAttemptRepository(
                static fn (): \PDO => $c->get(\PDO::class),
                'sqlite',
            ),
            RateLimitRepository::class => static fn (Container $c): RateLimitRepository => new RateLimitRepository(
                static fn (): \PDO => $c->get(\PDO::class),
                'sqlite',
            ),
            DatabaseRateLimiter::class => static fn (Container $c): DatabaseRateLimiter => new DatabaseRateLimiter(
                $c->get(RateLimitRepository::class),
                [
                    'checkout.submit' => ['limit' => $rateLimit, 'window_seconds' => 300],
                    'checkout.promo' => ['limit' => 20, 'window_seconds' => 300],
                ],
                $c->get(OperatorLog::class),
            ),
            CheckoutService::class => static fn (Container $c): CheckoutService => new CheckoutService(
                carts: $c->get(CartStore::class),
                journeys: $c->get(JourneyStore::class),
                rules: $c->get(CartRules::class),
                catalog: $c->get(ProductCatalog::class),
                adapter: $c->get(PaymentAdapter::class),
                consents: $c->get(Consents::class),
                bumps: $c->get(OrderBumps::class),
                verification: $c->get(VerificationGateway::class),
                attempts: $c->get(CheckoutAttemptRepository::class),
                limiter: $c->get(DatabaseRateLimiter::class),
                orders: $c->get(OrderRecorder::class),
                events: $c->get(CheckoutEventReporter::class),
                forms: $c->get(TeleformSource::class),
                flow: $c->get(FlowDefinition::class),
                config: $c->get(Config::class),
                log: $c->get(OperatorLog::class),
                postCharge: $c->get(PostChargeGuard::class),
                upsells: $c->get(Upsells::class),
            ),
            CheckoutController::class => static fn (Container $c): CheckoutController => new CheckoutController(
                $c->get(CheckoutService::class),
                $c->get(CartStore::class),
            ),
        ]);
    }

    /**
     * One Rx line with a plan chosen — otherwise the step guard refuses the
     * page — and one free attachment that this territory allows but New York
     * does not.
     */
    private function seedCart(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [
            [
                'slug' => 'tirzepatide', 'name' => 'Tirzepatide', 'kind' => 'rx',
                'emr_product_id' => null, 'parent_slug' => null,
                'quantity' => 1, 'unit_price_cents' => 12000, 'variant_id' => 't-3m',
            ],
            [
                'slug' => 'blocked-thing', 'name' => 'Blocked Thing', 'kind' => 'free-addon',
                'emr_product_id' => null, 'parent_slug' => 'tirzepatide',
                'quantity' => 1, 'unit_price_cents' => 0, 'variant_id' => null,
            ],
        ]];
    }

    /** Puts the buyer's details in journey state, the way a sub-action would have. */
    private function seedBuyer(App $app): void
    {
        $app->handle($this->get('/'));

        $store = new SessionRepository(fn (): \PDO => $this->pdo);
        $row = $store->find(self::SESSION) ?? ['journey_state' => []];
        $state = $row['journey_state'];
        $state['buyer'] = $this->carriedFields();
        $store->save(self::SESSION, $state, null, null);
    }

    /** @return array<string, string> */
    private function carriedFields(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '2125551234',
            'address_line' => '350 5th Avenue',
            'city' => 'San Francisco',
            'territory' => 'CA',
            'postal_code' => '94105',
        ];
    }

    /**
     * A complete, payable submission.
     *
     * @param array<string, string>|null $consents
     *
     * @return array<string, mixed>
     */
    private function submission(
        string $csrf,
        ?array $consents = ['terms' => 'on'],
        string $territory = 'CA',
        string $postalCode = '94105',
    ): array {
        return [
            '_csrf' => $csrf,
            'card_number' => self::CARD,
            'card_expiry' => '12 / 30',
            'card_cvc' => '123',
            'consents' => $consents ?? [],
        ] + ['territory' => $territory, 'postal_code' => $postalCode] + $this->carriedFields();
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

    private static function catalog(): ProductCatalog
    {
        return new FakeCatalog([
            'tirzepatide' => [
                'slug' => 'tirzepatide',
                'name' => 'Tirzepatide',
                'kind' => 'rx',
                'variants' => [
                    ['id' => 't-1m', 'name' => '1 Month', 'price_cents' => 14000, 'provider' => ['offer_id' => '337', 'product_id' => '3414']],
                    ['id' => 't-3m', 'name' => '3 Months', 'price_cents' => 12000, 'provider' => ['offer_id' => '337', 'product_id' => '3415']],
                ],
            ],
            'blocked-thing' => [
                'slug' => 'blocked-thing',
                'name' => 'Blocked Thing',
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
        ]);
    }

    /** Seeds the CSRF token with a GET, exactly as {@see CsrfTest} does. */
    private function csrfToken(App $app): string
    {
        $app->handle($this->get('/'));

        return (string) $_SESSION['_csrf'];
    }

    private function get(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withCookieParams(['amd_session' => self::SESSION]);
    }

    /** @param array<string, mixed> $body */
    private function post(App $app, string $path, array $body): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withParsedBody($body)
                ->withCookieParams(['amd_session' => self::SESSION]),
        );
    }
}

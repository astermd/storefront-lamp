<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Upsell\Upsells;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class FunnelPagesTest extends TestCase
{
    use ConfigVariant;
    use TempDatabase;

    /** The journey the two post-purchase pages are reached as. */
    private const string BUYER_SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function seedCart(string $kind = 'otc', ?string $variantId = 'v1'): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => 'seeded', 'name' => 'Seeded Product', 'kind' => $kind,
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => $variantId,
        ]]];
    }

    private function seedCartWith(string $slug, string $kind): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => $slug, 'name' => ucfirst($slug), 'kind' => $kind,
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => 'v1',
        ]]];
    }

    /**
     * A throwaway migrated SQLite file rather than whichever database the
     * developer running the suite has configured: none of these pages should
     * reach PDO, and binding a temp one keeps that a property of the code.
     */
    private function app(): \Slim\App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            Config::class => $this->indexableConfig(),
            // seedCartWith('semaglutide', 'rx') needs the sample seed's
            // teleform id, not whatever bin/console theme:sync --apply has
            // currently synced into config/products.generated.php.
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
        ]);
    }

    /**
     * The shipped deployment with `[24.7]`'s master switch **off**.
     *
     * Every case below asserts that its page refuses indexing, and under the
     * suite's own configuration that switch already refuses everything — the
     * home page renders the identical tag. Asserted against that
     * configuration, the seven assertions would hold just as well on a
     * marketing page, which is to say they would be asserting nothing about
     * these pages being funnel steps. With the switch off, the only thing left
     * refusing them is `[24.6]`, which is the rule they exist for.
     *
     * Off is also the shipped value: `config/app.php` sets it false, and it is
     * `APP_ENV=test` that turns it on. So this is the deployment as it really
     * runs rather than a contrivance.
     */
    private function indexableConfig(): Config
    {
        /** @var array<string, mixed> $app */
        $app = require dirname(__DIR__, 2) . '/config/app.php';
        /** @var array<string, mixed> $seo */
        $seo = $app['seo'];
        $app['seo'] = ['discourage_indexing' => false] + $seo;

        return $this->configWith(['app' => $app]);
    }

    /**
     * `[24.2]`: a refused page says so twice, in the tag and in the header,
     * from the one configured decision. Both are asserted together everywhere
     * below, because a page that carried only one of them would be refused by
     * a crawler that read the other and indexed by one that did not.
     */
    private static function assertRefusesIndexing(ResponseInterface $response): void
    {
        $body = (string) $response->getBody();

        self::assertSame(1, substr_count($body, 'name="robots"'), 'exactly one directive');
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow" />', $body);
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }

    /**
     * The same application, plus a journey that has bought something.
     *
     * The upsell and receipt steps require a placed order, and the cart is
     * cleared when one is made, so there is no cart to seed instead: the
     * durable journey is the only way in. The row is written before the first
     * request because the journey store keeps one state per container.
     */
    /**
     * @param array<string, mixed> $overrides container ids the case needs beyond a journey that has bought something
     */
    private function appWithPlacedOrder(array $overrides = [], array $upsellQueue = []): \Slim\App
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $pdo))->insert(
            self::BUYER_SESSION,
            ['placed_orders' => ['34660'], 'reconciled' => true, 'upsell_queue' => $upsellQueue],
            null,
        );

        return AppFactory::create(dirname(__DIR__, 2), $overrides + [
            \PDO::class => $pdo,
            Config::class => $this->indexableConfig(),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::BUYER_SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
    }

    /**
     * An upsell layer with one offer in it.
     *
     * The shipped `config/upsells.php` is deliberately empty — every product in
     * this channel is a prescription and an order carries at most one — so a
     * case that wants to see the offer page has to configure one. It names a
     * real catalog product, because an offer whose product cannot be resolved
     * is skipped rather than shown.
     */
    private function upsellLayer(): Upsells
    {
        // A product this test owns rather than the deployment's catalog: what
        // is being asserted is that the offer page renders, which is true of
        // any product, so reading the shipped catalog would make this fail the
        // day a channel stops selling whichever one it named.
        return Upsells::fromConfig(
            ['upsells' => [
                'travel-case' => [
                    'slug' => 'travel-case',
                    'offer_after' => ['anything'],
                    'eyebrow' => 'Wait! Your Exclusive Offer!',
                    'headline' => 'Enhance Your Wellness Journey',
                    'body' => 'Optimize your daily routine.',
                    'bullets' => ['Immune Support', 'Energy Boost'],
                    'price_cents_override' => 899,
                ],
            ]],
            new FakeCatalog(['travel-case' => [
                'slug' => 'travel-case',
                'name' => 'Travel Case',
                'kind' => 'otc',
                'price_cents' => 899,
                'variants' => [['id' => 'travel-case-1', 'price_cents' => 899, 'provider' => ['offer_id' => '412', 'product_id' => '3414']]],
            ]]),
            new OperatorLog(sys_get_temp_dir() . '/funnel-pages-upsell.log'),
        );
    }

    private function getAsBuyer(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withCookieParams(['amd_session' => self::BUYER_SESSION]);
    }

    public function testWelcomePageRendersWithNoindex(): void
    {
        $this->seedCart();
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/intake/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Start My Assessment', $body);
        self::assertRefusesIndexing($response);
    }

    public function testEligibilityStepForwardsWhenNoPreQualificationFormIsConfigured(): void
    {
        // The common real configuration: eligibility questions folded into the
        // intake form, so the dedicated step has nothing to ask. Forwarding
        // beats drawing an empty questionnaire.
        $this->seedCart();
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/intake/eligibility/'));

        self::assertSame(303, $response->getStatusCode());
        self::assertNotSame('/intake/eligibility/', $response->getHeaderLine('Location'));
    }

    public function testIntakeStepStatesTheOutageRatherThanDrawingAnEmptyForm(): void
    {
        // A product that really does declare a questionnaire, with no EMR
        // reachable to supply it: the visitor gets a stated outage and a 200,
        // never a blank form and never a crash [10.3].
        $this->seedCartWith('semaglutide', 'rx');
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/intake/medical/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('can&rsquo;t load your questionnaire', $body);
        self::assertRefusesIndexing($response);
    }

    public function testIntakeStepForwardsWhenNothingInTheCartAsksAQuestionnaire(): void
    {
        $this->seedCart();
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/intake/medical/'));

        self::assertSame(303, $response->getStatusCode(), '[8.3]: no form to collect means straight on to checkout');
    }

    /**
     * `/verify/` is a guarded funnel step (`[22.13]`), so it needs a cart to
     * be reachable at all. An over-the-counter line is the
     * cheapest journey that satisfies both intake preconditions honestly
     * rather than by relaxing them: a cart calling for no questionnaire has
     * nothing outstanding to collect (`[8.3]`).
     */
    public function testVerifyPageRendersWithNoindex(): void
    {
        $this->seedCart('otc');
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/verify/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Secure Identity Verification', $body);
        self::assertRefusesIndexing($response);
    }

    public function testCheckoutPageRendersWithNoindexAndUncheckedConsents(): void
    {
        $this->seedCart();
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/checkout/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Express checkout', $body);
        self::assertRefusesIndexing($response);

        // Locked business decision: the two CONSENT checkboxes ship unchecked, unlike
        // the mockup which pre-checks them. The mockup's exact markup for a checked
        // consent checkbox is `checked class="peer sr-only"` (attribute immediately
        // followed by the class, no other checkbox on the page uses this literal
        // sequence — Tailwind's `peer-checked:` variant classes contain "checked" too,
        // but never as a standalone `checked` HTML attribute). Asserting this substring
        // is absent proves neither consent checkbox renders with `checked`, without
        // being tripped up by the unrelated `peer-checked:` utility classes elsewhere
        // on the page.
        self::assertStringNotContainsString('checked class="peer sr-only"', $body);

        // Positive check that the consent section itself rendered, so the
        // negative assertion above is not vacuously true because the section is
        // missing. The copy is read from the configuration that renders it
        // rather than repeated here: it is a deployment's to edit (`[26.1]`),
        // and a test asserting its own copy would fail the first time counsel
        // reworded a sentence, which is not a regression.
        $configured = require dirname(__DIR__, 2) . '/config/consent.php';
        self::assertNotSame([], $configured['consents'], 'the shipped consent set must not be empty');

        foreach ($configured['consents'] as $consent) {
            self::assertStringContainsString('name="consents[' . $consent['key'] . ']"', $body);
            self::assertStringContainsString($consent['html'], $body);
        }
    }

    public function testEveryDocumentAConsentLinksToIsActuallyServed(): void
    {
        // `[26.9]`: the consent control renders, ticks and records agreement
        // whatever its link points at, so a 404 behind it is invisible at
        // runtime -- and a buyer has then agreed to something they could not
        // read. config:validate refuses the deployment, and this refuses the
        // routes going missing underneath it.
        $app = $this->app();
        $configured = require dirname(__DIR__, 2) . '/config/consent.php';

        $links = [];
        foreach ($configured['consents'] as $consent) {
            foreach ($consent['links'] as $link) {
                $links[] = $link;
            }
        }

        self::assertNotSame([], $links, 'the shipped consents link to at least one document');

        foreach ($links as $link) {
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $link));

            self::assertSame(200, $response->getStatusCode(), $link . ' is linked from a consent and must be served');
            self::assertStringContainsString('placeholder text', (string) $response->getBody());
        }
    }

    public function testUpsellPageRendersWithNoindex(): void
    {
        // The queue is journey state, so it has to be seeded as well as
        // configured: it is built once at checkout from what was bought, and
        // this journey's order was placed before the case began.
        $app = $this->appWithPlacedOrder(
            [Upsells::class => $this->upsellLayer()],
            upsellQueue: ['travel-case'],
        );
        $response = $app->handle($this->getAsBuyer('/upsell/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Your Exclusive Offer', $body);
        self::assertRefusesIndexing($response);
    }

    public function testThankYouPageRendersWithNoindexAndNoMapWithoutKey(): void
    {
        $app = $this->appWithPlacedOrder();
        $response = $app->handle($this->getAsBuyer('/thank-you/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertRefusesIndexing($response);
        // No GOOGLE_MAPS_API_KEY is configured in the test environment, so the
        // maps_api_key global is falsy and the {% if maps_api_key %} block must
        // render nothing at all.
        self::assertStringNotContainsString('google.com/maps', $body);
    }

    public function testNotEligiblePageRendersWithNoindex(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/not-eligible/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Requirements not met', $body);
        self::assertRefusesIndexing($response);
    }

    public function test404ResponseIsThemedWithBackToHomeButton(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/nope/'));
        $body = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Page not found', $body);
        // Same button markup vocabulary as the not-eligible/verify/checkout CTAs:
        // rounded-sm bg-primary button linking home via url('/').
        self::assertStringContainsString('href="/"', $body);
        self::assertStringContainsString('bg-primary', $body);
        self::assertStringContainsString('Back to Home', $body);
    }
}

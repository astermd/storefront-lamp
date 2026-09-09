<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\StubIdentityGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Verification\IdentityGateway;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The funnel walked through the real stack with `verification.enabled` true.
 *
 * Every existing HTTP assertion about `/verify/` is an assertion about the
 * step **switched off**, and that was not a choice anybody made: `AppFactory`
 * read `config/verification.php` through a lexically captured `$config`, so a
 * test could substitute the identity gateway but not the settings that decide
 * whether the step exists, where it sits, or whether it blocks. `blocking =>
 * true` therefore appeared in exactly one unit test that never touched HTTP,
 * the guard's refusal had never run through a request, and the held copy —
 * the sentences a buyer sees from behind a closed gate — had never been
 * rendered by anything.
 *
 * With {@see \AsterMD\Storefront\Tests\Bootstrap\ConfigSeamTest}'s seam in
 * place this file is what the seam was for. It walks a journey that has
 * finished its questionnaire through `/verify/` and on to `/checkout/`, once
 * for each of the four declared placements and once for each of the three
 * verdicts, and asserts the pair that has to agree: what the buyer is told,
 * and whether they actually get through.
 *
 * **Nothing here reaches a network.** The passing verdict is a stub, and it
 * must be: twenty calls recorded live on 2026-08-25 across nine identities and
 * all three checks came back `valid: false` without exception, so no input
 * produces a pass against the configured provider.
 */
final class VerificationEnabledFunnelTest extends TestCase
{
    use ConfigVariant;
    use TempDatabase;

    /** The journey that has finished its questionnaire and is standing on the step. */
    private const string SESSION = 'b17c4e90-2f5a-4d61-9c83-70ae1f2b6d45';

    private const string TELEFORM = 'tf-verification-funnel';

    private const string CSRF = 'verification-funnel-test-token';

    /** The sentence `[20.1]` allows for a refused identity and for nothing else. */
    private const string REFUSED = 'We could not confirm your identity from the details we hold';

    /** The promise every non-blocking notice ends on, and no held one may. */
    private const string CARRY_ON = 'you can carry on';

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $_SESSION = ['_csrf' => self::CSRF];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------- a gate that blocks

    public function testABlockingGateSendsAFinishedQuestionnaireToTheStepInsteadOfCheckout(): void
    {
        // The routing half of `[22.16]`. A step no routing decision names is
        // reachable only by typing its URL, and a guard that refuses
        // `/checkout/` while the router still answers `checkout` computes a
        // destination that is the step the visitor is already on.
        $app = $this->app(blocking: true);

        $response = $this->get($app, '/checkout/');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify/', $response->getHeaderLine('Location'));
    }

    public function testABlockingGateHoldsAJourneyWhoseIdentityWasRefused(): void
    {
        $app = $this->app(blocking: true, valid: false);

        $submit = $this->submit($app);

        // Re-rendered rather than redirected: a 303 to `/checkout/` would be
        // bounced straight back here by the gate on that step.
        self::assertSame(200, $submit->getStatusCode());
        self::assertSame(JourneyState::VERIFICATION_FAILED, $this->recordedVerification()['status'] ?? null);
        self::assertSame(302, $this->get($app, '/checkout/')->getStatusCode());
    }

    public function testAPageRenderedToAHeldBuyerNeverTellsThemTheyCanCarryOn(): void
    {
        // The same verdict means two different things under the two
        // placements, so one table of copy is wrong for one of them by
        // construction. Asserted as a property rather than against a sentence:
        // whatever this page decides to say to somebody the gate has just
        // stopped, it must not be that they may continue.
        //
        // `inconclusive` is the pair used because it is the one verdict whose
        // non-blocking wording says so outright, which makes the negative below
        // mean something rather than pass by accident.
        $recording = $this->app(blocking: false, valid: null);
        $this->submit($recording);
        $recorded = (string) $this->get($recording, '/verify/')->getBody();
        self::assertStringContainsString(self::CARRY_ON, $recorded);

        $held = (string) $this->submit($this->app(blocking: true, valid: null))->getBody();
        self::assertStringNotContainsString(
            self::CARRY_ON,
            $held,
            'a blocking gate has just stopped this buyer; the page must not tell them they may continue',
        );
    }

    public function testAPassingVerdictOpensABlockingGateForTheRestOfTheJourney(): void
    {
        $app = $this->app(blocking: true, valid: true);

        $submit = $this->submit($app);

        self::assertSame(303, $submit->getStatusCode());
        self::assertSame('/checkout/', $submit->getHeaderLine('Location'));
        self::assertSame(JourneyState::VERIFICATION_PASSED, $this->recordedVerification()['status'] ?? null);
        self::assertSame(200, $this->get($app, '/checkout/')->getStatusCode());
    }

    public function testTheStepItselfStaysReachableWhileTheGateIsHoldingSomebody(): void
    {
        // `/verify/` declares `verification_satisfied` in none of its own
        // requirements, and it must not: a step that gated on the verdict it
        // exists to collect would refuse everybody it is meant to serve.
        $app = $this->app(blocking: true, valid: false);
        $this->submit($app);

        self::assertSame(200, $this->get($app, '/verify/')->getStatusCode());
    }

    // --------------------------------------------- a gate that only records

    public function testANonBlockingGateLetsARefusedIdentityStraightThroughToCheckout(): void
    {
        // Recorded fact 3, exercised rather than asserted about: with the
        // provider refusing everybody, non-blocking is the only setting under
        // which a real buyer reaches checkout at all.
        $app = $this->app(blocking: false, valid: false);

        $submit = $this->submit($app);

        self::assertSame(303, $submit->getStatusCode());
        self::assertSame('/checkout/', $submit->getHeaderLine('Location'));
        self::assertSame(JourneyState::VERIFICATION_FAILED, $this->recordedVerification()['status'] ?? null);
        self::assertSame(200, $this->get($app, '/checkout/')->getStatusCode());
    }

    public function testANonBlockingStepIsOfferedByTheRouterAndEnforcedByNoGate(): void
    {
        // `[22.16]`: placement and blocking are separate settings, and this is
        // the case that shows they are not the same one wearing two names. The
        // router names the step, so every onward hop in the funnel offers it;
        // the gate on `/checkout/` does not stop anybody who never took it.
        // Both halves are the definition of non-blocking, and a test that
        // asserted only one of them would read as a defect in the other.
        $app = $this->app(blocking: false);

        // `/thank-you/` requires an order this journey has not placed, so the
        // guard turns the visitor away and computes where they belong from the
        // routing decision — which is the routing decision every onward hop in
        // the funnel uses. It names the step.
        $routed = $this->get($app, '/thank-you/');
        self::assertSame(302, $routed->getStatusCode());
        self::assertSame('/verify/', $routed->getHeaderLine('Location'));

        // And the gate on `/checkout/` lets through a buyer who never took it.
        self::assertSame(200, $this->get($app, '/checkout/')->getStatusCode());
    }

    // ------------------------------------------------------ the three verdicts

    /** @return iterable<string, array{?bool, string}> */
    public static function verdicts(): iterable
    {
        yield 'passed' => [true, JourneyState::VERIFICATION_PASSED];
        yield 'failed' => [false, JourneyState::VERIFICATION_FAILED];
        yield 'inconclusive' => [null, JourneyState::VERIFICATION_INCONCLUSIVE];
    }

    #[DataProvider('verdicts')]
    public function testEachVerdictIsRecordedAndDecidesTheGateUnderABlockingPlacement(?bool $valid, string $expected): void
    {
        $app = $this->app(blocking: true, valid: $valid);

        $submit = $this->submit($app);

        self::assertSame($expected, $this->recordedVerification()['status'] ?? null);
        self::assertSame(
            $expected === JourneyState::VERIFICATION_PASSED ? 303 : 200,
            $submit->getStatusCode(),
            'only a passing verdict may open a blocking gate',
        );
    }

    #[DataProvider('verdicts')]
    public function testEveryVerdictLetsTheBuyerThroughUnderANonBlockingPlacement(?bool $valid, string $expected): void
    {
        $app = $this->app(blocking: false, valid: $valid);

        $submit = $this->submit($app);

        self::assertSame($expected, $this->recordedVerification()['status'] ?? null);
        self::assertSame(303, $submit->getStatusCode());
        self::assertSame('/checkout/', $submit->getHeaderLine('Location'));
    }

    public function testAnInconclusiveVerdictIsNeverPresentedAsARefusalEvenBehindAClosedGate(): void
    {
        // `[20.1]`, at the point it is hardest to honour. The buyer has just
        // been stopped, so the page has to say so — and still must not say the
        // provider refused them, because it did not answer at all.
        $body = (string) $this->submit($this->app(blocking: true, valid: null))->getBody();

        self::assertStringNotContainsString(self::REFUSED, $body);
        self::assertStringNotContainsString('failed', $body);
        self::assertStringContainsString('Nothing is wrong with the details you gave us', $body);
    }

    // ---------------------------------------------------- the four placements

    /**
     * The four placements `[22.14]` declares, and whether each one gates the
     * pre-payment funnel.
     *
     * Only `intake` runs before money moves, so it is the only one that can.
     * Re-asking any of the other three at a pre-payment gate would stop a
     * buyer behind a step that comes *after* the one they are trying to
     * reach — a deadlock rather than a stricter reading of `[22.16]`.
     *
     * `async` is in this list as a fact about the code, not an endorsement:
     * `config/verification.php` calls selecting it "a configuration error
     * rather than a silent downgrade", and nothing at runtime is positioned to
     * refuse it. `config:validate` is where it is refused —
     * {@see \AsterMD\Storefront\Tests\Console\ValidateCommandTest}.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function placements(): iterable
    {
        yield 'intake' => ['intake', true];
        yield 'post_checkout' => ['post_checkout', false];
        yield 'receipt' => ['receipt', false];
        yield 'async' => ['async', false];
    }

    #[DataProvider('placements')]
    public function testOnlyTheIntakePlacementGatesThePrePaymentFunnel(string $placement, bool $gates): void
    {
        $app = $this->app(blocking: true, placement: $placement);

        $response = $this->get($app, '/checkout/');

        if ($gates) {
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/verify/', $response->getHeaderLine('Location'));

            return;
        }

        self::assertSame(200, $response->getStatusCode(), 'a placement that runs after checkout must not gate it');
    }

    #[DataProvider('placements')]
    public function testNoPlacementEverStrandsABuyerWithNoWayOnward(string $placement, bool $gates): void
    {
        // The deadlock check, run against all four rather than the one that
        // provokes it: whatever the placement, a buyer who submits the step
        // either advances or is told why they have not.
        $app = $this->app(blocking: true, placement: $placement, valid: true);

        $submit = $this->submit($app);

        self::assertContains($submit->getStatusCode(), [200, 303]);
        self::assertNotSame(
            '/verify/',
            $submit->getHeaderLine('Location'),
            'the step must never 303 onto itself',
        );
    }

    // ----------------------------------------------------------- fixtures

    /**
     * The application with verification switched on, and a journey standing on
     * the step.
     *
     * The payment adapter is nulled out throughout: what these cases assert is
     * whether a buyer may *reach* a page, and a provider has no part in that.
     * Binding a real one would put every assertion at the mercy of a network.
     */
    private function app(
        bool $blocking,
        string $placement = 'intake',
        ?bool $valid = null,
    ): App {
        $this->pdo ??= $this->tempPdo();
        $this->seedJourney();
        $this->seedCart();

        return AppFactory::create(dirname(__DIR__, 2), [
            Config::class => $this->verificationConfig([
                'enabled' => true,
                'placement' => $placement,
                'blocking' => $blocking,
            ]),
            \PDO::class => $this->pdo,
            ProductCatalog::class => self::catalog(),
            PaymentAdapter::class => new NullPaymentAdapter(),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
            TeleformGateway::class => self::teleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir(), 0),
            IdentityGateway::class => new StubIdentityGateway(enabled: true, valid: $valid),
        ]);
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
                ->withCookieParams(['amd_session' => self::SESSION]),
        );
    }

    private function submit(App $app): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/verify/')
                ->withCookieParams(['amd_session' => self::SESSION])
                ->withParsedBody(['_csrf' => self::CSRF]),
        );
    }

    /** @return array<string, mixed> */
    private function recordedVerification(): array
    {
        $row = $this->pdo->query('SELECT journey_state FROM sessions')->fetch(\PDO::FETCH_ASSOC);
        $state = json_decode((string) ($row['journey_state'] ?? '{}'), true);

        return is_array($state) && is_array($state['verification'] ?? null) ? $state['verification'] : [];
    }

    private function seedJourney(): void
    {
        $this->pdo->prepare('DELETE FROM sessions')->execute();

        (new \AsterMD\Storefront\Repository\SessionRepository(fn (): \PDO => $this->pdo))->insert(
            self::SESSION,
            [
                'form_status' => [self::TELEFORM => JourneyState::FORM_COMPLETED],
                'form_answers' => [self::TELEFORM => [
                    'first_name' => 'Marek',
                    'last_name' => 'Oyelaran',
                    'email' => 'marek.oyelaran@example.test',
                    'phone' => '5035550188',
                    'date_of_birth' => '1979-02-03',
                    'street' => '412 Halsey Street',
                    'city' => 'Portland',
                    'state' => 'OR',
                    'zip' => '97213',
                ]],
            ],
            null,
        );
    }

    private function seedCart(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => 'OR', 'lines' => [[
            'slug' => 'tirzepatide', 'name' => 'Tirzepatide', 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 24500, 'variant_id' => 't-1m',
        ]]];
    }

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/verification-funnel-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->registerTempDirForCleanup($dir);

        return $dir;
    }

    private static function catalog(): ProductCatalog
    {
        return new FakeCatalog(['tirzepatide' => [
            'slug' => 'tirzepatide',
            'name' => 'Tirzepatide',
            'kind' => 'rx',
            'teleform_id' => self::TELEFORM,
            'variants' => [[
                'id' => 't-1m', 'name' => '1 Month', 'price_cents' => 24500,
                'provider' => ['offer_id' => '337', 'product_id' => '3414'],
            ]],
        ]]);
    }

    /** The questionnaire, and the `db_fields` map the identity is assembled through (`[13.5b]`). */
    private static function teleformGateway(): TeleformGateway
    {
        return new class () implements TeleformGateway {
            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'tests/verification-funnel.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: [
                        'first_name' => 'opportunity.first_name',
                        'last_name' => 'opportunity.last_name',
                        'email' => 'opportunity.email',
                        'phone' => 'opportunity.phone',
                        'date_of_birth' => 'opportunity.dob',
                        'street' => 'opportunity.address.line1',
                        'city' => 'opportunity.address.city',
                        'state' => 'opportunity.address.state',
                        'zip' => 'opportunity.address.postal_code',
                    ],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return ['formId' => $metadata->id, 'pages' => []];
            }
        };
    }
}

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
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\StubIdentityGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Verification\IdentityGateway;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `/verify/` with the step actually switched on.
 *
 * {@see VerifyPageTest} covers the shipped configuration, in which
 * `verification.enabled` is false and the placement therefore answers "there
 * is no step" to every question. That is the deployment that ships and it is
 * worth pinning, but it means nothing in that file — or anywhere else in the
 * suite — ever ran the two branches a live deployment lives in: a placement
 * that holds somebody, and a routing decision that has a `verify` to name.
 *
 * The configuration is varied through {@see ConfigVariant} and the
 * `Config::class` seam rather than by substituting the router and the guard
 * directly, because those two exist to agree with each other and a test that
 * builds each of them by hand is a test that cannot notice them disagreeing.
 *
 * **What is under test is the agreement between what the buyer is told and
 * what happens to them.** Both halves are already right on their own: `[20.1]`
 * says an unrunnable check is not a statement about the buyer, and `[22.16]`
 * says whether a verdict stops anybody is the `blocking` setting's business.
 * Held together they produced a page that told a buyer the gate had just
 * refused that they could carry on.
 */
final class VerifyPlacementTest extends TestCase
{
    use ConfigVariant;
    use TempDatabase;

    private const string SESSION = '0b6d5f31-9c47-4a28-8ee0-7d1c4b0a9f52';

    private const string TELEFORM = 'tf-verify-placement';

    private const string CSRF = 'verify-placement-token';

    /** The throwaway database, kept so the assertions can read back what the request wrote. */
    private ?\PDO $pdo = null;

    private CapturedLog $log;

    protected function setUp(): void
    {
        $_SESSION = ['_csrf' => self::CSRF];
        $this->log = new CapturedLog();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------- what a held buyer is told

    public function testABlockedBuyerIsNeverToldTheyCanCarryOnByTheBranchThatIsHoldingThem(): void
    {
        // An EMR that answered without deciding, which recording #5 makes the
        // common shape rather than an edge: a 503, a malformed request of ours
        // and a provider that scored nothing all arrive here as inconclusive.
        $app = $this->app(enabled: true, blocking: true, verdict: null);

        $submit = $this->post($app, '/verify/');

        self::assertSame(200, $submit->getStatusCode(), 'the blocking gate re-renders rather than redirecting');
        self::assertSame(
            JourneyState::VERIFICATION_INCONCLUSIVE,
            $this->recordedVerification()['status'] ?? null,
            '`[22.20]`: the outcome is recorded whatever it is',
        );

        $body = (string) $submit->getBody();

        // The claim the page must not make, and the proof that it would be
        // false: the only way on is shut behind them.
        self::assertStringNotContainsString('you can carry on', $body);
        self::assertSame(['/checkout/', '/verify/'], $this->walk($app, 'GET', '/checkout/'));

        // And the two things it must say instead: that the journey has
        // stopped, and what to do about it.
        self::assertStringContainsString('we cannot continue until it has run', $body);
        self::assertStringContainsString('Try again', $body);
        self::assertStringContainsString('contact support', $body);
    }

    public function testTheSameOutageUnderANonBlockingPlacementStillSaysCarryOn(): void
    {
        // The overcorrection this pair exists to catch. Non-blocking means the
        // verdict is recorded and the journey continues, so "you can carry on"
        // is simply true and rewriting it into a warning would be the same
        // defect facing the other way.
        $app = $this->app(enabled: true, blocking: false, verdict: null);

        $submit = $this->post($app, '/verify/');
        self::assertSame(303, $submit->getStatusCode());
        self::assertSame('/checkout/', $submit->getHeaderLine('Location'));

        self::assertStringContainsString('you can carry on', $this->get($app, '/verify/'));
    }

    public function testAHeldBuyerWhoseIdentityWasRefusedIsNotSentToCheckDetailsThePageDoesNotHold(): void
    {
        // The mirror of the same defect. `[20.1]` permits this sentence — the
        // identity really was refused — but "check them over and try again" on
        // a page whose own copy says "there is nothing more to enter here"
        // names an action that does not exist.
        $app = $this->app(enabled: true, blocking: true, verdict: false);

        $body = (string) $this->post($app, '/verify/')->getBody();

        self::assertSame(JourneyState::VERIFICATION_FAILED, $this->recordedVerification()['status'] ?? null);
        self::assertStringContainsString('could not confirm your identity', $body, '`[20.1]`: a refusal may be said plainly');
        self::assertStringNotContainsString('Check them over and try again', $body);
        self::assertStringContainsString('we cannot continue', $body);
        self::assertStringContainsString('go back and correct it', $body, 'the action the page actually offers');
    }

    public function testABuyerBouncedOffCheckoutIsToldTheSameThingOnTheWayBack(): void
    {
        // The submit is not the only way onto this page while a hold is in
        // force. The guard on `/checkout/` sends them here too, and that is a
        // GET — so the notice and the action have to survive the round trip,
        // or a buyer who tries the next step is answered with a page that has
        // forgotten why they are on it.
        $app = $this->app(enabled: true, blocking: true, verdict: null);
        $this->post($app, '/verify/');

        self::assertSame(['/checkout/', '/verify/'], $this->walk($app, 'GET', '/checkout/'));

        $body = $this->get($app, '/verify/');
        self::assertStringContainsString('we cannot continue until it has run', $body);
        self::assertStringContainsString('>Try again<', $body, 'the button says what the notice points at');
        self::assertStringNotContainsString('you can carry on', $body);
    }

    public function testOpeningTheStepUnderABlockingPlacementIsNotAnOutageNotice(): void
    {
        // The gate refuses a journey that has not been asked yet, exactly as
        // it refuses one whose check could not run — so the hold alone cannot
        // be what puts a notice on screen, or a buyer's first sight of the
        // page would be an apology for something that has not happened.
        $app = $this->app(enabled: true, blocking: true, verdict: null);

        $body = $this->get($app, '/verify/');

        self::assertStringNotContainsString('could not be completed', $body);
        self::assertStringNotContainsString('cannot continue', $body);
        self::assertStringContainsString('>Submit<', $body);
    }

    // --------------------------------------- a step that forwards to itself

    public function testASubmitIsNeverRedirectedBackToTheStepItWasPostedFrom(): void
    {
        // A journey the request cannot load. The controller fails forward on
        // `[20.1]`'s rule, and the routing decision it forwards through reads
        // a missing journey as one that has never been asked — so it named
        // this very step, and the fail-forward was a 303 from `/verify/` to
        // `/verify/`. Neither end is a guard, so `funnel.guard_self_redirect`
        // never fired and nothing was logged.
        $app = $this->app(enabled: true, blocking: false, verdict: null, journeyReadable: false, formless: true);

        $walk = $this->walk($app, 'POST', '/verify/');

        self::assertNotContains('/verify/', array_slice($walk, 1), sprintf(
            'POST /verify/ walked back onto itself: %s',
            implode(' -> ', $walk),
        ));
        self::assertSame('/checkout/', end($walk), 'the fail-forward `[20.1]` rules on actually lands');
        self::assertNotSame([], $this->log->eventsNamed('verify.onward_self_redirect'), 'and the loop it replaced is stated');
    }

    public function testABlockingPlacementDuringAJourneyOutageIsNotAClosedBox(): void
    {
        // Non-blocking leaves `/checkout/` reachable, so the loop above cost
        // the visitor the forward path and not the order. Blocking closed the
        // box: the gate refused a null journey, so `/checkout/` bounced to
        // `/verify/` and `/verify/` forwarded to itself, with no exit and no
        // log line anywhere.
        $app = $this->app(enabled: true, blocking: true, verdict: null, journeyReadable: false, formless: true);

        $submit = $this->post($app, '/verify/');

        self::assertSame(200, $submit->getStatusCode(), 'the buyer is held, and told so, rather than forwarded in a circle');

        $body = (string) $submit->getBody();
        self::assertStringContainsString('could not be completed just now', $body);
        self::assertStringNotContainsString('you can carry on', $body);
        // `[20.1]`: a journey this storefront could not read says nothing
        // whatever about the person reading the page.
        self::assertStringNotContainsString('could not confirm your identity', $body);
    }

    // --------------------------------------------- how far the journey got

    public function testCompletingTheIdentityStepIsRecordedAsAStepTheJourneyCompleted(): void
    {
        // `verify` is a rung in `FurthestStep::ORDER` that no writer wrote, so
        // a buyer who verified and abandoned at checkout was reported at
        // `intake` — indistinguishable in the `[21.11]` signal from one who
        // never saw the step.
        $app = $this->app(enabled: true, blocking: false, verdict: true);

        $this->post($app, '/verify/');

        self::assertSame(JourneyState::VERIFICATION_PASSED, $this->recordedVerification()['status'] ?? null);
        self::assertSame('verify', $this->storedFurthestStep());
    }

    public function testABuyerTheGateIsStillHoldingHasNotCompletedTheStep(): void
    {
        // `[21.7]` is the furthest *completed* step, which is why a checkout
        // visit was ruled not to advance it. A buyer the identity gate is
        // holding is on this step, not past it.
        $app = $this->app(enabled: true, blocking: true, verdict: false);

        $this->post($app, '/verify/');

        self::assertSame(JourneyState::VERIFICATION_FAILED, $this->recordedVerification()['status'] ?? null);
        self::assertNotSame('verify', $this->storedFurthestStep());
    }

    // ------------------------------------------------------------ fixtures

    /** @return list<string> the chain of paths, starting with the one requested */
    private function walk(App $app, string $method, string $path, int $limit = 8): array
    {
        $seen = [$path];

        for ($hop = 0; $hop < $limit; ++$hop) {
            $response = $this->request($app, $method, $path);
            $method = 'GET';

            if ($response->getStatusCode() < 300 || $response->getStatusCode() >= 400) {
                return $seen;
            }

            $path = parse_url($response->getHeaderLine('Location'), PHP_URL_PATH) ?: '/';
            $seen[] = $path;

            if (count(array_unique($seen)) < count($seen)) {
                return $seen;
            }
        }

        return $seen;
    }

    private function post(App $app, string $path): ResponseInterface
    {
        return $this->request($app, 'POST', $path);
    }

    private function get(App $app, string $path): string
    {
        $response = $this->request($app, 'GET', $path);

        self::assertSame(200, $response->getStatusCode(), $path . ' did not render');

        return (string) $response->getBody();
    }

    private function request(App $app, string $method, string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams(['amd_session' => self::SESSION]);

        if ($method === 'POST') {
            $request = $request->withParsedBody(['_csrf' => self::CSRF]);
        }

        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function recordedVerification(): array
    {
        $state = $this->storedJourney();

        return is_array($state['verification'] ?? null) ? $state['verification'] : [];
    }

    private function storedFurthestStep(): ?string
    {
        $step = $this->storedJourney()['furthest_step'] ?? null;

        return is_string($step) ? $step : null;
    }

    /** @return array<string, mixed> */
    private function storedJourney(): array
    {
        $row = $this->pdo?->query('SELECT journey_state FROM sessions')->fetch(\PDO::FETCH_ASSOC);
        $state = json_decode((string) ($row['journey_state'] ?? '{}'), true);

        return is_array($state) ? $state : [];
    }

    /**
     * @param ?bool $verdict what the provider says: true, false, or null for an answer it could not reach
     * @param bool  $formless a cart whose product asks no questionnaire, so `/verify/` stays reachable
     *                        with no journey — the intake gate fails closed on one, by design
     */
    private function app(
        bool $enabled,
        bool $blocking,
        ?bool $verdict = null,
        bool $journeyReadable = true,
        bool $formless = false,
    ): App {
        $this->pdo ??= $this->tempPdo();
        $this->seedJourney();
        $this->seedCart($formless);

        return AppFactory::create(dirname(__DIR__, 2), [
            Config::class => $this->verificationConfig([
                'enabled' => $enabled,
                'placement' => 'intake',
                'blocking' => $blocking,
            ]),
            \PDO::class => $journeyReadable ? $this->pdo : self::unreadableSessions($this->pdo),
            ProductCatalog::class => self::catalog($formless),
            PaymentAdapter::class => new NullPaymentAdapter(),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
            TeleformGateway::class => self::teleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir(), 0),
            IdentityGateway::class => new StubIdentityGateway(enabled: true, valid: $verdict),
            OperatorLog::class => $this->log->log,
        ]);
    }

    /**
     * A live connection to the same file whose `sessions` reads raise.
     *
     * A database blip and nothing else: everything the request does that does
     * not touch that table still works, which is what makes the journey
     * unloadable rather than the page broken.
     */
    private static function unreadableSessions(\PDO $healthy): \PDO
    {
        $row = $healthy->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC);

        return new class ('sqlite:' . $row['file'], null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]) extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'sessions')) {
                    throw new \PDOException('SQLSTATE[HY000]: general error: database is locked');
                }

                return parent::prepare($query, $options);
            }
        };
    }

    private function seedJourney(): void
    {
        $this->pdo->prepare('DELETE FROM sessions')->execute();

        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [
            'form_status' => [self::TELEFORM => JourneyState::FORM_COMPLETED],
            'form_answers' => [self::TELEFORM => [
                'first_name' => 'Dana',
                'last_name' => 'Reyes',
                'email' => 'dana.reyes@example.test',
                'phone' => '4155550142',
            ]],
        ], null);
    }

    private function seedCart(bool $formless): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => 'OR', 'lines' => [[
            'slug' => $formless ? 'lidocaine-patch' : 'tirzepatide',
            'name' => $formless ? 'Lidocaine Patch' : 'Tirzepatide',
            'kind' => $formless ? 'otc' : 'rx',
            'emr_product_id' => null,
            'parent_slug' => null,
            'quantity' => 1,
            'unit_price_cents' => 24500,
            'variant_id' => $formless ? null : 't-1m',
        ]]];
    }

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/verify-placement-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->registerTempDirForCleanup($dir);

        return $dir;
    }

    private static function catalog(bool $formless): ProductCatalog
    {
        if ($formless) {
            return new FakeCatalog(['lidocaine-patch' => [
                'slug' => 'lidocaine-patch',
                'name' => 'Lidocaine Patch',
                'kind' => 'otc',
                'price_cents' => 2400,
            ]]);
        }

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

    private static function teleformGateway(): TeleformGateway
    {
        return new class () implements TeleformGateway {
            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'tests/verify-placement.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: [
                        'first_name' => 'opportunity.first_name',
                        'last_name' => 'opportunity.last_name',
                        'email' => 'opportunity.email',
                        'phone' => 'opportunity.phone',
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

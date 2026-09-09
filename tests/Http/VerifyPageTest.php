<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Http\Controller\VerifyController;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Verification\IdentityGateway;
use AsterMD\Storefront\Verification\IdentityVerdict;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `/verify/` as a step rather than a picture of one (`[22.13]`-`[22.21]`).
 *
 * The page was a mockup transcription: an unnamed file input in no form, an
 * unnamed SSN box in no form, and a "submit" button whose script assigned
 * `window.location`. It collected nothing and recorded nothing, so every
 * assertion here is about behaviour the page did not previously have.
 *
 * **The negative assertions are the point.** Three of them carry this step's
 * two hardest rules: `[20.1]` says only a `failed` verdict may ever be
 * presented to a buyer as a failed check, and `[22.21]` says a date of birth
 * and a Social Security Number must not reach the operator log, the events
 * table or the rendered page. Both are rules about what is *absent*, and
 * neither can be proved by looking at a happy path.
 *
 * **Nothing here reaches a network.** The pass branch cannot be exercised
 * live at all: recorded on 2026-08-25, twenty identity calls across nine
 * identities and all three checks returned `valid: false` without exception,
 * so a passing verdict exists only against a stub and a test that expected one
 * from the provider would be asserting a fiction.
 */
final class VerifyPageTest extends TestCase
{
    use TempDatabase;

    /** The journey that has finished its questionnaire and is standing on the step. */
    private const string SESSION = '9d41c7b2-63ae-4f05-8b1c-2ea5d7c34f19';

    private const string TELEFORM = 'tf-verify-page';

    private const string CSRF = 'verify-page-test-token';

    /**
     * The two data the `[22.21]` assertions hunt for.
     *
     * A date of birth that came out of the questionnaire and a Social Security
     * fragment the buyer typed on this page: one the storefront already held
     * and one it has just been handed, which are the two different ways
     * identity data gets into a request.
     */
    private const string DOB = '1984-07-19';

    private const string SSN_LAST_FOUR = '6821';

    /** The throwaway database, kept so the assertions can read back what the request wrote. */
    private ?\PDO $pdo = null;

    /**
     * Every string the mockup shipped that must not survive the transcription.
     *
     * The receipt's list is fabricated buyer data. This page had none — what it
     * shipped instead was **promises the storefront cannot keep and hooks that
     * go nowhere**, which is the same hazard wearing different clothes: an
     * upload control with no upload behind it looks finished until a buyer
     * chooses a file and nothing happens to it.
     *
     * @var list<string>
     */
    private const array MOCKUP_SAMPLES = [
        // The mockup's own navigation target, and the attribute the old script
        // read it out of instead of submitting anything.
        'checkout.html',
        'data-next-url',
        'data-file-input',
        // Two promises about a document the storefront never receives. `[22.21]`
        // puts identity documents under a retention policy that is not built,
        // so the honest page does not ask for one.
        'Please upload a valid government-issued ID',
        'Drag and drop your ID',
        'Your ID never shared to anyone else!',
    ];

    protected function setUp(): void
    {
        $_SESSION = ['_csrf' => self::CSRF];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------------------ the page

    public function testThePageIsAFormThatPostsAndNeedsNoJavaScriptToDoIt(): void
    {
        $body = $this->render($this->app());

        self::assertStringContainsString('<form method="post" action="/verify/"', $body);
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $body);
        self::assertStringContainsString('type="submit"', $body);
        // The old page's only "submit" was a click handler assigning
        // window.location. A page whose correctness depends on that is a page
        // that stops working with scripting off, and checkout already set the
        // precedent of one form with real sub-actions.
        self::assertStringNotContainsString('window.location', $body);
    }

    public function testNoMockupSampleDataSurvivesAnywhereInTheRenderedPage(): void
    {
        $body = $this->render($this->app());

        foreach (self::MOCKUP_SAMPLES as $sample) {
            self::assertStringNotContainsString(
                $sample,
                $body,
                sprintf('The mockup sample "%s" is still being rendered on the verify page.', $sample),
            );
        }
    }

    public function testTheDocumentControlsCannotAcceptADocumentAtAll(): void
    {
        $body = $this->render($this->app());

        // Kept as markup because the design is a deliverable, and disabled
        // because nothing here ingests a document. A file input with
        // no name, inside a form with no multipart encoding, cannot carry one
        // even if the disabled attribute were stripped by hand.
        self::assertStringContainsString('type="file"', $body);
        self::assertStringContainsString('disabled', $body);
        self::assertStringNotContainsString('enctype', $body);
        self::assertStringNotContainsString('name="id_document"', $body);
    }

    public function testTheShippedConfigurationNeverAsksForASocialSecurityNumber(): void
    {
        // `verification.enabled` ships false, so no configured check can run
        // and nothing would be done with the answer. Asking for the most
        // sensitive datum in the funnel anyway is exactly what `[22.21]`
        // forbids.
        $body = $this->render($this->app(gateway: null));

        self::assertStringNotContainsString('name="ssn"', $body);
        self::assertStringNotContainsString('SSN', $body);
    }

    public function testAnEnabledSsnCheckIsWhatPutsTheFieldOnThePage(): void
    {
        $body = $this->render($this->app(gateway: $this->gateway()));

        self::assertStringContainsString('name="ssn"', $body);
        self::assertStringContainsString('maxlength="4"', $body);
    }

    // -------------------------------------------------------- the outcomes

    public function testADisabledStepStillRecordsAnOutcomeAndStillLetsTheBuyerThrough(): void
    {
        // `[22.20]`: the outcome is recorded regardless of placement, and a
        // step that is switched off is the case where forgetting to record it
        // costs nothing today and leaves a compliance hole tomorrow.
        $app = $this->app(gateway: null);

        $response = $this->submit($app);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));
        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $this->recordedVerification()['status'] ?? null);
    }

    public function testWithVerificationDisabledTheBuyerCanStillReachCheckout(): void
    {
        // The whole reason `verification.enabled` and `blocking` both ship
        // false: recorded live, every identity call in this sandbox comes back
        // `valid: false`, so a step that stopped anybody would stop everybody.
        // What is under test here is the guard on the *next* step, so the
        // payment adapter is nulled out — a provider has no part in whether a
        // buyer may reach the page, and binding one would put the assertion at
        // the mercy of a network.
        $app = $this->app(gateway: null, overrides: [PaymentAdapter::class => new NullPaymentAdapter()]);
        $this->submit($app);

        $checkout = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/checkout/')
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        self::assertSame(200, $checkout->getStatusCode(), 'a disabled step must never strand a buyer');
    }

    public function testAnInconclusiveVerdictIsNeverPresentedToTheBuyerAsAFailedCheck(): void
    {
        // The recorded live behaviour of an inactive integration, and of our
        // own malformed request, is inconclusive — neither is a statement
        // about the buyer, and `[20.1]` says only `failed` may be shown as one.
        $app = $this->app(gateway: $this->gateway(status: null));
        $this->submit($app);

        $body = $this->render($app);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $this->recordedVerification()['status'] ?? null);
        self::assertStringNotContainsString('could not confirm your identity', $body);
        self::assertStringNotContainsString('failed', $body);
        self::assertStringContainsString('could not be completed', $body);
    }

    public function testOnlyARefusedIdentityIsPresentedAsAFailedCheck(): void
    {
        $app = $this->app(gateway: $this->gateway(status: false));
        $this->submit($app);

        $body = $this->render($app);

        self::assertSame(JourneyState::VERIFICATION_FAILED, $this->recordedVerification()['status'] ?? null);
        self::assertStringContainsString('could not confirm your identity', $body);
    }

    public function testAPassingVerdictIsRecordedWithTheCheckThatReachedIt(): void
    {
        $app = $this->app(gateway: $this->gateway(status: true));
        $this->submit($app);

        $verification = $this->recordedVerification();

        self::assertSame(JourneyState::VERIFICATION_PASSED, $verification['status'] ?? null);
        self::assertSame('crosscheck', $verification['check'] ?? null);
        self::assertSame('score', $verification['basis'] ?? null);
        self::assertIsString($verification['at'] ?? null);
    }

    // ------------------------------------------------------ what gets sent

    public function testTheIdentitySentIsBuiltFromTheIntakeMappingRatherThanASecondOne(): void
    {
        // Inconclusive throughout, so every configured check runs and the
        // whole assembled identity becomes observable across the three calls.
        $gateway = $this->gateway(status: null);
        $this->submit($this->app(gateway: $gateway));

        $sent = array_merge(...$gateway->identities);

        // `[13.5b]`: the questionnaire's own db_fields map, read backwards, is
        // the one mapping. Renaming a question must not break this.
        self::assertSame('Dana', $sent['firstName'] ?? null);
        self::assertSame('Reyes', $sent['lastName'] ?? null);
        self::assertSame('dana.reyes@example.test', $sent['email'] ?? null);
        self::assertSame('4155550142', $sent['phone'] ?? null);
        self::assertSame(self::DOB, $sent['dob'] ?? null);
        self::assertSame('900 Larkin Avenue', $sent['address']['streetAddress'] ?? null);
        self::assertSame('Portland', $sent['address']['city'] ?? null);
        self::assertSame('OR', $sent['address']['state'] ?? null);
        self::assertSame('97205', $sent['address']['postalCode'] ?? null);
        self::assertSame(self::SSN_LAST_FOUR, $sent['ssn'] ?? null);
        // `[13.36]` is a declared gap — no country is collected anywhere, so
        // none is invented for a compliance check.
        self::assertArrayNotHasKey('country', $sent['address'] ?? []);
    }

    public function testNothingBeyondWhatACheckNamesIsEverSent(): void
    {
        // `[22.21]`: an SSN offered to a check that ignores it is an SSN given
        // to a provider for no reason. `crosscheck` names neither it nor a date
        // of birth, and the journey holds both.
        $gateway = $this->gateway(status: null);
        $this->submit($this->app(gateway: $gateway));

        self::assertSame(['crosscheck', 'dob_verify', 'ssn_verify'], $gateway->checks);
        self::assertArrayNotHasKey('ssn', $gateway->identities[0]);
        self::assertArrayNotHasKey('dob', $gateway->identities[0]);
        self::assertArrayNotHasKey('ssn', $gateway->identities[1]);
    }

    public function testADecisiveVerdictStopsTheSequenceRatherThanEscalating(): void
    {
        $gateway = $this->gateway(status: false);
        $this->submit($this->app(gateway: $gateway));

        self::assertSame(['crosscheck'], $gateway->checks);
    }

    public function testAFullSocialSecurityNumberIsCutToItsLastFourBeforeItLeavesTheStorefront(): void
    {
        // `maxlength="4"` is a hint to one browser, and the field's bound has
        // to survive a scripted post, a curl call and an attribute deleted in
        // a developer console. The provider retains what it is sent
        // (`raw.request.store: true` in this branch's own recording), so nine
        // digits arriving here is nine digits kept somewhere `[22.21]` and §30
        // do not allow them to be.
        $gateway = $this->gateway(status: null);
        $this->submit($this->app(gateway: $gateway), ssn: '078-05-1120');

        $sent = array_merge(...$gateway->identities);

        // Recorded 2026-08-25: a four-digit `ssn` is accepted and answered
        // with a completed check of its own, so cutting the number down does
        // not cost the deployment the check.
        self::assertSame('1120', $sent['ssn'] ?? null);

        foreach ($gateway->identities as $identity) {
            self::assertStringNotContainsString(
                '078051120',
                (string) json_encode($identity, JSON_THROW_ON_ERROR),
                'a whole Social Security Number was handed to the provider',
            );
        }
    }

    public function testASsnIsNeverCollectedOrSentWhileTheGatewayIsDisabled(): void
    {
        $gateway = new FakeIdentityGateway(enabled: false);
        $this->submit($this->app(gateway: $gateway));

        // The disabled gateway is still walked — `[22.20]` records an outcome
        // either way — so this proves the field was dropped on the way in
        // rather than never reaching a provider by accident.
        self::assertNotSame([], $gateway->identities);

        foreach ($gateway->identities as $identity) {
            self::assertArrayNotHasKey('ssn', $identity);
        }
    }

    // ------------------------------------------------------------ `[22.21]`

    public function testNoSocialSecurityNumberOrDateOfBirthReachesTheLogTheDatabaseOrThePage(): void
    {
        $log = new CapturedLog();
        $app = $this->app(gateway: $this->gateway(status: false), log: $log);

        $this->submit($app);
        $body = $this->render($app);

        self::assertNotSame([], $log->lines(), 'the log really was written to, so the absence below means something');

        foreach ([self::SSN_LAST_FOUR, self::DOB, 'dana.reyes@example.test'] as $secret) {
            self::assertStringNotContainsString($secret, $log->contents(), 'identity data reached the operator log');
        }

        foreach (['sessions', 'events'] as $table) {
            $rows = (string) json_encode(
                $this->pdo->query('SELECT * FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC),
                JSON_THROW_ON_ERROR,
            );

            self::assertStringNotContainsString(self::SSN_LAST_FOUR, $rows, sprintf('an SSN reached the %s table', $table));
        }

        // A date of birth is deliberately *not* hunted for across the whole
        // `sessions` row, and the distinction matters. The questionnaire's
        // answers live in journey state — that is where a half-finished form
        // is kept — so a blanket assertion here would fail for a reason this
        // step neither caused nor may fix. What `[22.21]` asks of this step is
        // that it puts identity data nowhere *new*, and the record it writes
        // is the one place it could have.
        $verification = (string) json_encode($this->recordedVerification(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::DOB, $verification);
        self::assertStringNotContainsString(self::SSN_LAST_FOUR, $verification);
        self::assertStringNotContainsString('Dana', $verification);
        self::assertSame(
            ['status', 'at', 'basis', 'check'],
            array_keys($this->recordedVerification()),
            'the compliance record carries an outcome and nothing about the person',
        );

        self::assertStringNotContainsString(self::SSN_LAST_FOUR, $body, 'an SSN was echoed back onto the page');
        self::assertStringNotContainsString(self::DOB, $body, 'a date of birth was echoed back onto the page');
    }

    // ----------------------------------------------------------- fixtures

    /**
     * The real application, with the journey standing on the step and a cart to
     * make the step reachable at all.
     *
     * Everything that would otherwise reach a network is substituted: the
     * session and teleform gateways, the identity gateway — disabled unless a
     * case supplies its own — and the operator log whenever a case wants to
     * read the lines back.
     */
    /** @param array<string, mixed> $overrides container ids the case needs beyond the fixtures below */
    private function app(?FakeIdentityGateway $gateway = null, ?CapturedLog $log = null, array $overrides = []): App
    {
        $this->pdo ??= $this->tempPdo();
        $this->seedJourney();
        $this->seedCart();

        $app = AppFactory::create(dirname(__DIR__, 2), $overrides + array_filter([
            \PDO::class => $this->pdo,
            ProductCatalog::class => self::catalog(),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
            TeleformGateway::class => self::teleformGateway(),
            DefinitionCache::class => new DefinitionCache($this->cacheDir(), 0),
            IdentityGateway::class => $gateway ?? new FakeIdentityGateway(enabled: false),
            OperatorLog::class => $log?->log,
        ], static fn (mixed $value): bool => $value !== null));

        return $app;
    }

    private function render(App $app): string
    {
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/verify/')
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function submit(App $app, string $ssn = self::SSN_LAST_FOUR): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/verify/')
                ->withCookieParams(['amd_session' => self::SESSION])
                ->withParsedBody(['_csrf' => self::CSRF, 'ssn' => $ssn]),
        );
    }

    /** @return array<string, mixed> */
    private function recordedVerification(): array
    {
        $row = $this->pdo->query('SELECT journey_state FROM sessions')->fetch(\PDO::FETCH_ASSOC);
        $state = json_decode((string) ($row['journey_state'] ?? '{}'), true);

        return is_array($state) && is_array($state['verification'] ?? null) ? $state['verification'] : [];
    }

    /**
     * A journey that has finished the questionnaire, with the answers the
     * identity is assembled from.
     *
     * No `buyer` block: the shipped placement is pre-payment (`[22.17]`), so
     * checkout has not run and the questionnaire is the only source there is.
     */
    private function seedJourney(): void
    {
        $this->pdo->prepare('DELETE FROM sessions')->execute();

        (new \AsterMD\Storefront\Repository\SessionRepository(fn (): \PDO => $this->pdo))->insert(
            self::SESSION,
            [
                'form_status' => [self::TELEFORM => JourneyState::FORM_COMPLETED],
                'form_answers' => [self::TELEFORM => [
                    'first_name' => 'Dana',
                    'last_name' => 'Reyes',
                    'email' => 'dana.reyes@example.test',
                    'phone' => '4155550142',
                    'date_of_birth' => self::DOB,
                    'street' => '900 Larkin Avenue',
                    'city' => 'Portland',
                    'state' => 'OR',
                    'zip' => '97205',
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
        $dir = sys_get_temp_dir() . '/verify-page-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->registerTempDirForCleanup($dir);

        return $dir;
    }

    private function gateway(?bool $status = true): FakeIdentityGateway
    {
        return new FakeIdentityGateway(enabled: true, valid: $status);
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

    /**
     * The questionnaire, and the `db_fields` map that is the only mapping
     * between an answer and an identity field (`[13.5b]`).
     */
    private static function teleformGateway(): TeleformGateway
    {
        return new class () implements TeleformGateway {
            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'tests/verify-page.json',
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

/**
 * An {@see IdentityGateway} that answers whatever the case needs and records
 * what it was asked.
 *
 * A stub rather than the live gateway because the pass branch **cannot** be
 * exercised against the provider: twenty recorded calls across nine identities
 * returned `valid: false` without exception, so there is no input that produces
 * a pass and a live-walk test expecting one would be asserting a fiction.
 */
final class FakeIdentityGateway implements IdentityGateway
{
    /** @var list<array<string, mixed>> every payload this gateway was handed, in order */
    public array $identities = [];

    /** @var list<string> the check slugs it was asked for, in order */
    public array $checks = [];

    public function __construct(
        private readonly bool $enabled,
        private readonly ?bool $valid = null,
    ) {
    }

    public function verify(string $check, array $identity): IdentityVerdict
    {
        $this->identities[] = $identity;
        $this->checks[] = $check;

        return IdentityVerdict::fromProviderData($check, [
            'check' => $check,
            'valid' => $this->valid,
            'basis' => 'score',
            'score' => $this->valid === true ? 0.91 : 0,
            'threshold' => 0.75,
            'reasons' => $this->valid === false ? [['code' => 'address_invalid', 'message' => 'no']] : [],
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

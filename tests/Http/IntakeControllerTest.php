<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The intake HTTP surface: the two questionnaire steps, the progressive save,
 * the submit and the early-capture endpoint, driven through the whole
 * application the way {@see CartControllerTest} drives the cart.
 *
 * The test container binds {@see \AsterMD\Storefront\Forms\NullTeleformGateway},
 * whose metadata resolves to nothing at all — which is correct, because a test
 * must never reach the EMR, and is also why every case that wants a form on
 * screen substitutes a gateway serving the recorded definition. The definition
 * cache is redirected to a temp directory for the same reason: a test must not
 * leave a cached form behind for the next one to find.
 *
 * Two of these cases are here because of what they would have caught rather
 * than what they describe. `POST /intake/save/` is asserted with the database
 * unreachable, because a GET-only degradation suite once stayed green while
 * every mutation answered 500. And the capture endpoint's body is asserted for
 * what it does *not* contain: an answer echoed into a response is an answer in
 * every proxy and access log between here and the visitor.
 */
final class IntakeControllerTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = 'sess-1234567890abcdef';

    /**
     * A complete, qualifying first page of the recorded form. 66 inches and
     * 200 pounds put the derived BMI at 32, clear of the form's own
     * `bmi_measurement less_than '27'` hard stop.
     */
    private const array PAGE_ONE = [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'phone' => '5551234567',
        'date_of_birth' => '1990-04-01',
        'age_confirmation' => ['yes'],
        'sex_at_birth' => ['female'],
        'pregnancy_status' => ['no'],
        'comorbidities_conditions' => ['high-blood-pressure'],
        'bmi_height' => '66',
        'bmi_weight' => '200',
    ];

    /** Pages two to five of the recorded form, answered so that no rule fires. */
    private const array REMAINING_PAGES = [
        'mtc_men2_history' => ['no'],
        'type1_diabetes_dka' => ['no'],
        'pancreatitis_history' => ['no'],
        'gi_conditions' => ['no'],
        'gallbladder_kidney_liver_disease' => ['no'],
        'eating_disorder_mental_health' => ['no'],
        'other_glp1_medications' => ['no'],
        'insulin_sulfonylureas' => ['no'],
        'oral_birth_control' => ['no'],
        'other_medications_supplements' => ['no'],
        'known_allergies' => ['no'],
        'side_effect_acknowledgment' => 'on',
        'telehealth_consent' => 'on',
    ];

    private string $cacheDir;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    /** The lead gateway {@see self::app()} bound, for the cases that assert a record was written. */
    private \AsterMD\Storefront\Tests\Support\FakeLeadGateway $leads;

    /** The connection {@see self::app()} bound, so a case can read the journey back out of it. */
    private \PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cacheDir = sys_get_temp_dir() . '/intake-controller-' . bin2hex(random_bytes(6));
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

        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->originalEnv = [];
    }

    public function testTheIntakeStepRendersEveryPageOfTheFormInOneResponse(): void
    {
        // `[10.6]`/`[10.6a]`: all five pages are in the one response and all
        // but the first are concealed, so navigation is a class toggle and the
        // whole questionnaire exists even where scripts never run.
        $this->seedCart();
        $response = $this->app()->handle($this->get('/intake/medical/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-intake-form', $body);
        for ($page = 0; $page < 5; $page++) {
            self::assertStringContainsString(sprintf('data-intake-page="%d"', $page), $body);
        }
        self::assertStringContainsString('id="intake-first_name"', $body, 'the first page');
        self::assertStringContainsString('data-intake-field="telehealth_consent"', $body, 'the last page');
        self::assertStringContainsString('content="noindex', $body, '[24.6]: a funnel step is never indexed');
    }

    public function testADefinitionThatCannotBeResolvedIsAStatedOutageRatherThanACrash(): void
    {
        // `[10.3]`: the null gateway resolves no metadata at all, which is the
        // same position a real outage leaves the storefront in.
        $this->seedCart();
        $response = $this->appWithoutAForm()->handle($this->get('/intake/medical/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('can&rsquo;t load your questionnaire', (string) $response->getBody());
    }

    public function testASaveWithoutACsrfTokenIsRejected(): void
    {
        $this->seedCart();
        $app = $this->app();
        $this->csrfToken($app);

        $response = $this->post($app, '/intake/save/', ['page' => 0, ...self::PAGE_ONE]);

        self::assertSame(419, $response->getStatusCode());
    }

    public function testAValidPageIsPersistedAndTheVisitorIsSentBackToTheForm(): void
    {
        $this->seedCart();
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => 0, ...self::PAGE_ONE]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/intake/medical/', $response->getHeaderLine('Location'));

        // Persisted, not merely accepted: the next render prefills from the
        // journey, so the answer coming back is proof it was stored.
        $redrawn = (string) $app->handle($this->get('/intake/medical/'))->getBody();
        self::assertStringContainsString('value="Ada"', $redrawn);
        self::assertStringContainsString('value="ada@example.com"', $redrawn);
    }

    public function testAnInvalidPageIsRedrawnWithTheErrorAndTheVisitorsOwnInputStillInPlace(): void
    {
        $this->seedCart();
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => 0, 'first_name' => 'Ada']);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Last Name is required.', $body);
        self::assertStringContainsString('aria-invalid="true"', $body, '[25.4]');
        // Nobody should have to retype what they already typed to see an error
        // about a different field.
        self::assertStringContainsString('value="Ada"', $body);
    }

    public function testASaveThatTripsAHardRuleSendsTheVisitorToTheTerminalPage(): void
    {
        // `[10.44a]`: the derived BMI falls under the form's own threshold, and
        // the server stops the journey rather than letting the step advance.
        $this->seedCart();
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/intake/save/', [
            '_csrf' => $token, 'page' => 0, ...self::PAGE_ONE, 'bmi_weight' => '120',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/not-eligible/', $response->getHeaderLine('Location'));
    }

    public function testACompletedSubmissionMovesTheVisitorOnAndUnlocksTheStepBehindIt(): void
    {
        $this->seedCart();
        $app = $this->app();
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/intake/submit/', [
            '_csrf' => $token, ...self::PAGE_ONE, ...self::REMAINING_PAGES,
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertNotSame(
            '/intake/medical/',
            $response->getHeaderLine('Location'),
            'a completed form does not send the visitor back to itself',
        );

        // What "completed" has to mean downstream: the precondition guarding
        // checkout is now satisfied, which it was not before this post.
        self::assertSame(200, $app->handle($this->get('/checkout/'))->getStatusCode());
    }

    public function testAnEligibilityAnswerIsFiledAgainstTheEligibilityFormAndNotTheMedicalOne(): void
    {
        // `[8.2]`: the save, submit and capture endpoints are shared by both
        // questionnaire steps and none of their paths names one, so the posted
        // `step` field is the only thing that can tell them apart. Guessing
        // from the URI files every eligibility answer against the medical
        // form — which completes a questionnaire the visitor was never shown
        // and opens checkout behind it.
        $this->seedCart();
        $app = $this->app(self::contactForm(), [ProductCatalog::class => self::twoFormCatalog()]);
        $token = $this->csrfToken($app);

        $rendered = (string) $app->handle($this->get('/intake/eligibility/'))->getBody();
        self::assertStringContainsString('name="step"', $rendered, 'the step has to be posted back');
        self::assertStringContainsString('value="prequalification"', $rendered);

        $submitted = $this->post($app, '/intake/submit/', [
            '_csrf' => $token,
            'step' => 'prequalification',
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        self::assertSame(303, $submitted->getStatusCode());

        $state = $this->journeyState();
        self::assertSame(['tf-eligibility' => 'completed'], $state['form_status']);
        self::assertSame('Ada', $state['form_answers']['tf-eligibility']['first_name']);
        self::assertArrayNotHasKey('tf-medical', $state['form_answers']);

        // The consequence of getting this wrong, asserted rather than
        // inferred: the medical intake has not been rendered, let alone
        // answered, so checkout stays shut (`[8.4]`).
        $checkout = $app->handle($this->get('/checkout/'));
        self::assertSame(302, $checkout->getStatusCode());
        self::assertNotSame('/checkout/', $checkout->getHeaderLine('Location'));

        // The URI-based fallback is still there for a body that names no step
        // at all, and it still answers "intake" — which is what makes the
        // field above load-bearing rather than decorative.
        $this->post($app, '/intake/submit/', [
            '_csrf' => $token,
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        self::assertSame('completed', $this->journeyState()['form_status']['tf-medical'] ?? null);
    }

    public function testCheckoutIsStillGuardedUntilTheFormIsActuallyCompleted(): void
    {
        // The other half of the claim above. Without it, the assertion that
        // checkout opens could not tell "the submit unlocked it" from "it was
        // never locked".
        $this->seedCart();
        $response = $this->app()->handle($this->get('/checkout/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/intake/medical/', $response->getHeaderLine('Location'));
    }

    public function testCaptureReportsThatARecordWasMadeAndIsRefusedOnTheFourthCallInTheWindow(): void
    {
        // `[9.4]`: two fields and an abandonment is still a lead. The limiter
        // allows three calls per window, so the fourth is refused — which is a
        // stuck retry loop being stopped, not a visitor being punished.
        $this->seedCart();
        $app = $this->app(self::contactForm());
        $token = $this->csrfToken($app);
        $body = ['_csrf' => $token, 'first_name' => 'Ada', 'email' => 'ada@example.com'];

        // `[9.6]`: the answer distinguishes "a record was created" from
        // "there already was one", so only the first call reports true. All
        // three are accepted; it is the fourth the limiter refuses.
        $first = $this->post($app, '/intake/capture/', $body);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('{"captured":true}', (string) $first->getBody());

        for ($call = 2; $call <= 3; $call++) {
            $response = $this->post($app, '/intake/capture/', $body);

            self::assertSame(200, $response->getStatusCode(), 'call ' . $call);
            self::assertSame('{"captured":false}', (string) $response->getBody(), 'call ' . $call);
        }

        self::assertCount(1, $this->leads->creates, 'one create, then updates');
        self::assertCount(2, $this->leads->updates);

        $fourth = $this->post($app, '/intake/capture/', $body);

        self::assertSame(429, $fourth->getStatusCode());
        self::assertSame('{"captured":false}', (string) $fourth->getBody());
    }

    public function testCaptureNeverEchoesAnAnswerBackInItsResponseBody(): void
    {
        // `[9.6]` needs one bit of information, and a clinical answer in a
        // response body is a clinical answer in every log that body passes
        // through (`[20.6]`).
        $this->seedCart();
        $app = $this->app(self::contactForm());
        $token = $this->csrfToken($app);

        $response = $this->post($app, '/intake/capture/', [
            '_csrf' => $token,
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $body = (string) $response->getBody();

        self::assertSame('{"captured":true}', $body);
        self::assertCount(1, $this->leads->creates, 'the flag has to mean a record was written');
        self::assertStringNotContainsString('Ada', $body);
        self::assertStringNotContainsString('ada@example.com', $body);
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testTheIntakeStepAndItsSavePostBothStillWorkWithTheDatabaseUnreachable(): void
    {
        // `[20.1]`: the durable journey is a copy, so an outage costs the
        // visitor their saved answers and nothing else. The POST is the
        // assertion that matters — a page that renders while every mutation
        // answers 500 is exactly the shape of bug this pins.
        $this->makeTheDatabaseUnreachable();
        $this->seedCart();
        $app = $this->appWithNoDatabase();

        $rendered = $app->handle($this->get('/intake/medical/'));
        self::assertSame(200, $rendered->getStatusCode());
        self::assertStringContainsString('data-intake-form', (string) $rendered->getBody());

        $token = (string) $_SESSION['_csrf'];
        $saved = $this->post($app, '/intake/save/', ['_csrf' => $token, 'page' => 0, ...self::PAGE_ONE]);

        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/intake/medical/', $saved->getHeaderLine('Location'));

        $captured = $this->post($app, '/intake/capture/', [
            '_csrf' => $token, 'first_name' => 'Ada', 'email' => 'ada@example.com',
        ]);

        self::assertSame(200, $captured->getStatusCode());
    }

    public function testACompletedSubmitWithTheDatabaseUnreachableIsNotBouncedBackToTheFormForever(): void
    {
        // `[20.1]`: with no journey to read, whether the questionnaire is
        // outstanding is unknowable — and answering "still outstanding" sends
        // the visitor back to the form they have just submitted, again on the
        // next submit, for as long as the outage lasts. Failing forward costs
        // the guard at checkout nothing: it degrades the same way and is still
        // the thing that decides.
        $this->makeTheDatabaseUnreachable();
        $this->seedCart();
        $app = $this->appWithNoDatabase();

        $app->handle($this->get('/intake/medical/'));
        $token = (string) $_SESSION['_csrf'];

        $response = $this->post($app, '/intake/submit/', [
            '_csrf' => $token, ...self::PAGE_ONE, ...self::REMAINING_PAGES,
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertNotSame(
            '/intake/medical/',
            $response->getHeaderLine('Location'),
            'an unknowable completion must not become an infinite loop',
        );
        // Named, not merely "anywhere else": the routing decision reads a
        // missing journey as "nothing is finished", which is right for the
        // step guard and wrong here, so this is the one destination the
        // controller still chooses for itself.
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));
    }

    /**
     * The whole application with a throwaway database, a definition cache of
     * its own, and a gateway that serves one form without leaving the process.
     *
     * @param array<string, mixed>|null $definition the form to serve; the recorded one by default
     * @param array<string, mixed>      $extra      further container bindings, for a case that needs its own catalog
     */
    private function app(?array $definition = null, array $extra = []): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo = $this->tempPdo(),
            // Defaults to the theme's original sample seed rather than
            // whatever `bin/console theme:sync --apply` currently has synced
            // into config/products.generated.php — twoFormCatalog() overrides
            // this for the one case that needs a catalog the sample seed
            // can't express (see its own docblock).
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            TeleformGateway::class => self::gateway($definition ?? self::recordedForm()),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
            // The container binds a null lead gateway when analytics is off,
            // and a null gateway writes nothing — so "was a record made" is
            // truthfully no. Anything asserting on that answer has to supply a
            // gateway that writes.
            \AsterMD\Storefront\Forms\LeadGateway::class => $this->leads = new \AsterMD\Storefront\Tests\Support\FakeLeadGateway(),
            // Without a minted session there is no journey, so nothing is
            // stored and every journey assertion would pass vacuously.
            \AsterMD\Storefront\Emr\SessionGateway::class => new \AsterMD\Storefront\Tests\Support\FakeSessionGateway(mintUuid: '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30'),
            ...$extra,
        ]);
    }

    /**
     * The durable journey behind the session cookie these cases post with.
     *
     * Read back from the database rather than from a store, because what a
     * post filed and where it filed it is only settled once it is persisted.
     *
     * @return array<string, mixed>
     */
    private function journeyState(): array
    {
        $row = (new SessionRepository(fn (): \PDO => $this->pdo))->find(self::SESSION);
        self::assertNotNull($row, 'the post had a journey to write to');

        return $row['journey_state'];
    }

    /**
     * A catalog whose one product asks for a dedicated eligibility step and
     * names a different form for each of the two steps, so which form an
     * answer was filed against is observable. The shipped sample catalog has
     * no such product: every entry folds its eligibility questions into the
     * intake form, which is the configuration that hid this.
     */
    private static function twoFormCatalog(): ProductCatalog
    {
        return new FakeCatalog(['semaglutide' => [
            'requires_prequalification' => true,
            'prequalification_teleform_id' => 'tf-eligibility',
            'teleform_id' => 'tf-medical',
        ]]);
    }

    /** The same application with the container's own null gateway left in place. */
    private function appWithoutAForm(): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
        ]);
    }

    /** No `\PDO` override, so the unreachable connection details in the environment are used. */
    private function appWithNoDatabase(): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            TeleformGateway::class => self::gateway(self::recordedForm()),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
        ]);
    }

    /** A driver and port nothing can be listening on, so the attempt fails at once rather than hanging. */
    private function makeTheDatabaseUnreachable(): void
    {
        foreach (['DB_DRIVER' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'nothing_here'] as $key => $value) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }
    }

    /**
     * One Rx line whose product declares the sample catalog's real teleform
     * id, which is what makes `[8.3]`'s "the first line that declares one"
     * resolve to a form at all.
     */
    private function seedCart(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => 'semaglutide', 'name' => 'Semaglutide', 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 4600, 'variant_id' => 'semaglutide-3m',
        ]]];
    }

    /** Seeds the CSRF token with a GET, exactly as {@see CsrfTest} does. */
    private function csrfToken(App $app): string
    {
        $app->handle($this->get('/'));

        return (string) $_SESSION['_csrf'];
    }

    /**
     * A GET carrying the session cookie, so the journey these cases read back
     * from is the one the post wrote to — no session is minted on a POST.
     */
    private function get(string $path): \Psr\Http\Message\ServerRequestInterface
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

    /** @param array<string, mixed> $definition */
    private static function gateway(array $definition): TeleformGateway
    {
        return new class($definition) implements TeleformGateway {
            /** @param array<string, mixed> $definition */
            public function __construct(private readonly array $definition)
            {
            }

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    // Real identifiers carry the form's version and publish
                    // epoch, so a republish is a cache miss. Deriving it from
                    // the definition keeps that property here.
                    identifier: 'acct/org/form_' . md5((string) json_encode($this->definition)) . '.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: [
                        'first_name' => 'opportunity.first_name',
                        'last_name' => 'opportunity.last_name',
                        'email' => 'opportunity.email',
                        'phone' => 'opportunity.phone',
                        'bmi_measurement' => 'opportunity.clinical.bmi',
                        'bmi_height' => 'opportunity.clinical.height.value',
                        'bmi_weight' => 'opportunity.clinical.weight.value',
                    ],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return $this->definition;
            }
        };
    }

    /** The real 5-page, 56-field weight-loss intake recorded from staging. @return array<string, mixed> */
    private static function recordedForm(): array
    {
        return (array) json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/teleform-definition.json'),
            true,
        );
    }

    /**
     * A one-page form asking only for a name and an email, so a capture posting
     * exactly those two fields is a complete first page. The recorded form's
     * first page asks eleven questions, which would make an early capture a
     * failed page validation rather than a captured lead.
     *
     * @return array<string, mixed>
     */
    private static function contactForm(): array
    {
        return [
            'formId' => 'f-contact',
            'formName' => 'Contact',
            'pages' => [[
                'pageId' => 'p1',
                'title' => 'About you',
                'order' => 0,
                'fields' => [
                    ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
                    ['fieldId' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ],
            ]],
        ];
    }
}

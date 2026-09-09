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
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The terminal page and the way back off it.
 *
 * The explanation is the assertion that matters: it is the form author's own
 * wording for the rule that fired (`[10.42]`), and it is the only place the
 * visitor learns why they stopped. A generic line here would be a page that
 * technically renders and tells nobody anything, which is why the authored
 * text is pinned rather than merely the presence of a message region.
 *
 * Start Over is the destructive way off, and it is where the triggering line
 * leaves the cart (`[10.47]`) — deliberately here rather than when the rule
 * fired, so a resumed terminal state still has the line to re-evaluate
 * against (`[10.48]`).
 */
final class NotEligibleTest extends TestCase
{
    use TempDatabase;

    private const string TELEFORM = '6a8aec0dec745c70f5f3e69a';

    private const string SESSION = 'sess-1234567890abcdef';

    /** The authored wording the page has to reproduce verbatim. */
    private const string ALERT_TEXT = 'We cannot prescribe this treatment during pregnancy.';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testThePageExplainsTheStopInTheWordingTheFormAuthorWrote(): void
    {
        $app = $this->app();
        $this->seedCart();
        $this->disqualify($app);

        $response = $this->get($app, '/not-eligible/');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(self::ALERT_TEXT, $body, '[10.42]: the clinician\'s wording, not a generic line');
        self::assertStringContainsString('Semaglutide', $body, 'the page names the treatment the stop was about');
        self::assertStringContainsString('Review my answers', $body, '[10.43]: a mistyped answer must be correctable');
        self::assertStringNotContainsString('refund', $body, 'nothing has been paid at this point in the funnel');
    }

    public function testAVisitorWhoJustTypedTheUrlGetsANeutralPageAndNoWayBackIntoAForm(): void
    {
        $app = $this->app();

        $response = $this->get($app, '/not-eligible/');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Requirements not met', $body);
        self::assertStringNotContainsString(self::ALERT_TEXT, $body, 'no rule fired, so there is no authored reason to show');
        self::assertStringContainsString('>this</span> treatment', $body, 'the neutral fallback names no treatment');
        self::assertStringNotContainsString(
            'Review my answers',
            $body,
            'there are no answers to review, so the control that returns to them is not offered',
        );
    }

    public function testStartingOverClearsTheVerdictTheAnswersAndTheTriggeringLine(): void
    {
        $app = $this->app();
        // A second, independently purchasable line, so what Start Over removes
        // can be told apart from what it merely happens to survive (`[10.47]`).
        $this->seedCart(withLab: true);
        $token = $this->disqualify($app);

        $state = $this->state($app);
        self::assertSame('pregnancy_hard_stop', $state->disqualifiedRule);
        self::assertNotSame([], $state->answersFor(self::TELEFORM));

        $response = $this->post($app, '/intake/start-over/', ['_csrf' => $token]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'), 'the destructive option returns the visitor to browsing');

        $state = $this->state($app);
        self::assertNull($state->disqualifiedRule);
        self::assertNull($state->disqualifiedTeleform);
        self::assertFalse($state->isDisqualified());
        self::assertSame([], $state->answersFor(self::TELEFORM), 'the answers behind the verdict go with it');
        self::assertSame(
            ['comprehensive-metabolic-panel'],
            array_column((array) ($_SESSION['cart']['lines'] ?? []), 'slug'),
            '[10.47]: the prescription the stop was about leaves the cart, the lab panel stays',
        );

        $body = (string) $this->get($app, '/not-eligible/')->getBody();
        self::assertStringNotContainsString(self::ALERT_TEXT, $body, 'the page no longer claims a verdict');
        self::assertStringNotContainsString('Review my answers', $body);
    }

    /**
     * A verdict from the dedicated eligibility form sends the visitor back to
     * the eligibility step, not to the medical one.
     *
     * Answering the wrong questionnaire cannot change the answer that stopped
     * them, so the way back has to name the step that actually collected the
     * form. The disqualifying answer is posted with `step=prequalification`,
     * which is what the eligibility template emits: the save endpoint is shared
     * by both steps and the posted field is the only thing that distinguishes
     * them.
     */
    public function testTheWayBackNamesTheStepThatCollectedTheForm(): void
    {
        $app = $this->appWithCatalog(['semaglutide' => [
            'slug' => 'semaglutide', 'name' => 'Semaglutide', 'kind' => 'rx', 'emr_product_id' => null,
            'requires_prequalification' => true,
            'prequalification_teleform_id' => self::TELEFORM,
            'variants' => [['id' => 'semaglutide-1m', 'name' => 'Monthly', 'price_cents' => 5000]],
        ]]);
        $this->seedCart();

        $this->get($app, '/intake/eligibility/');
        $save = $this->post($app, '/intake/save/', [
            '_csrf' => (string) $_SESSION['_csrf'],
            'step' => 'prequalification',
            'page' => '0',
            'first_name' => 'Dana',
            'pregnancy_status' => 'yes',
        ]);
        self::assertSame('/not-eligible/', $save->getHeaderLine('Location'));

        $body = (string) $this->get($app, '/not-eligible/')->getBody();
        self::assertStringContainsString(self::ALERT_TEXT, $body, 'the eligibility form is where the answer was filed');
        self::assertStringContainsString('href="/intake/eligibility/"', $body, '[10.43]: back to the form that stopped them');
    }

    /**
     * A form named without `requires_prequalification` is not collected by the
     * eligibility step (`[8.2]`), so a verdict on it must not send the visitor
     * there.
     *
     * The line that names it here is not even the line that was asked: the
     * prescription declares the medical form, a second line names the same
     * questionnaire as an eligibility form it never opted into, and the stop
     * came from the medical step. Naming the eligibility step would hand the
     * visitor a link to a step nothing serves — it forwards straight back to
     * this page — so their one chance to correct a mistyped answer would bounce.
     */
    public function testAFormNamedWithoutTheOptInDoesNotSendTheVisitorToAStepNothingServes(): void
    {
        $app = $this->appWithCatalog([
            'semaglutide' => [
                'slug' => 'semaglutide', 'name' => 'Semaglutide', 'kind' => 'rx', 'emr_product_id' => null,
                'teleform_id' => self::TELEFORM,
                'variants' => [['id' => 'semaglutide-1m', 'name' => 'Monthly', 'price_cents' => 5000]],
            ],
            'comprehensive-metabolic-panel' => [
                'slug' => 'comprehensive-metabolic-panel', 'name' => 'Comprehensive Metabolic Panel',
                'kind' => 'lab', 'emr_product_id' => null,
                'prequalification_teleform_id' => self::TELEFORM,
                'variants' => [['id' => 'comprehensive-metabolic-panel-panel', 'name' => 'Panel', 'price_cents' => 8900]],
            ],
        ]);
        $this->seedCart(withLab: true);
        $this->disqualify($app);

        $body = (string) $this->get($app, '/not-eligible/')->getBody();

        self::assertStringContainsString('href="/intake/medical/"', $body, 'the step that collected the form');
        self::assertStringNotContainsString('href="/intake/eligibility/"', $body, 'a step no line asked for');
    }

    /**
     * Posts the answer the inline form disqualifies on and returns the CSRF
     * token, so a caller can go on to post Start Over with it.
     */
    private function disqualify(App $app): string
    {
        $this->get($app, '/intake/medical/');
        $token = (string) $_SESSION['_csrf'];

        $response = $this->post($app, '/intake/save/', [
            '_csrf' => $token,
            'page' => '0',
            'first_name' => 'Dana',
            'pregnancy_status' => 'yes',
        ]);

        self::assertSame('/not-eligible/', $response->getHeaderLine('Location'), 'the save is what stops the journey [10.45]');

        return $token;
    }

    /**
     * The same application over a literal catalog, for the cases about which
     * step a form belongs to.
     *
     * The shipped sample catalog cannot express those: every product there
     * folds its eligibility questions into the intake form, so no entry both
     * asks for the dedicated step and names its own questionnaire, and none
     * names one without asking.
     *
     * @param array<string, array<string, mixed>> $products
     */
    private function appWithCatalog(array $products): App
    {
        return $this->app([ProductCatalog::class => new FakeCatalog($products)]);
    }

    /** @param array<string, mixed> $extra further container bindings */
    private function app(array $extra = []): App
    {
        $cacheDir = sys_get_temp_dir() . '/storefront-teleform-' . bin2hex(random_bytes(6));
        $this->registerTempDirForCleanup($cacheDir);

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            // Defaults to the theme's original sample seed rather than
            // whatever `bin/console theme:sync --apply` currently has synced
            // into config/products.generated.php — appWithCatalog() overrides
            // this for the cases that need a catalog the sample seed can't
            // express (see its own docblock).
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            // A journey has to exist for a verdict to be recorded on, and the
            // null gateway mints nothing.
            SessionGateway::class => new FakeSessionGateway(mintUuid: self::SESSION),
            TeleformGateway::class => self::gateway(),
            DefinitionCache::class => new DefinitionCache($cacheDir, 0),
            ...$extra,
        ]);
    }

    private function seedCart(bool $withLab = false): void
    {
        $lines = [[
            'slug' => 'semaglutide', 'name' => 'Semaglutide', 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => 'semaglutide-1m',
        ]];

        if ($withLab) {
            $lines[] = [
                'slug' => 'comprehensive-metabolic-panel', 'name' => 'Comprehensive Metabolic Panel', 'kind' => 'lab',
                'emr_product_id' => null, 'parent_slug' => null,
                'quantity' => 1, 'unit_price_cents' => 8900, 'variant_id' => 'comprehensive-metabolic-panel-panel',
            ];
        }

        $_SESSION['cart'] = ['session' => self::SESSION, 'territory' => null, 'lines' => $lines];
    }

    /**
     * A two-question form whose one `danger` alert is conditioned on an answer
     * this test controls. Inline rather than the recorded fixture because what
     * is under test is that the page reproduces *authored* wording, and an
     * inline definition is where the expected string is visible next to the
     * assertion.
     */
    private static function gateway(): TeleformGateway
    {
        $definition = ['formId' => self::TELEFORM, 'pages' => [
            ['pageId' => 'page_1', 'order' => 0, 'fields' => [
                [
                    'fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text',
                    'label' => 'First name', 'required' => true,
                ],
                [
                    'fieldId' => 'pregnancy_status', 'name' => 'pregnancy_status', 'type' => 'choice-single',
                    'label' => 'Are you pregnant or planning a pregnancy?', 'required' => true,
                    'properties' => ['options' => [
                        ['label' => 'Yes', 'value' => 'yes'],
                        ['label' => 'No', 'value' => 'no'],
                    ]],
                ],
                [
                    'fieldId' => 'pregnancy_hard_stop', 'name' => 'pregnancy_hard_stop', 'type' => 'alert',
                    'label' => 'Pregnancy notice',
                    'properties' => ['alertType' => 'danger', 'alertText' => self::ALERT_TEXT],
                    'conditions' => [[
                        'conditionId' => 'cond_pregnancy_hard_stop', 'action' => 'show', 'logic' => 'and',
                        'rules' => [['field' => 'pregnancy_status', 'operator' => 'equals', 'value' => 'yes']],
                    ]],
                ],
            ]],
        ]];

        return new class ($definition) implements TeleformGateway {
            /** @param array<string, mixed> $definition */
            public function __construct(private readonly array $definition)
            {
            }

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'tests/not-eligible.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: ['first_name' => 'opportunity.first_name', 'email' => 'opportunity.email'],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return $this->definition;
            }
        };
    }

    private function state(App $app): JourneyState
    {
        $state = $app->getContainer()?->get(JourneyStore::class)->state();
        self::assertInstanceOf(JourneyState::class, $state);

        return $state;
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
                ->withCookieParams(['amd_session' => self::SESSION]),
        );
    }

    /** @param array<string, string> $body */
    private function post(App $app, string $path, array $body): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withCookieParams(['amd_session' => self::SESSION])
                ->withParsedBody($body),
        );
    }
}

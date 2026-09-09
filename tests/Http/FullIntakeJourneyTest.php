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
 * The funnel walked end to end, one test per path, through real requests.
 *
 * Every other test in this suite covers one collaborator. This one covers the
 * seam between them, which is where the interesting failures live: a
 * precondition, a routing decision and a controller can each be right on their
 * own while the journey they compose still strands a visitor. So nothing here
 * reaches into a collaborator to arrange state — the cart is filled by posting
 * to `/cart/add/`, the questionnaire by posting to `/intake/save/`, and every
 * redirect is followed by hand.
 */
final class FullIntakeJourneyTest extends TestCase
{
    use TempDatabase;

    /** The teleform the sample catalog declares on `semaglutide`. */
    private const string TELEFORM = '6a8aec0dec745c70f5f3e69a';

    private const string SESSION = 'sess-1234567890abcdef';

    private const string ALERT_TEXT = 'We cannot prescribe this treatment during pregnancy.';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAQualifyingJourneyFinishesTheFormAndOpensCheckout(): void
    {
        $app = $this->app();
        $token = $this->addToCart($app, 'semaglutide', 'semaglutide-1m');

        self::assertSame(200, $this->get($app, '/intake/medical/')->getStatusCode());

        $firstPage = $this->post($app, '/intake/save/', [
            '_csrf' => $token, 'page' => '0',
            'first_name' => 'Dana', 'email' => 'dana@example.test', 'pregnancy_status' => 'no',
        ]);
        self::assertSame(303, $firstPage->getStatusCode());
        self::assertSame('/intake/medical/', $firstPage->getHeaderLine('Location'), 'a saved page returns to the form');

        $secondPage = $this->post($app, '/intake/save/', [
            '_csrf' => $token, 'page' => '1', 'telehealth_consent' => '1',
        ]);
        self::assertSame('/intake/medical/', $secondPage->getHeaderLine('Location'));

        // Nothing but the token: every answer was already stored page by page,
        // so a submission that carries none of them still validates (`[10.27]`).
        $submit = $this->post($app, '/intake/submit/', ['_csrf' => $token]);
        self::assertSame(303, $submit->getStatusCode());
        // `[8.4]`: completing the intake leads to checkout. The routing table
        // is not consulted for this, because "the cart holds a prescription"
        // stays true after the questionnaire is done and would send the visitor
        // straight back to it.
        self::assertSame('/checkout/', $submit->getHeaderLine('Location'));

        self::assertTrue($this->state($app)->formCompleted(self::TELEFORM));

        $checkout = $this->get($app, '/checkout/');
        self::assertSame(200, $checkout->getStatusCode(), 'a completed intake stops the guard bouncing checkout');
        self::assertSame('', $checkout->getHeaderLine('Location'));
    }

    public function testADisqualifyingAnswerStopsTheJourneyAndKeepsCheckoutShut(): void
    {
        $app = $this->app();
        $token = $this->addToCart($app, 'semaglutide', 'semaglutide-1m');
        $this->get($app, '/intake/medical/');

        $save = $this->post($app, '/intake/save/', [
            '_csrf' => $token, 'page' => '0',
            'first_name' => 'Dana', 'email' => 'dana@example.test', 'pregnancy_status' => 'yes',
        ]);

        self::assertSame(303, $save->getStatusCode());
        self::assertSame('/not-eligible/', $save->getHeaderLine('Location'), '[10.45]: the save is evaluated, not just the submit');

        $state = $this->state($app);
        self::assertSame('pregnancy_hard_stop', $state->disqualifiedRule, '[10.46]: which rule fired is the point of recording one');
        self::assertSame(self::TELEFORM, $state->disqualifiedTeleform);
        self::assertFalse($state->formCompleted(self::TELEFORM));

        $checkout = $this->get($app, '/checkout/');
        self::assertSame(302, $checkout->getStatusCode());
        self::assertSame(
            '/not-eligible/',
            $checkout->getHeaderLine('Location'),
            'a hard stop outranks every other routing consideration',
        );

        $terminal = $this->get($app, '/not-eligible/');
        self::assertSame(200, $terminal->getStatusCode(), 'the terminal page carries no requirements, or it would bounce too');
        self::assertStringContainsString(self::ALERT_TEXT, (string) $terminal->getBody());
    }

    public function testCheckoutBouncesToTheIntakeStepForACartThatHasCollectedNoForm(): void
    {
        $app = $this->app();
        $this->addToCart($app, 'semaglutide', 'semaglutide-1m');

        $checkout = $this->get($app, '/checkout/');

        // [8.6]: no deep link reaches checkout with an uncollected
        // questionnaire, and the guard is the one place that enforces it.
        self::assertSame(302, $checkout->getStatusCode());
        self::assertSame('/intake/medical/', $checkout->getHeaderLine('Location'));

        self::assertSame(200, $this->get($app, '/intake/medical/')->getStatusCode(), 'the step it sends the visitor to is reachable');
    }

    public function testACartWithNothingToAskReachesCheckoutWithNoIntakeAtAll(): void
    {
        $app = $this->app();
        $added = $this->addToCartResponse($app, 'comprehensive-metabolic-panel', 'comprehensive-metabolic-panel-panel');

        // [8.3]: the routing decision already says checkout, because no line
        // declares a questionnaire.
        self::assertSame('/checkout/', $added->getHeaderLine('Location'));

        $checkout = $this->get($app, '/checkout/');
        self::assertSame(200, $checkout->getStatusCode());

        $state = $this->state($app);
        self::assertSame([], $state->formAnswers, 'no form was opened, so none was answered');
        self::assertSame([], $state->formStatus);
    }

    public function testStartingOverAfterAStopMakesTheQuestionnaireReachableAgain(): void
    {
        $app = $this->app();
        $token = $this->addToCart($app, 'semaglutide', 'semaglutide-1m');
        $this->get($app, '/intake/medical/');
        $this->post($app, '/intake/save/', [
            '_csrf' => $token, 'page' => '0',
            'first_name' => 'Dana', 'email' => 'dana@example.test', 'pregnancy_status' => 'yes',
        ]);
        self::assertTrue($this->state($app)->isDisqualified());

        $startOver = $this->post($app, '/intake/start-over/', ['_csrf' => $token]);
        self::assertSame('/', $startOver->getHeaderLine('Location'));

        // Start Over dropped the prescription with the verdict (`[10.47]`), so
        // getting back to the questionnaire means choosing the treatment again.
        $this->addToCart($app, 'semaglutide', 'semaglutide-1m');

        $intake = $this->get($app, '/intake/medical/');
        self::assertSame(200, $intake->getStatusCode(), 'no stale verdict redirects the visitor off the form');
        self::assertSame(
            '',
            self::standingTermination((string) $intake->getBody()),
            'the notice a resumed terminal state would show (`[10.48]`) is gone with the verdict',
        );

        $checkout = $this->get($app, '/checkout/');
        self::assertSame('/intake/medical/', $checkout->getHeaderLine('Location'), 'checkout is guarded again, not terminated');
    }

    private function app(): App
    {
        $cacheDir = sys_get_temp_dir() . '/storefront-teleform-' . bin2hex(random_bytes(6));
        $this->registerTempDirForCleanup($cacheDir);

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            // addToCart() adds `semaglutide` (whose sample-seed teleform id
            // must match self::TELEFORM) and, in one case,
            // `comprehensive-metabolic-panel` (the sample lab override) —
            // neither of which is whatever bin/console theme:sync --apply
            // has currently synced into config/products.generated.php.
            ProductCatalog::class => new FakeCatalog(SampleCatalog::productsWithLabOverride()),
            // Answers, verdicts and form status all live on the journey, and
            // the null gateway mints no session for one to exist on.
            SessionGateway::class => new FakeSessionGateway(mintUuid: self::SESSION),
            TeleformGateway::class => self::gateway(),
            DefinitionCache::class => new DefinitionCache($cacheDir, 0),
        ]);
    }

    /** Fills the cart the way a visitor does, and returns the CSRF token every later post needs. */
    private function addToCart(App $app, string $slug, string $variantId): string
    {
        $this->addToCartResponse($app, $slug, $variantId);

        return (string) $_SESSION['_csrf'];
    }

    private function addToCartResponse(App $app, string $slug, string $variantId): ResponseInterface
    {
        $this->get($app, '/');
        $token = (string) $_SESSION['_csrf'];

        $response = $this->post($app, '/cart/add/', [
            '_csrf' => $token, 'slug' => $slug, 'variant_id' => $variantId, 'quantity' => '1',
        ]);
        self::assertSame(303, $response->getStatusCode(), 'the product was accepted into the cart');

        return $response;
    }

    /**
     * A two-page form: enough for a page-by-page save to mean something, small
     * enough that every required answer is visible beside the assertions. Its
     * one `danger` alert is the hard stop these paths turn on.
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
                    'fieldId' => 'email', 'name' => 'email', 'type' => 'email',
                    'label' => 'Email address', 'required' => true,
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
            ['pageId' => 'page_2', 'order' => 1, 'fields' => [
                [
                    'fieldId' => 'telehealth_consent', 'name' => 'telehealth_consent', 'type' => 'terms',
                    'label' => 'I consent to a telehealth consultation', 'required' => true,
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
                    identifier: 'tests/full-journey.json',
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

    /**
     * The message the form shows on entry when the server has already judged
     * the journey — read from the interstitial itself rather than by searching
     * the page for the wording, since the alert field's authored text is part
     * of the questionnaire's own markup either way.
     */
    private static function standingTermination(string $body): string
    {
        if (preg_match('/data-intake-interstitial-text>(.*?)<\/p>/s', $body, $match) !== 1) {
            self::fail('The form did not render the eligibility interstitial at all.');
        }

        return trim($match[1]);
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

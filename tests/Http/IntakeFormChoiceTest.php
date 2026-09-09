<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Which questionnaire a step puts on screen, driven through real HTTP against
 * the real `config/funnel.php`.
 *
 * Every case here is about one agreement: the step guard decides whether a
 * visitor may pass, and this page decides what they are shown, and if the two
 * resolve "which form" differently the visitor is stranded — the guard sends
 * them to a step, the step serves a form that does not move the guard, and its
 * submit sends them back to the same step at the same URL forever. A browser
 * cannot detect that loop because every hop is a different path.
 *
 * The shape that produced it is a cart with more than one questionnaire in it.
 * `[8.0f]` allows a single prescription per order, but free attachments, OTC
 * lines and accepted order bumps share the cart and may each name a form, so
 * the shipped sample catalog — where every product folds its eligibility
 * questions into one intake form — is the configuration that hides this rather
 * than the rule.
 *
 * The markers are load-bearing: each form's title carries its own id, so which
 * of two forms was rendered is observable in the response body instead of
 * being inferred from a status code.
 */
final class IntakeFormChoiceTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private string $cacheDir;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cacheDir = sys_get_temp_dir() . '/intake-form-choice-' . bin2hex(random_bytes(6));
        $this->registerTempDirForCleanup($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Two intake forms, the first one finished: the medical step must serve the
     * one checkout is still waiting on.
     *
     * Serving the finished form instead is not a cosmetic error — its submit
     * completes a form that is already complete, the routing decision sends the
     * visitor straight back to this step, and checkout becomes permanently
     * unreachable for that cart.
     */
    public function testTheMedicalStepServesTheFormCheckoutIsStillWaitingOn(): void
    {
        $app = $this->app($this->twoIntakeForms(), ['tf-a' => 'completed']);
        $this->seedCart('rx-a', 'otc-b');

        $checkout = $app->handle($this->get('/checkout/'));
        self::assertSame(302, $checkout->getStatusCode(), 'tf-b is outstanding, so checkout is shut');
        self::assertSame('/intake/medical/', $checkout->getHeaderLine('Location'));

        $medical = $app->handle($this->get('/intake/medical/'));
        self::assertSame(200, $medical->getStatusCode());
        $body = (string) $medical->getBody();
        self::assertStringContainsString('FORM-tf-b', $body, 'the outstanding form is the one on screen');
        self::assertStringNotContainsString('FORM-tf-a', $body, 'the finished form is not served again');
    }

    /**
     * With every form it collects finished, the step forwards rather than
     * drawing one.
     *
     * The guard would normally have redirected such a visitor before this page
     * ran, so this is the typed-URL case — and the honest answer to "which form
     * is outstanding" is then none at all. Inventing an identifier to have
     * something to render is what `[20.8]`/`[21.9b]` forbid.
     */
    public function testTheMedicalStepForwardsWhenEveryFormItCollectsIsFinished(): void
    {
        $app = $this->app($this->twoIntakeForms(), ['tf-a' => 'completed', 'tf-b' => 'completed']);
        $this->seedCart('rx-a', 'otc-b');

        $response = $app->handle($this->get('/intake/medical/'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/checkout/', $response->getHeaderLine('Location'));
    }

    /**
     * A product may name an eligibility form without asking for the step that
     * collects it. Pre-qualification is opt-in twice (`[8.2]`), so a form named
     * without `requires_prequalification` is not a gate — and must not be
     * served, because a hard rule inside it would terminate a journey the
     * funnel never required to take that questionnaire.
     */
    public function testTheEligibilityStepServesNothingWhenNoLineAskedForIt(): void
    {
        $app = $this->app([
            'rx-a' => [
                'slug' => 'rx-a', 'name' => 'Rx A', 'kind' => 'rx', 'emr_product_id' => null,
                'prequalification_teleform_id' => 'tf-elig',
                'teleform_id' => 'tf-med',
                'variants' => [['id' => 'rx-a-v1', 'name' => 'Monthly', 'price_cents' => 5000]],
            ],
        ]);
        $this->seedCart('rx-a');

        $response = $app->handle($this->get('/intake/eligibility/'));

        self::assertSame(303, $response->getStatusCode(), 'no gate was declared, so there is nothing to ask');
        self::assertSame('/intake/medical/', $response->getHeaderLine('Location'));
    }

    /**
     * The opt-in and the form it names must come from the same cart line
     * (`[8.2]`). Here an earlier line names a form without asking for the step
     * and a later line asks for it properly, so the earlier line's form is not
     * the one the step collects.
     */
    public function testTheEligibilityStepServesTheOptedInFormNotAnEarlierLinesForm(): void
    {
        $app = $this->app([
            'otc-a' => [
                'slug' => 'otc-a', 'name' => 'Otc A', 'kind' => 'otc', 'emr_product_id' => null,
                'prequalification_teleform_id' => 'tf-a',
                'variants' => [['id' => 'otc-a-v1', 'name' => 'One', 'price_cents' => 2500]],
            ],
            'rx-b' => [
                'slug' => 'rx-b', 'name' => 'Rx B', 'kind' => 'rx', 'emr_product_id' => null,
                'requires_prequalification' => true,
                'prequalification_teleform_id' => 'tf-b',
                'variants' => [['id' => 'rx-b-v1', 'name' => 'Monthly', 'price_cents' => 5000]],
            ],
        ]);
        $this->seedCart('otc-a', 'rx-b');

        $response = $app->handle($this->get('/intake/eligibility/'));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('FORM-tf-b', $body, 'the form the opted-in line named');
        self::assertStringNotContainsString('FORM-tf-a', $body, 'a form no line asked for is never collected');
    }

    /**
     * A standing hard stop re-opens the form that produced it, even when that
     * form was completed before the stop fired.
     *
     * `[10.43]`: a disqualifying answer is often a mistyped one, and the
     * terminal page invites the visitor back to correct it. Resolving the form
     * purely by "the first one not yet finished" would send them to a step with
     * nothing left to ask, which forwards them straight back to the page
     * explaining that they are stopped — with no way to change the answer.
     */
    public function testAStandingHardStopReopensTheFormThatProducedIt(): void
    {
        $app = $this->app($this->twoIntakeForms(), ['tf-a' => 'completed', 'tf-b' => 'completed'], 'tf-a');
        $this->seedCart('rx-a', 'otc-b');

        $response = $app->handle($this->get('/intake/medical/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('FORM-tf-a', (string) $response->getBody(), 'the answer that stopped them is correctable');
    }

    /**
     * The medical form for both lines, so the cart names exactly one
     * questionnaire twice over.
     *
     * @return array<string, array<string, mixed>>
     */
    private function twoIntakeForms(): array
    {
        return [
            'rx-a' => [
                'slug' => 'rx-a', 'name' => 'Rx A', 'kind' => 'rx', 'emr_product_id' => null,
                'teleform_id' => 'tf-a',
                'variants' => [['id' => 'rx-a-v1', 'name' => 'Monthly', 'price_cents' => 5000]],
            ],
            'otc-b' => [
                'slug' => 'otc-b', 'name' => 'Otc B', 'kind' => 'otc', 'emr_product_id' => null,
                'teleform_id' => 'tf-b',
                'variants' => [['id' => 'otc-b-v1', 'name' => 'One', 'price_cents' => 2500]],
            ],
        ];
    }

    /**
     * The whole application over a throwaway database, with the journey these
     * cases read already seeded: form completion is a durable fact, and a
     * request that had to establish it first would be testing the submit
     * endpoint rather than the choice of form.
     *
     * @param array<string, array<string, mixed>> $products
     * @param array<string, string>               $formStatus
     */
    private function app(array $products, array $formStatus = [], ?string $disqualifiedOn = null): App
    {
        $this->pdo = $this->tempPdo();
        $journey = ['reconciled' => true, 'form_status' => $formStatus];
        if ($disqualifiedOn !== null) {
            $journey['disqualified_teleform'] = $disqualifiedOn;
            $journey['disqualified_rule'] = 'rule-1';
        }
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, $journey, null);

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo,
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
            TeleformGateway::class => self::gateway(),
            ProductCatalog::class => new FakeCatalog($products),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
    }

    /** @param string ...$slugs cart lines in order, priced from the catalog above */
    private function seedCart(string ...$slugs): void
    {
        $lines = [];
        foreach ($slugs as $slug) {
            $lines[] = [
                'slug' => $slug, 'name' => ucfirst($slug), 'kind' => str_starts_with($slug, 'rx') ? 'rx' : 'otc',
                'emr_product_id' => null, 'parent_slug' => null,
                'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => $slug . '-v1',
            ];
        }

        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => $lines];
    }

    /**
     * One form per teleform id, each titled after its own id so the response
     * body says which one was served.
     */
    private static function gateway(): TeleformGateway
    {
        return new class implements TeleformGateway {
            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'acct/org/form_' . $teleformId . '.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: [],
                );
            }

            /** @return array<string, mixed>|null */
            public function definition(TeleformMetadata $metadata): ?array
            {
                $marker = 'FORM-' . $metadata->id;

                return [
                    'formId' => $metadata->id,
                    'formName' => $marker,
                    'pages' => [[
                        'pageId' => 'p1',
                        'title' => $marker,
                        'order' => 0,
                        'fields' => [
                            ['fieldId' => 'q1', 'name' => 'q1', 'type' => 'text', 'label' => $marker, 'required' => true],
                        ],
                    ]],
                ];
            }
        };
    }

    private function get(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withCookieParams(['amd_session' => self::SESSION]);
    }
}

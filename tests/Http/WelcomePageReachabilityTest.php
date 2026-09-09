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
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * That the intake welcome page can be entered, and that the one button on it
 * leads to the questionnaire rather than back to itself.
 *
 * Walks real HTTP against the real `config/funnel.php` and the real step
 * guard, because neither half of the guarantee is visible to a unit test. The
 * page is offered to a visitor who has answered nothing — the cart drawer is
 * the surface that offers it — so it has to be *enterable* by exactly that
 * visitor, which is a question about its own preconditions; and its call to
 * action has to lead onward, which is a question about a controller forward.
 *
 * The forward is the part that catches people out. A cart declaring no
 * eligibility form makes `/intake/eligibility/` hand off to the routing
 * decision, so if that decision ever answers "the welcome page" for an empty
 * answer set, the welcome page's own button leads back to the welcome page and
 * the questionnaire becomes unreachable. **The step guard cannot see it**: the
 * hop that closes the loop is a controller forward, not a guard redirect, and
 * an exhaustive walk of the guard alone reports no cycle. That is why the
 * welcome page is a link target and never a routing answer, and this test is
 * what holds the distinction in place.
 */
final class WelcomePageReachabilityTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private string $cacheDir;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cacheDir = sys_get_temp_dir() . '/welcome-loop-' . bin2hex(random_bytes(6));
        $this->registerTempDirForCleanup($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function app(): App
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $pdo))
            ->insert(self::SESSION, ['reconciled' => true, 'form_status' => []], null);

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $pdo,
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
            TeleformGateway::class => self::gateway(),
            ProductCatalog::class => new FakeCatalog([
                'rx-a' => [
                    'slug' => 'rx-a', 'name' => 'Rx A', 'kind' => 'rx', 'emr_product_id' => null,
                    'teleform_id' => 'tf-med',
                    'variants' => [['id' => 'rx-a-v1', 'name' => 'Monthly', 'price_cents' => 5000]],
                ],
            ]),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
    }

    public function testTheWelcomePageIsEnterableAndItsOnlyCallToActionLeadsToTheQuestionnaire(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => 'rx-a', 'name' => 'Rx A', 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => 'rx-a-v1',
        ]]];

        $app = $this->app();
        $factory = new ServerRequestFactory();
        $path = '/intake/';
        $trail = [];

        for ($hop = 0; $hop < 8; $hop++) {
            $response = $app->handle(
                $factory->createServerRequest('GET', $path)->withCookieParams(['amd_session' => self::SESSION]),
            );
            $status = $response->getStatusCode();
            $trail[] = $status . ' ' . $path;

            if ($status === 200) {
                $body = (string) $response->getBody();
                // The welcome page's only call to action.
                if (str_contains($body, 'Start My Assessment')) {
                    $trail[] = 'CLICK "Start My Assessment"';
                    $path = '/intake/eligibility/';
                    continue;
                }

                break;
            }

            $path = $response->getHeaderLine('Location');
        }

        // The welcome page is a link target rather than a routing answer, and
        // this is the walk that makes the difference load-bearing: it is
        // served rather than bounced by its own guard, and the button on it
        // reaches the questionnaire. Routing to it instead produced
        // `200 /intake/` on every hop, because form submission is not a
        // funnel step and the eligibility step forwards through the same
        // routing decision that had just sent the visitor back.
        self::assertSame([
            '200 /intake/',
            'CLICK "Start My Assessment"',
            '303 /intake/eligibility/',
            '200 /intake/medical/',
        ], $trail, 'the welcome page must lead onward rather than back to itself');
    }

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
}

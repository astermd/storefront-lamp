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
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Where a finished questionnaire hands a visitor next when the journey cannot
 * be loaded at all.
 *
 * The fail-forward itself is a ruling and is not what is under test: a router
 * that reads a missing journey as "nothing is finished" is right for the step
 * guard, which must fail closed, and wrong immediately after a submit. What is
 * under test is that the fail-forward **terminates**. It sends a prescription
 * buyer to `/checkout/`, whose guard sees no journey and a prescription line
 * and sends them back to the form they just filled in — so for the whole
 * duration of an EMR session outage the answer to "press continue" was the
 * same two URLs, with no error, no message and no way out but abandoning the
 * cart.
 *
 * The walk below is deliberately an honest one: follow every redirect the way
 * a browser does, fill the form in, press continue, repeat. Nothing here
 * inspects a private method — the loop is either there in the responses or it
 * is not.
 */
final class IntakeOutageOnwardTest extends TestCase
{
    use TempDatabase;

    /** A journey the visitor already holds a cookie for, so a later outage can be one they carry a session into. */
    private const string RESUMED_SESSION = 'b2f4a7c9-1d38-4e05-9a62-8c0f3b71d4e6';

    private string $cacheDir;

    private CapturedLog $log;

    /** The visitor's cookie jar, so a session that is minted is a session the next request carries. @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->log = new CapturedLog();
        $this->cacheDir = sys_get_temp_dir() . '/intake-outage-' . bin2hex(random_bytes(6));
        $this->registerTempDirForCleanup($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAPrescriptionBuyerIsNotAskedToSubmitTheSameFormForever(): void
    {
        $app = $this->appWithNoSessionService();
        $this->cartHoldsAPrescription();

        $trail = $this->walk($app, rounds: 4);

        self::assertNotSame([], $this->log->eventsNamed('intake.completion_unknown'), 'the fail-forward really was taken');
        self::assertContains('303 POST /intake/submit/ -> /checkout/', $trail, 'and the ruling still stands for the first try');
        self::assertContains('QUESTIONNAIRE UNAVAILABLE', $trail, 'but the loop ends in a page that says so');
        self::assertLessThan(4, self::roundsWalked($trail), 'and it ends without the buyer having to find that out');
    }

    public function testTheOutageIsStatedToTheOperatorAsALoopAndNotOnlyAsAMissingJourney(): void
    {
        // `intake.completion_unknown` is one visitor failing forward once and
        // is a warning. A visitor being handed back the same form is a
        // storefront that has stopped working for every prescription buyer,
        // and it has to be legible as that rather than as a run of the first.
        $app = $this->appWithNoSessionService();
        $this->cartHoldsAPrescription();

        $this->walk($app, rounds: 4);

        $looping = $this->log->eventsNamed('intake.completion_looping');
        self::assertCount(1, $looping, 'said once, when the loop is proven, and not on every hop');
        self::assertSame('error', $looping[0]['level'] ?? null);
    }

    public function testTheExitIsNotArmedByAnOutageTheVisitorHasAlreadyComeThrough(): void
    {
        // The absence a counter invites: a visitor who failed forward once,
        // recovered, and met a second unrelated outage an hour later must get
        // the same first fail-forward the ruling grants everybody, not the
        // exhausted state left over from the first.
        $app = $this->appWithNoSessionService();
        $this->cartHoldsAPrescription();
        $this->walk($app, rounds: 4);

        // The EMR comes back. The next completed form routes normally, and
        // that is what clears the tally.
        $recovered = $this->walk($this->appWithASessionService(), rounds: 2);
        self::assertContains('REACHED CHECKOUT', $recovered, 'the recovery this case turns on really happened');

        $trail = $this->walk($this->appWithNoSessionService(), rounds: 1);

        self::assertContains('303 POST /intake/submit/ -> /checkout/', $trail, 'the ruling is granted again');
        self::assertNotContains('QUESTIONNAIRE UNAVAILABLE', $trail, 'and not spent on the first try');
    }

    public function testTheTallyIsForgottenByAJourneyThatLoadsOnAPageAndNotOnlyByOneThatLoadsOnASubmit(): void
    {
        // The case above recovers by completing another form, which is the one
        // request that reaches `onward()` — and `onward()` was the only place
        // the tally was ever cleared. That is not how a visitor comes through a
        // one-request blip. They land on the page the fail-forward sent them
        // to, or come back an hour later and open the questionnaire, and
        // neither completes anything. If those clear nothing then the allowance
        // is spent per browser session rather than per outage, and the *first*
        // attempt of a second, unrelated blip gets the dead-end page the ruling
        // grants to nobody's first attempt.
        //
        // The outage here is a database that cannot read `sessions` rather than
        // an EMR that cannot mint one, and the difference is load-bearing: a
        // visitor who has already been given a session has a cookie, so this
        // is the shape in which a *later* blip actually reaches somebody.
        $pdo = $this->tempPdo();
        $this->journeyExistsFor($pdo);
        $this->cartHoldsAPrescription();
        $_SESSION['_csrf'] = 'intake-floor-token';
        $this->cookies['amd_session'] = self::RESUMED_SESSION;

        $healthy = $this->app(self::sessionServiceFor(self::RESUMED_SESSION), $pdo);
        $outage = $this->app(self::sessionServiceFor(self::RESUMED_SESSION), self::unreadableSessions($pdo));

        self::assertSame(303, $this->submit($outage)->getStatusCode(), 'blip one: the ruled fail-forward');
        self::assertStringContainsString(
            'load your questionnaire right now',
            (string) $this->submit($outage)->getBody(),
            'and the floor under it, so the allowance really is spent',
        );

        // The blip is over and the visitor opens the questionnaire again. No
        // form is completed, so nothing reaches `onward()`.
        $recovered = $this->handle($healthy, $this->request('GET', '/intake/medical/'));
        self::assertSame(200, $recovered->getStatusCode(), 'the journey really does load again between the two blips');

        self::assertSame(
            303,
            $this->submit($outage)->getStatusCode(),
            "a later outage's first attempt got the dead-end page instead of the ruled fail-forward",
        );
    }

    // ---------------------------------------------------------------- the walk

    /**
     * Follow the redirects, fill the form in, press continue, repeat.
     *
     * @return list<string>
     */
    private function walk(App $app, int $rounds): array
    {
        $trail = [];

        for ($round = 0; $round < $rounds; ++$round) {
            $trail[] = 'ROUND';
            $path = '/checkout/';
            for ($hop = 0; $hop < 6; ++$hop) {
                $response = $this->handle($app, $this->request('GET', $path));
                $trail[] = $response->getStatusCode() . ' GET ' . $path;
                if ($response->getStatusCode() < 300 || $response->getStatusCode() >= 400) {
                    break;
                }
                $path = $response->getHeaderLine('Location');
            }

            if ($path === '/checkout/') {
                $trail[] = 'REACHED CHECKOUT';

                return $trail;
            }

            $submitted = $this->submit($app);
            $trail[] = $submitted->getStatusCode() . ' POST /intake/submit/ -> ' . $submitted->getHeaderLine('Location');

            if (str_contains((string) $submitted->getBody(), 'load your questionnaire right now')) {
                $trail[] = 'QUESTIONNAIRE UNAVAILABLE';

                return $trail;
            }
        }

        return $trail;
    }

    private function submit(App $app): ResponseInterface
    {
        $token = (string) ($_SESSION['_csrf'] ?? '');
        $answers = ['_csrf' => $token, 'step' => 'intake', 'q1' => 'yes'];
        $this->handle($app, $this->request('POST', '/intake/save/')->withParsedBody($answers + ['page' => '0']));

        return $this->handle($app, $this->request('POST', '/intake/submit/')->withParsedBody($answers));
    }

    /** One request, keeping whatever cookie came back — the loop is only real if the session is. */
    private function handle(App $app, \Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
    {
        $response = $app->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($value === '') {
                unset($this->cookies[$name]);

                continue;
            }

            $this->cookies[$name] = $value;
        }

        return $response;
    }

    /** @param list<string> $trail */
    private static function roundsWalked(array $trail): int
    {
        return count(array_filter($trail, static fn (string $line): bool => $line === 'ROUND'));
    }

    private function request(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams($this->cookies)
            ->withHeader('X-Forwarded-For', '198.51.100.77');
    }

    // ---------------------------------------------------------------- harness

    private function cartHoldsAPrescription(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => 'rx-a',
            'name' => 'Rx A',
            'kind' => 'rx',
            'emr_product_id' => null,
            'parent_slug' => null,
            'quantity' => 1,
            'unit_price_cents' => 5000,
            'variant_id' => 'rx-a-v1',
        ]]];
    }

    /** The outage: the EMR can neither mint nor read a session, so no journey is ever loaded. */
    private function appWithNoSessionService(): App
    {
        return $this->app(new class implements SessionGateway {
            /** @param array<string, mixed> $data */
            public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
            {
                throw new \RuntimeException('EMR unreachable');
            }

            /** @return array<string, mixed>|null */
            public function view(string $uuid): ?array
            {
                throw new \RuntimeException('EMR unreachable');
            }
        });
    }

    private function appWithASessionService(): App
    {
        return $this->app(new class implements SessionGateway {
            /** @param array<string, mixed> $data */
            public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
            {
                return '4c1b8f2a-9d3e-4a61-8c7b-2e5d8a1f6c40';
            }

            /** @return array<string, mixed>|null */
            public function view(string $uuid): ?array
            {
                return ['uuid' => $uuid, 'opportunity_id' => null, 'events' => []];
            }
        });
    }

    /** A session service that answers for one known journey, so an outage can be the database's alone. */
    private static function sessionServiceFor(string $uuid): SessionGateway
    {
        return new FakeSessionGateway(sessions: [$uuid => ['opportunity_id' => null, 'events' => []]]);
    }

    /**
     * A live connection to the same file whose `sessions` reads raise.
     *
     * The other outage in this file is an EMR that can neither mint a session
     * nor read one, which leaves the visitor with no cookie at all. This one
     * leaves everything else working, so a visitor who already has a session
     * carries it into the outage — which is the shape a *second* blip takes.
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

    private function journeyExistsFor(\PDO $pdo): void
    {
        (new SessionRepository(fn (): \PDO => $pdo))->insert(self::RESUMED_SESSION, [], null);
    }

    private function app(SessionGateway $sessions, ?\PDO $pdo = null): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $pdo ?? $this->tempPdo(),
            OperatorLog::class => $this->log->log,
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
            TeleformGateway::class => self::teleforms(),
            ProductCatalog::class => new FakeCatalog([
                'rx-a' => [
                    'slug' => 'rx-a',
                    'name' => 'Rx A',
                    'kind' => 'rx',
                    'teleform_id' => 'tf-med',
                    'variants' => [['id' => 'rx-a-v1', 'name' => 'Monthly', 'price_cents' => 5000]],
                ],
            ]),
            SessionGateway::class => $sessions,
        ]);
    }

    private static function teleforms(): TeleformGateway
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
                return [
                    'formId' => $metadata->id,
                    'formName' => 'FORM',
                    'pages' => [[
                        'pageId' => 'p1',
                        'title' => 'FORM',
                        'order' => 0,
                        'fields' => [['fieldId' => 'q1', 'name' => 'q1', 'type' => 'text', 'label' => 'Q', 'required' => true]],
                    ]],
                ];
            }
        };
    }
}

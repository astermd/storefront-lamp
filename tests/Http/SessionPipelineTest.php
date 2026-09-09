<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Database\ConnectionFactory;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Http\Controller\HomeController;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The whole pipeline with a fake EMR: the parts that only appear once the
 * middleware, the resolver and the repositories are wired together.
 */
final class SessionPipelineTest extends TestCase
{
    use TempDatabase;

    private ?\PDO $pdo = null;

    /** @param array<string, mixed> $overrides additional container overrides, merged over the PDO/gateway pair */
    private function app(FakeSessionGateway $gateway, array $overrides = []): App
    {
        $this->pdo = $this->tempPdo();
        $root = dirname(__DIR__, 2);

        return AppFactory::create($root, [
            \PDO::class => $this->pdo,
            SessionGateway::class => $gateway,
            ...$overrides,
        ]);
    }

    public function testALandingRequestMintsASessionAndSetsTheCookie(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        $response = $this->app($gateway)->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/?aff_id=4412'),
        );

        self::assertSame(200, $response->getStatusCode());
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('amd_session=sess-1234567890abcdef', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('Max-Age=2592000', $cookie);
        self::assertStringNotContainsString('Secure', $cookie);   // plain http request
        self::assertCount(1, $gateway->createCalls);
    }

    public function testTheCookieIsMarkedSecureBehindTlsTermination(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        $response = $this->app($gateway)->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/')->withHeader('X-Forwarded-Proto', 'https'),
        );

        self::assertStringContainsString('Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testJourneyStateAndAttributionArePersistedByTheSaveBackMiddleware(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        $app = $this->app($gateway);
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/?aff_id=4412&utm_source=partner'));

        $row = (new SessionRepository(fn (): \PDO => $this->pdo))->find('sess-1234567890abcdef');
        self::assertNotNull($row);
        self::assertSame('4412', $row['attribution']['params']['affiliate_id']);
        self::assertSame('partner', $row['attribution']['params']['utm_source']);
        self::assertTrue($row['journey_state']['reconciled']);
        self::assertSame(['session_created'], (new EventRepository(fn (): \PDO => $this->pdo))->namesFor('sess-1234567890abcdef'));

        // A second request over that cookie reuses the session rather than minting.
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/treatments/')
                ->withCookieParams(['amd_session' => 'sess-1234567890abcdef']),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertCount(1, $gateway->createCalls);
    }

    public function testTheHealthProbeNeverMintsASession(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        $response = $this->app($gateway)->handle((new ServerRequestFactory())->createServerRequest('GET', '/health/'));

        self::assertSame([], $gateway->createCalls);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAPostRequestNeverMintsASession(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        // No CSRF token: the 419 short-circuit must not have minted anything
        // on the way in. Asserting the status too is what proves this is the
        // CSRF rejection and not some other reason createCalls stayed empty.
        $response = $this->app($gateway)->handle((new ServerRequestFactory())->createServerRequest('POST', '/'));

        self::assertSame(419, $response->getStatusCode());
        self::assertSame([], $gateway->createCalls);
    }

    public function testTheDefaultTestEnvironmentBindsTheNullGatewaySoNoRequestTouchesTheEmr(): void
    {
        // Its own PDO, so the assertion can never depend on whatever the
        // developer's own storage/database/app.sqlite happens to hold.
        $app = AppFactory::create(dirname(__DIR__, 2), [\PDO::class => $this->tempPdo()]);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * The whole pipeline over a database with no tables: analytics session
     * creation is a must-degrade-silently dependency (`[20.2]`), and the local
     * writes backing it are part of that dependency, so the visitor must get
     * their page rather than a 500.
     */
    public function testATablelessDatabaseDegradesToNoSessionInsteadOfBreakingThePage(): void
    {
        $logFile = sys_get_temp_dir() . '/session-pipeline-log-' . bin2hex(random_bytes(6)) . '.log';
        $app = AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->unmigratedPdo(),
            SessionGateway::class => new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef'),
            OperatorLog::class => new OperatorLog($logFile),
        ]);

        try {
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('', $response->getHeaderLine('Set-Cookie'));
            self::assertStringContainsString('aria-label="AsterMD home"', (string) $response->getBody());
            self::assertStringContainsString('session.resolve_failed', (string) file_get_contents($logFile));
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }

    /**
     * A journey whose row has vanished under it — a reset database, or the
     * retention sweep the operator notes describe — must leave an operator
     * line rather than losing the write in silence.
     */
    public function testALostJourneyWriteIsReportedToOperators(): void
    {
        $logFile = sys_get_temp_dir() . '/session-pipeline-log-' . bin2hex(random_bytes(6)) . '.log';
        $gateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        $app = $this->app($gateway, [
            OperatorLog::class => new OperatorLog($logFile),
            HomeController::class => function (Container $c) {
                return new class($c->get(JourneyStore::class), $this->pdo) {
                    public function __construct(private readonly JourneyStore $journey, private readonly \PDO $pdo)
                    {
                    }

                    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
                    {
                        $state = $this->journey->state();
                        if ($state !== null) {
                            $state->furthestStep = 'row-deleted-underneath';
                        }
                        $this->pdo->exec("DELETE FROM sessions WHERE session_uuid = 'sess-1234567890abcdef'");

                        return $response;
                    }
                };
            },
        ]);

        try {
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

            $log = (string) file_get_contents($logFile);

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('journey.save_failed', $log);
            self::assertStringContainsString('no longer exists', $log);
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }

    /**
     * A connection with the migrations deliberately not run. `TempDatabase`
     * always migrates, which is exactly what this test must not have.
     */
    private function unmigratedPdo(): \PDO
    {
        $dir = sys_get_temp_dir() . '/storefront-unmigrated-' . bin2hex(random_bytes(6));
        mkdir($dir . '/storage/database', 0775, true);
        $this->registerTempDirForCleanup($dir);

        return (new ConnectionFactory(
            ['driver' => 'sqlite', 'database' => 'storage/database/unmigrated.sqlite'],
            $dir,
        ))->create();
    }

    /**
     * Proves the `finally`-flush, not just that a crash produces a 500: the
     * route handler is replaced, through the override seam, with one that
     * mutates the journey state and then throws before rendering anything.
     * If the save-back only ran on the happy path, `furthest_step` would
     * still read null afterwards.
     */
    public function testJourneyStateSurvivesACrashingHandler(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: 'sess-1234567890abcdef');
        $response = $this->app($gateway, [
            HomeController::class => static function (Container $c) {
                $journey = $c->get(JourneyStore::class);

                return new class($journey) {
                    public function __construct(private readonly JourneyStore $journey)
                    {
                    }

                    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
                    {
                        $state = $this->journey->state();
                        if ($state !== null) {
                            $state->furthestStep = 'crashed-before-throw';
                        }

                        throw new \RuntimeException('deliberate crash for the save-back test');
                    }
                };
            },
        ])->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame(500, $response->getStatusCode());

        $row = (new SessionRepository(fn (): \PDO => $this->pdo))->find('sess-1234567890abcdef');
        self::assertNotNull($row);
        self::assertSame('crashed-before-throw', $row['journey_state']['furthest_step']);
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Database\ConnectionFactory;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Http\Controller\HealthController;
use AsterMD\Storefront\Observability\Reachability;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HealthTest extends TestCase
{
    use TempDatabase;

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                if (is_file((string) $file)) {
                    unlink((string) $file);
                }
            }

            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        $this->scratchDirs = [];
    }

    private function scratchDir(bool $create = true): string
    {
        $dir = sys_get_temp_dir() . '/health-' . bin2hex(random_bytes(6));
        $this->scratchDirs[] = $dir;

        if ($create) {
            mkdir($dir, 0o775, true);
        }

        return $dir;
    }

    /**
     * @param \Closure(): bool|null $emr
     * @param \Closure(): bool|null $provider
     */
    private function controller(
        ?\Closure $emr = null,
        ?\Closure $provider = null,
        ?DefinitionCache $definitions = null,
    ): HealthController {
        $root = dirname(__DIR__, 2);

        return new HealthController(
            Config::load($root . '/config', $_ENV),
            new ConnectionFactory(['driver' => 'sqlite', 'database' => 'health.sqlite'], $this->scratchDir()),
            $root,
            new Reachability(
                emr: $emr ?? static fn (): never => throw new \LogicException('the EMR must not be reached'),
                provider: $provider ?? static fn (): never => throw new \LogicException('the provider must not be reached'),
                cacheFile: $this->scratchDir() . '/reachability.json',
            ),
            $definitions ?? new DefinitionCache($this->scratchDir(), 86400),
        );
    }

    /** @return array<string, mixed> */
    private function body(HealthController $controller, string $query = ''): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health/' . $query);
        $response = $controller($request, new \Slim\Psr7\Response());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        $decoded['__status'] = $response->getStatusCode();

        return $decoded;
    }

    public function testHealthReportsChecksAndNoindex(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2), [\PDO::class => $this->tempPdo()]);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/health/'));
        $payload = json_decode((string) $response->getBody(), true);

        self::assertContains($response->getStatusCode(), [200, 503]);
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertArrayHasKey('checks', $payload);
        self::assertArrayHasKey('database', $payload['checks']);
        self::assertArrayHasKey('storage_writable', $payload['checks']);
    }

    public function testHealthReportsSessionConfigurationWithoutLeakingTheKey(): void
    {
        $previous = $_ENV['AMD_TRACKING_KEY'] ?? null;
        $_ENV['AMD_TRACKING_KEY'] = 'deadbeefdeadbeefdeadbeefdeadbeef';

        try {
            $app = AppFactory::create(dirname(__DIR__, 2), [\PDO::class => $this->tempPdo()]);
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/health/'));
            $body = (string) $response->getBody();
            $payload = json_decode($body, true);

            self::assertSame(200, $response->getStatusCode());
            self::assertFalse($payload['info']['analytics_sessions']);
            self::assertSame('configured', $payload['info']['attribution_key']);
            self::assertStringNotContainsString('deadbeefdeadbeefdeadbeefdeadbeef', $body);
            self::assertArrayNotHasKey('analytics_sessions', $payload['checks']);
        } finally {
            if ($previous === null) {
                unset($_ENV['AMD_TRACKING_KEY']);
            } else {
                $_ENV['AMD_TRACKING_KEY'] = $previous;
            }
        }
    }

    public function testHealthReportsAttributionKeyMissingWhenBlank(): void
    {
        $previous = $_ENV['AMD_TRACKING_KEY'] ?? null;
        // Seeded as an empty string rather than unset: Dotenv::safeLoad() only
        // fills in a key that is absent from $_ENV, so on a machine whose real
        // .env already configures AMD_TRACKING_KEY, merely unsetting it here
        // would not be enough to force the 'missing' branch — Dotenv would put
        // the real value straight back. An explicit blank value is what every
        // machine ends up with either way.
        $_ENV['AMD_TRACKING_KEY'] = '';

        try {
            $app = AppFactory::create(dirname(__DIR__, 2), [\PDO::class => $this->tempPdo()]);
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/health/'));
            $payload = json_decode((string) $response->getBody(), true);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('missing', $payload['info']['attribution_key']);
        } finally {
            if ($previous === null) {
                unset($_ENV['AMD_TRACKING_KEY']);
            } else {
                $_ENV['AMD_TRACKING_KEY'] = $previous;
            }
        }
    }

    /**
     * The endpoint is polled by load balancers. A probe that reached two
     * external systems on every hit would be a self-inflicted outage.
     */
    public function testAnOrdinaryProbeMakesNoOutboundCall(): void
    {
        $body = $this->body($this->controller());

        self::assertSame('unknown', $body['info']['emr']['status']);
        self::assertSame('unknown', $body['info']['provider']['status']);
    }

    public function testAnOptInProbeReportsReachabilityForBothDependencies(): void
    {
        $body = $this->body(
            $this->controller(emr: static fn (): bool => true, provider: static fn (): bool => false),
            '?probe=1',
        );

        self::assertSame('ok', $body['info']['emr']['status']);
        self::assertSame('unreachable', $body['info']['provider']['status']);
    }

    /**
     * `[20.1]`: an unreachable EMR is a storefront that degrades, not one that
     * should be pulled out of rotation — so reachability lives beside the
     * verdict rather than inside it.
     */
    public function testAnUnreachableDependencyNeverFlipsTheVerdict(): void
    {
        $body = $this->body(
            $this->controller(emr: static fn (): bool => false, provider: static fn (): bool => false),
            '?probe=1',
        );

        self::assertSame(200, $body['__status']);
        self::assertSame('ok', $body['status']);
        self::assertArrayNotHasKey('emr', $body['checks']);
        self::assertArrayNotHasKey('provider', $body['checks']);
    }

    public function testHealthReportsTheFormDefinitionCacheState(): void
    {
        $dir = $this->scratchDir();
        $cache = new DefinitionCache($dir, 86400);
        $cache->put('acct/org/form_v_1.json', ['pages' => []]);

        $body = $this->body($this->controller(definitions: $cache));

        self::assertTrue($body['checks']['form_definition_cache']);
        self::assertSame(1, $body['info']['form_definitions_cached']);
    }

    public function testAFormDefinitionCacheThatCannotBeWrittenDegradesTheEndpoint(): void
    {
        $dir = $this->scratchDir();
        chmod($dir, 0o500);

        try {
            $body = $this->body($this->controller(definitions: new DefinitionCache($dir . '/teleforms', 86400)));

            self::assertFalse($body['checks']['form_definition_cache']);
            self::assertSame(503, $body['__status']);
            self::assertSame('degraded', $body['status']);
        } finally {
            chmod($dir, 0o775);
        }
    }

    /** `[23.10]`: an installation running in degraded URL mode says so. */
    public function testHealthReportsWhichUrlModeTheInstallationIsRunningIn(): void
    {
        self::assertSame('rewritten', $this->body($this->controller())['info']['url_mode']);
    }

    public function testAFrontControllerVisibleInThePathIsReportedAsDegraded(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/index.php/health/', ['SCRIPT_NAME' => '/index.php']);
        $response = ($this->controller())($request, new \Slim\Psr7\Response());
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame('degraded', $payload['info']['url_mode']);
        self::assertSame(200, $response->getStatusCode());
    }
}

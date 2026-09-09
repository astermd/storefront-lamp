<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Console\ValidateCommand;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ValidateCommandTest extends TestCase
{
    private string $configDir;

    private string $rootDir;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->configDir = sys_get_temp_dir() . '/validate-command-' . uniqid();
        $this->rootDir = sys_get_temp_dir() . '/validate-command-root-' . uniqid();
        mkdir($this->configDir);
        mkdir($this->rootDir);

        file_put_contents(
            $this->configDir . '/app.php',
            // `url` is not decoration: `config:validate` refuses a deployment whose
            // canonical links, sitemap and robots.txt would name no host, so a
            // fixture without it is not a deployment these cases could pass.
            "<?php return ['url' => 'https://storefront.example', 'emr' => ['base_host' => 'sales.example.test', 'channel_id' => 'channel-123']];",
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        self::removeDir($this->configDir);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? self::removeDir($file) : @unlink($file);
        }

        @rmdir($dir);
    }

    /** @param array<string, mixed> $products */
    private function writeCatalog(array $products, ?array $overrides = null): void
    {
        file_put_contents(
            $this->configDir . '/products.generated.php',
            '<?php return ' . var_export([
                'channel' => ['id' => 'channel-123', 'name' => 'Flow 1', 'currency' => 'USD'],
                'products' => $products,
            ], true) . ';',
        );

        if ($overrides !== null) {
            file_put_contents(
                $this->configDir . '/products.overrides.php',
                '<?php return ' . var_export($overrides, true) . ';',
            );
        }
    }

    private function command(?FakeEmrHttpClient $httpClient = null): ValidateCommand
    {
        $config = Config::load($this->configDir);

        return new ValidateCommand(
            new CatalogProvider($config),
            new ClientFactory($config, $this->rootDir),
            $this->configDir,
            $httpClient,
        );
    }

    /**
     * A command that receives the configuration, so the deployment-wide passes
     * run.
     *
     * The plain {@see self::command()} deliberately does not: the structural
     * catalog cases predate those passes and have no deployment to validate,
     * so both skip when no configuration was supplied. A case about a
     * deployment-wide setting has to hand one over.
     */
    private function deploymentCommand(): ValidateCommand
    {
        $config = Config::load($this->configDir);

        return new ValidateCommand(
            new CatalogProvider($config),
            new ClientFactory($config, $this->rootDir),
            $this->configDir,
            null,
            $config,
        );
    }
    /** @param array<string, mixed> $payload */
    private function writeChannelGeneratedFile(array $payload): void
    {
        file_put_contents(
            $this->configDir . '/channel.generated.php',
            '<?php return ' . var_export($payload, true) . ';',
        );
    }

    /** A minimal, fully valid, single-clean-product catalog — used by the channel.generated.php tests so the only errors/warnings possible come from that file. */
    private function writeCleanCatalog(): void
    {
        $this->writeCatalog([
            'abc123-tadalafil' => [
                'slug' => 'abc123-tadalafil',
                'name' => 'Tadalafil',
                'kind' => 'rx',
                'emr_product_id' => 'abc123',
                'variants' => [
                    ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                ],
            ],
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>} status code and body for the token endpoint */
    private function tokenRoute(): array
    {
        return [200, [
            'success' => true,
            'data' => [
                'access_token' => 'test-token',
                'access_token_expiry' => '2099-01-01T00:00:00.000Z',
            ],
        ]];
    }

    // --- structural pass -------------------------------------------------

    public function testStructuralErrorExitsTwoAndPrintsErrorPrefixed(): void
    {
        $this->writeCatalog([
            'abc123-tadalafil' => [
                'slug' => 'abc123-tadalafil',
                'name' => '',
                'kind' => 'rx',
                'emr_product_id' => 'abc123',
                'variants' => [
                    ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                ],
            ],
        ]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: product abc123-tadalafil', $tester->getDisplay());
    }

    /**
     * A deployment whose URLs would name no host is refused.
     *
     * The failure this catches is entirely silent otherwise: root-relative
     * canonical links, sitemap locations and a `Sitemap:` line are all still
     * well-formed and still served, and no rendered page looks wrong. There is
     * no honest runtime repair either — deriving the host from the request
     * would make a canonical URL depend on who asked — so the deployment gate
     * is the only place it can be said.
     */
    public function testADeploymentWithNoApplicationUrlExitsTwo(): void
    {
        file_put_contents(
            $this->configDir . '/app.php',
            "<?php return ['emr' => ['base_host' => 'sales.example.test', 'channel_id' => 'channel-123']];",
        );
        $this->writeCatalog([
            'abc123-tadalafil' => [
                'slug' => 'abc123-tadalafil',
                'name' => 'Tadalafil',
                'kind' => 'rx',
                'emr_product_id' => 'abc123',
                'variants' => [
                    ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                ],
            ],
        ]);

        $tester = new CommandTester($this->deploymentCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: seo: app.url is not an absolute URL', $tester->getDisplay());
    }

    /** A rule the indexing policy cannot read leaves its path crawlable, so the deploy is refused. */
    public function testARobotsRuleWithNoPathToMatchExitsTwo(): void
    {
        file_put_contents(
            $this->configDir . '/app.php',
            "<?php return ['url' => 'https://storefront.example', 'seo' => ['robots_rules' => ['/checkout/*']], "
            . "'emr' => ['base_host' => 'sales.example.test', 'channel_id' => 'channel-123']];",
        );
        $this->writeCatalog([
            'abc123-tadalafil' => [
                'slug' => 'abc123-tadalafil',
                'name' => 'Tadalafil',
                'kind' => 'rx',
                'emr_product_id' => 'abc123',
                'variants' => [
                    ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                ],
            ],
        ]);

        $tester = new CommandTester($this->deploymentCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('has no path to match', $tester->getDisplay());
    }
    public function testWarningsOnlyExitsZeroAndPrintsWarningPrefixed(): void
    {
        $this->writeCatalog([
            'abc123-tadalafil' => [
                'slug' => 'abc123-tadalafil',
                'name' => 'Tadalafil',
                'kind' => 'rx',
                'emr_product_id' => 'abc123',
                'variants' => [
                    ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => null],
                ],
            ],
        ]);

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('warning: product abc123-tadalafil', $tester->getDisplay());
        self::assertStringContainsString('provider identifiers', $tester->getDisplay());
    }

    public function testCleanCatalogExitsZeroAndPrintsCatalogValid(): void
    {
        $this->writeCleanCatalog();
        $this->writeChannelGeneratedFile([
            'channel' => ['id' => 'channel-123', 'name' => 'Flow 1'],
            'payment_processor' => ['provider_category' => 'vrio', 'name' => 'Vrio', 'config' => ['api_key' => 'x']],
        ]);

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Catalog valid.', $tester->getDisplay());
    }

    public function testIgnoredOverridesAreReportedAsWarnings(): void
    {
        $this->writeCatalog(
            [
                'abc123-tadalafil' => [
                    'slug' => 'abc123-tadalafil',
                    'name' => 'Tadalafil',
                    'kind' => 'rx',
                    'emr_product_id' => 'abc123',
                    'variants' => [
                        ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                    ],
                ],
            ],
            overrides: [
                'products' => [
                    // Matches no generated emr_product_id and isn't a full product definition.
                    'unknown-id' => ['price_cents' => 100],
                ],
            ],
        );

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('warning: override ignored: unknown-id', $tester->getDisplay());
    }

    // --- channel.generated.php -------------------------------------------

    public function testValidChannelGeneratedFileAddsNoNewErrorsOrWarnings(): void
    {
        $this->writeCleanCatalog();
        $this->writeChannelGeneratedFile([
            'channel' => ['id' => 'channel-123', 'name' => 'Flow 1'],
            'payment_processor' => ['provider_category' => 'vrio', 'name' => 'Vrio', 'config' => ['api_key' => 'x']],
        ]);

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Catalog valid.', $tester->getDisplay());
    }

    public function testChannelGeneratedFileMissingProviderCategoryExitsTwo(): void
    {
        $this->writeCleanCatalog();
        $this->writeChannelGeneratedFile([
            'channel' => ['id' => 'channel-123', 'name' => 'Flow 1'],
            'payment_processor' => ['provider_category' => '', 'name' => 'Vrio', 'config' => ['api_key' => 'x']],
        ]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString(
            'ERROR: channel.generated.php: payment_processor.provider_category is missing or blank',
            $tester->getDisplay(),
        );
    }

    public function testChannelGeneratedFileMalformedIsError(): void
    {
        $this->writeCleanCatalog();
        file_put_contents($this->configDir . '/channel.generated.php', '<?php return "not-an-array";');

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: channel.generated.php is malformed', $tester->getDisplay());
    }

    public function testChannelGeneratedFileMissingPaymentProcessorConfigIsWarningOnly(): void
    {
        $this->writeCleanCatalog();
        $this->writeChannelGeneratedFile([
            'channel' => ['id' => 'channel-123', 'name' => 'Flow 1'],
            'payment_processor' => ['provider_category' => 'vrio', 'name' => 'Vrio'],
        ]);

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString(
            'warning: channel.generated.php: payment_processor.config is missing',
            $tester->getDisplay(),
        );
    }

    public function testChannelGeneratedFileNotFoundIsWarningAndExitsZero(): void
    {
        $this->writeCleanCatalog();

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString(
            'warning: channel.generated.php not found — run bin/console theme:sync --apply',
            $tester->getDisplay(),
        );
    }

    // --- --live ------------------------------------------------------------

    public function testLiveWithFake404ForOneProductReportsErrorAndExitsTwo(): void
    {
        $this->writeCatalog([
            'abc123-tadalafil' => [
                'slug' => 'abc123-tadalafil',
                'name' => 'Tadalafil',
                'kind' => 'rx',
                'emr_product_id' => 'abc123',
                'variants' => [
                    ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                ],
            ],
            'def456-semaglutide' => [
                'slug' => 'def456-semaglutide',
                'name' => 'Semaglutide',
                'kind' => 'rx',
                'emr_product_id' => 'def456',
                'variants' => [
                    ['id' => 'v2', 'name' => 'Vial', 'price_cents' => 4600, 'provider' => ['offer_id' => 'o2', 'product_id' => 'p2']],
                ],
            ],
        ]);

        $fake = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => $this->tokenRoute(),
            '/v1/sales/products/view/abc123' => [404, ['success' => false, 'message' => 'Not found']],
            '/v1/sales/products/view/def456' => [200, ['success' => true, 'data' => ['_id' => 'def456']]],
        ]);

        $tester = new CommandTester($this->command($fake));

        self::assertSame(2, $tester->execute(['--live' => true]));
        self::assertStringContainsString('ERROR: EMR cannot resolve product abc123-tadalafil (abc123)', $tester->getDisplay());
        self::assertStringNotContainsString('def456-semaglutide', $tester->getDisplay());
    }

    public function testLiveWithAllProductsResolvingExitsZero(): void
    {
        $this->writeCatalog([
            'abc123-tadalafil' => [
                'slug' => 'abc123-tadalafil',
                'name' => 'Tadalafil',
                'kind' => 'rx',
                'emr_product_id' => 'abc123',
                'variants' => [
                    ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                ],
            ],
            // lab kind is exempt from --live resolution; no route is configured for
            // its id below, so if the command called view() on it anyway, the fake
            // client would throw and this test would fail with exit 2.
            'lab789-panel' => [
                'slug' => 'lab789-panel',
                'name' => 'At-Home Panel',
                'kind' => 'lab',
                'emr_product_id' => 'lab789',
                'variants' => [
                    ['id' => 'v3', 'name' => 'Panel', 'price_cents' => 8900, 'provider' => ['offer_id' => 'o3', 'product_id' => 'p3']],
                ],
            ],
        ]);
        $this->writeChannelGeneratedFile([
            'channel' => ['id' => 'channel-123', 'name' => 'Flow 1'],
            'payment_processor' => ['provider_category' => 'vrio', 'name' => 'Vrio', 'config' => ['api_key' => 'x']],
        ]);

        $fake = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => $this->tokenRoute(),
            '/v1/sales/products/view/abc123' => [200, ['success' => true, 'data' => ['_id' => 'abc123']]],
        ]);

        $tester = new CommandTester($this->command($fake));

        self::assertSame(0, $tester->execute(['--live' => true]));
        self::assertStringContainsString('Catalog valid.', $tester->getDisplay());
    }

    /**
     * The command with a `Config` behind it, which is what turns on every pass
     * that reads configuration rather than the catalog.
     *
     * The default {@see self::command()} deliberately builds without one — the
     * tests written before there was a payment provider to check pass no
     * config and only want the structural pass — so the deployment-time passes
     * need their own constructor.
     */
    private function configuredCommand(): ValidateCommand
    {
        $config = Config::load($this->configDir);

        return new ValidateCommand(
            new CatalogProvider($config),
            new ClientFactory($config, $this->rootDir),
            $this->configDir,
            null,
            $config,
        );
    }

    /** @param array<string, mixed> $verification */
    private function writeVerification(array $verification): void
    {
        file_put_contents(
            $this->configDir . '/verification.php',
            '<?php return ' . var_export($verification, true) . ';',
        );
    }

    /** @param array<string, mixed> $abandonment */
    private function writeAbandonment(array $abandonment): void
    {
        file_put_contents(
            $this->configDir . '/abandonment.php',
            '<?php return ' . var_export($abandonment, true) . ';',
        );
    }

    /** The shipped file, so a case can vary one key without restating a deployment. */
    private static function shipped(string $name): array
    {
        /** @var array<string, mixed> $values */
        $values = require dirname(__DIR__, 2) . '/config/' . $name . '.php';

        return $values;
    }

    /** A clean catalog and a synced channel, so the only findings possible come from the file under test. */
    private function quietDeployment(): void
    {
        $this->writeCleanCatalog();
        $this->writeChannelGeneratedFile([
            'channel' => ['id' => 'channel-123', 'name' => 'Flow 1'],
            'payment_processor' => ['provider_category' => 'vrio', 'name' => 'Vrio', 'config' => ['api_key' => 'x']],
        ]);
    }

    // --- config/verification.php -----------------------------------------

    public function testTheShippedVerificationAndAbandonmentFilesValidate(): void
    {
        $this->quietDeployment();
        $this->writeVerification(self::shipped('verification'));
        $this->writeAbandonment(self::shipped('abandonment'));
        copy(dirname(__DIR__, 2) . '/config/consent.php', $this->configDir . '/consent.php');
        copy(dirname(__DIR__, 2) . '/config/routes.php', $this->configDir . '/routes.php');

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Catalog valid.', $tester->getDisplay());
    }

    public function testAnUnimplementedPlacementIsRefusedRatherThanSilentlyDowngraded(): void
    {
        // `async` is declared in `config/verification.php` and implemented
        // nowhere, and the file itself calls selecting it "a configuration
        // error rather than a silent downgrade". Nothing enforced that: an
        // unknown placement makes the step vanish from the funnel while the
        // gate it was meant to guard reports itself satisfied, so a deployment
        // that asked for a blocking identity check gets an open door and no
        // signal at all.
        $this->quietDeployment();
        $this->writeVerification(['placement' => 'async'] + self::shipped('verification'));

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: verification: placement "async"', $tester->getDisplay());
    }

    public function testAMisspeltPlacementIsRefusedForTheSameReason(): void
    {
        $this->quietDeployment();
        $this->writeVerification(['placement' => 'intkae'] + self::shipped('verification'));

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: verification: placement "intkae"', $tester->getDisplay());
    }

    public function testEnabledAndBlockingMustBeBooleansRatherThanTruthyStrings(): void
    {
        // `[22.16]` keeps these two settings separate, and both are read with
        // `=== true`. A string "false" is truthy to a human reader and false
        // to the code, which is the shape of misconfiguration that looks
        // correct in review.
        $this->quietDeployment();
        $this->writeVerification(['enabled' => 'true', 'blocking' => 1] + self::shipped('verification'));

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: verification: enabled must be a boolean', $tester->getDisplay());
        self::assertStringContainsString('ERROR: verification: blocking must be a boolean', $tester->getDisplay());
    }

    public function testAnEnabledStepWithNoChecksIsRefused(): void
    {
        $this->quietDeployment();
        $this->writeVerification(['enabled' => true, 'checks' => []] + self::shipped('verification'));

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: verification: checks is empty', $tester->getDisplay());
    }

    public function testACheckSlugTheProviderDoesNotHaveIsRefused(): void
    {
        // The sequence drops a check whose slug the gateway cannot map, so a
        // typo here is a rung of the escalation that silently never runs.
        $this->quietDeployment();
        $this->writeVerification(['checks' => [
            ['slug' => 'dob_verifiy', 'required' => ['firstName'], 'narrowing' => []],
        ]] + self::shipped('verification'));

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: verification: checks[0] names an unknown check "dob_verifiy"', $tester->getDisplay());
        self::assertStringContainsString('crosscheck, dob_verify, ssn_verify', $tester->getDisplay());
    }

    public function testACheckMissingItsRequiredOrNarrowingListIsRefused(): void
    {
        // Recorded 2026-08-25: called with its required fields alone,
        // `crosscheck` returns `address_invalid` and `phone_invalid` — it
        // penalises fields that were never sent. A check with no `narrowing`
        // is therefore a check configured to fail, and a missing `required` is
        // an `ApiException` 400 rather than a verdict.
        $this->quietDeployment();
        $this->writeVerification(['checks' => [
            ['slug' => 'crosscheck', 'required' => []],
        ]] + self::shipped('verification'));

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: verification: checks[0] required must be a non-empty list', $tester->getDisplay());
        self::assertStringContainsString('ERROR: verification: checks[0] narrowing must be a list', $tester->getDisplay());
    }

    // --- config/abandonment.php ------------------------------------------

    public function testAbandonmentIntervalsMustBePositive(): void
    {
        // Zero is the value an unset environment variable casts to, so it is
        // the misconfiguration that arrives by accident: a zero idle interval
        // reports every live journey as abandoned, and a zero lookback or
        // limit reports none at all.
        $this->quietDeployment();
        $this->writeAbandonment([
            'idle_seconds' => 0,
            'lookback_seconds' => -1,
            'limit' => 0,
            'marketing_consent_key' => 'marketing',
        ]);
        copy(dirname(__DIR__, 2) . '/config/consent.php', $this->configDir . '/consent.php');

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        foreach (['idle_seconds', 'lookback_seconds', 'limit'] as $key) {
            self::assertStringContainsString(
                'ERROR: abandonment: ' . $key . ' must be a positive integer',
                $tester->getDisplay(),
            );
        }
    }

    public function testTheMarketingConsentKeyMustNameAConsentThatExists(): void
    {
        // `[21.11b]` requires a signal for somebody who declined marketing to
        // be marked so the platform can suppress it. A key naming nothing
        // reports `not_asked` for every journey, including the ones that
        // declined — the suppression signal silently stops existing.
        $this->quietDeployment();
        $this->writeAbandonment(['marketing_consent_key' => 'promotions'] + self::shipped('abandonment'));
        copy(dirname(__DIR__, 2) . '/config/consent.php', $this->configDir . '/consent.php');

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString(
            'ERROR: abandonment: marketing_consent_key "promotions" names no consent in config/consent.php',
            $tester->getDisplay(),
        );
    }

    public function testNeitherFileIsInventedWhenTheDeploymentDoesNotShipIt(): void
    {
        // A config directory without these files is a caller that wants the
        // structural pass and nothing else — every test above this block is
        // one — and an absent verification file already means "no step" to
        // every reader of it. Reporting its absence would fail those callers
        // for a fault none of them has.
        $this->quietDeployment();

        $tester = new CommandTester($this->configuredCommand());

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('verification:', $tester->getDisplay());
        self::assertStringNotContainsString('abandonment:', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Console\ValidateCommand;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Payment\RedeclaredAdapter;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `config:validate`'s payment pass ([2.21]).
 *
 * The adapter is the real Vrio one over a transport that is never asked for
 * anything: the pass reads `capabilities()` and nothing else, and building it
 * over the recorded credentials is what makes "does the channel carry every
 * key this adapter declares" a real question rather than a fixture answering
 * itself.
 */
final class ValidateCommandPaymentTest extends TestCase
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

        $this->configDir = sys_get_temp_dir() . '/validate-payment-' . bin2hex(random_bytes(6));
        $this->rootDir = sys_get_temp_dir() . '/validate-payment-root-' . bin2hex(random_bytes(6));
        mkdir($this->configDir);
        mkdir($this->rootDir);

        file_put_contents(
            $this->configDir . '/app.php',
            // `url` is not decoration: `config:validate` refuses a deployment whose
            // canonical links, sitemap and robots.txt would name no host, so a
            // fixture without it is not a deployment these cases could pass.
            "<?php return ['url' => 'https://storefront.example', 'emr' => ['base_host' => 'sales.example.test', 'channel_id' => 'channel-123']];",
        );
        $this->writeCatalog();
        $this->writeChannel();
        $this->writePayment();
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

        foreach ([$this->configDir, $this->rootDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testAFullyConfiguredPaymentSetupPasses(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('ERROR', $tester->getDisplay());
    }

    public function testAnUnresolvedAdapterIsAnError(): void
    {
        // A storefront that cannot resolve a provider cannot take money, which
        // is not a warning.
        $tester = new CommandTester($this->command(static fn (): PaymentAdapter => new NullPaymentAdapter()));

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: payment: no adapter resolved', $tester->getDisplay());
    }

    public function testAConfigKeyTheAdapterDeclaresAsRequiredIsAnErrorWhenMissing(): void
    {
        $this->writeChannel(['api_endpoint' => 'https://api.vrio.app', 'api_key' => 'jwt', 'campaign_id' => '147']);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('missing connection_id', $tester->getDisplay());
    }

    public function testAMissingShippingProfileIdIsAnError(): void
    {
        // The provider refuses any order carrying a payment action without one
        // ("Shipping Method Required"), so this fails every checkout.
        $this->writePayment(shippingProfileId: null);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('payment.shipping_profile_id is not set', $tester->getDisplay());
    }

    public function testABumpNamingAProductThatIsNotInTheCatalogIsAnError(): void
    {
        $this->writeCrossSells(['tirzepatide' => [['key' => 'kit', 'slug' => 'anti-nausea-kit']]]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('names anti-nausea-kit, which is not in the catalog', $tester->getDisplay());
    }

    public function testABumpWithNoProviderMappingIsAnError(): void
    {
        // `[27.9]`: an offer the provider has never heard of could never be
        // charged for, and discovering that when a buyer accepts one is late.
        $this->writeCatalog(withUnmappedExtra: true);
        $this->writeCrossSells(['tirzepatide' => [['key' => 'organizer', 'slug' => 'pill-organizer']]]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('order bump pill-organizer has no provider mapping', $tester->getDisplay());
    }

    public function testUnmappedCatalogVariantsAreAWarningWithACountRatherThanAnError(): void
    {
        // The recorded live channel legitimately carries a product with no
        // variants and therefore no mappings.
        $this->writeCatalog(withUnmappedExtra: true);

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('warning: payment: 1 catalog variants have no provider mapping', $tester->getDisplay());
    }

    public function testAFreshInstallThatHasNotSyncedYetIsNotFailedByTheAdapterChecks(): void
    {
        // The missing file is already reported as the warning it is, and one
        // fact must not become three findings — a clone whose first command
        // was `config:validate` would otherwise look broken.
        @unlink($this->configDir . '/channel.generated.php');

        $tester = new CommandTester($this->command(static fn (): PaymentAdapter => new NullPaymentAdapter()));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('channel.generated.php not found', $tester->getDisplay());
        self::assertStringNotContainsString('no adapter resolved', $tester->getDisplay());
    }

    public function testTheStructuralPassStillRunsWithNoPaymentArgumentsAtAll(): void
    {
        // Every caller written before there was a provider to check keeps
        // working, and the payment pass simply does not run.
        $config = Config::load($this->configDir);
        $tester = new CommandTester(new ValidateCommand(
            new CatalogProvider($config),
            new ClientFactory($config, $this->rootDir),
            $this->configDir,
        ));

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('payment:', $tester->getDisplay());
    }

    public function testAnUpsellNamingAProductTheCatalogDoesNotHaveIsAnError(): void
    {
        // `[16.17]`: upsell definitions are validated with the catalog's own
        // rigour, so an offer nothing could resolve is caught here rather than
        // becoming a page a buyer reaches and an offer that silently vanishes.
        $this->writeUpsells(['wellness' => ['slug' => 'wellness-pack', 'offer_after' => ['tirzepatide']]]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('upsell wellness names wellness-pack, which is not in the catalog', $tester->getDisplay());
    }

    public function testAnUpsellWhoseOfferAfterNamesAnUnknownSlugIsAnError(): void
    {
        // A trigger no product answers to is an upsell nothing can ever earn,
        // which reads at runtime as an empty queue rather than as a fault.
        $this->writeUpsells(['again' => ['slug' => 'tirzepatide', 'offer_after' => ['semaglutide']]]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('upsell again is offered after semaglutide, which is not in the catalog', $tester->getDisplay());
    }

    public function testAnUpsellWhoseVariantHasNoProviderMappingIsAnError(): void
    {
        // `[16.17]`, and the same reasoning as an unmappable bump: an offer
        // the provider has never heard of could never be charged for, and an
        // upsell is charged *after* the buyer has already paid.
        $this->writeCatalog(withUnmappedExtra: true);
        $this->writeUpsells(['organizer' => ['slug' => 'pill-organizer', 'offer_after' => ['tirzepatide']]]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('upsell organizer has no provider mapping', $tester->getDisplay());
    }

    public function testAnUpsellWithNoOfferAfterEntriesIsAnError(): void
    {
        // Configured, mappable, and unreachable: nothing earns it, so it is
        // dead configuration that looks live.
        $this->writeUpsells(['orphan' => ['slug' => 'tirzepatide', 'offer_after' => []]]);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('upsell orphan names nothing in offer_after', $tester->getDisplay());
    }

    public function testAWellFormedUpsellPasses(): void
    {
        $this->writeUpsells(['again' => ['slug' => 'tirzepatide', 'offer_after' => ['tirzepatide']]]);

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('upsell', $tester->getDisplay());
    }

    public function testThePciPostureIsReportedSoAnOperatorKnowsTheScopeBeforeGoingLive(): void
    {
        // `[15.15]` names the configuration validator specifically, and the
        // run that most needs the posture is the clean one -- so it cannot be
        // a warning, which would both suppress "Catalog valid." and dress an
        // informational fact as something to act on.
        $adapter = $this->vrioAdapter();
        $tester = new CommandTester($this->command(static fn (): PaymentAdapter => $adapter));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString(
            'PCI posture — ' . $adapter->capabilities()->pciPosture,
            $tester->getDisplay(),
        );
        self::assertStringContainsString('Catalog valid.', $tester->getDisplay());
    }

    public function testARawCarryForwardAdapterWarnsThatItCannotServeUpsells(): void
    {
        // The honest statement of a real limit: the card is collected in one
        // request and is not held past it, so an accepted upsell under such an
        // adapter has nothing to charge. Holding the card instead would put
        // the deployment in full PCI scope for an optional add-on.
        $this->writeUpsells(['again' => ['slug' => 'tirzepatide', 'offer_after' => ['tirzepatide']]]);

        $tester = new CommandTester($this->command($this->redeclaredAs(AdapterCapabilities::STRATEGY_RAW_CARRY_FORWARD)));

        self::assertSame(0, $tester->execute([]), 'a limit an operator can accept is a warning, not a failure');
        self::assertStringContainsString('warning: payment: ', $tester->getDisplay());
        self::assertMatchesRegularExpression('/warning: payment: .*raw.*/i', $tester->getDisplay());
        self::assertMatchesRegularExpression('/warning: payment: .*upsell.*/i', $tester->getDisplay());
    }

    public function testARawCarryForwardAdapterWithNoUpsellsConfiguredIsNotWarnedAbout(): void
    {
        // Nothing is lost when nothing is offered, and a warning nobody can
        // act on trains operators to ignore warnings.
        $tester = new CommandTester($this->command($this->redeclaredAs(AdapterCapabilities::STRATEGY_RAW_CARRY_FORWARD)));

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('warning: payment: ', $tester->getDisplay());
    }

    public function testAReferenceOrderAdapterServingUpsellsIsNotWarnedAbout(): void
    {
        $this->writeUpsells(['again' => ['slug' => 'tirzepatide', 'offer_after' => ['tirzepatide']]]);

        $tester = new CommandTester($this->command($this->redeclaredAs(AdapterCapabilities::STRATEGY_ORDER_REFERENCE)));

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('warning: payment: ', $tester->getDisplay());
    }

    public function testAConsentLinkingToAPathTheApplicationServesPasses(): void
    {
        $this->writeRoutes(['/terms/']);
        $this->writeConsent('/terms/');

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('consent:', $tester->getDisplay());
    }

    public function testAConsentLinkingSomewhereNothingServesIsAnError(): void
    {
        // `[26.9]`: the control still renders and still records agreement, so
        // nothing at runtime notices that the document behind it answers 404.
        $this->writeRoutes(['/terms/']);
        $this->writeConsent('/telehealth-consent/');

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString(
            'consent: terms links to /telehealth-consent/, which this application does not serve',
            $tester->getDisplay(),
        );
    }

    public function testAnOffsiteConsentLinkIsNotThisApplicationsToServe(): void
    {
        $this->writeRoutes(['/terms/']);
        $this->writeConsent('https://legal.example.test/terms');

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    // ---------------------------------------------------------------- fixtures

    /** @param list<string> $paths */
    private function writeRoutes(array $paths): void
    {
        $lines = '';
        foreach ($paths as $path) {
            $lines .= sprintf("    \$app->get(%s, 'handler')->setName('x');\n", var_export($path, true));
        }

        file_put_contents(
            $this->configDir . '/routes.php',
            "<?php return static function (\$app): void {\n" . $lines . "};\n",
        );
    }

    private function writeConsent(string $link): void
    {
        file_put_contents(
            $this->configDir . '/consent.php',
            "<?php return " . var_export([
                'consents' => [
                    ['key' => 'terms', 'label' => 'Terms', 'html' => 'I agree.', 'blocking' => true, 'links' => [$link]],
                ],
            ], true) . ";\n",
        );
    }

    /** @param (\Closure(): PaymentAdapter)|null $adapter */
    private function command(?\Closure $adapter = null): ValidateCommand
    {
        $config = Config::load($this->configDir);

        return new ValidateCommand(
            new CatalogProvider($config),
            new ClientFactory($config, $this->rootDir),
            $this->configDir,
            null,
            $config,
            $adapter ?? fn (): PaymentAdapter => $this->vrioAdapter(),
        );
    }

    private function vrioAdapter(): VrioAdapter
    {
        return new VrioAdapter(
            new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
            new VrioApiFactory(new FakeVrioTransport()),
            (new CapturedLog())->log,
            shippingProfileId: 1,
        );
    }

    /**
     * The shipped adapter with one declaration overridden.
     *
     * The credential-strategy cases have to name the strategy they are about
     * rather than lean on whatever Vrio currently declares: the point is that
     * the validator adapts to a declaration (`[14.4]`), so a test that read
     * the shipped value would stop testing anything the day that value
     * changed.
     *
     * @return \Closure(): PaymentAdapter
     */
    private function redeclaredAs(string $strategy): \Closure
    {
        $inner = $this->vrioAdapter();

        return static fn (): PaymentAdapter => new RedeclaredAdapter($inner, $strategy, true);
    }

    private function writeCatalog(bool $withUnmappedExtra = false): void
    {
        $products = [
            'tirzepatide' => [
                'slug' => 'tirzepatide',
                'name' => 'Tirzepatide',
                'kind' => 'rx',
                'emr_product_id' => '3414',
                'variants' => [
                    ['id' => 't-1m', 'name' => '1 Month', 'price_cents' => 14000, 'provider' => ['offer_id' => '337', 'product_id' => '3414']],
                ],
            ],
        ];

        if ($withUnmappedExtra) {
            $products['pill-organizer'] = [
                'slug' => 'pill-organizer',
                'name' => 'Pill Organizer',
                'kind' => 'otc',
                'emr_product_id' => '3500',
                'variants' => [['id' => 'po-1', 'name' => 'One', 'price_cents' => 499, 'provider' => null]],
            ];
        }

        file_put_contents(
            $this->configDir . '/products.generated.php',
            '<?php return ' . var_export([
                'channel' => ['id' => 'channel-123', 'name' => 'Flow 1', 'currency' => 'USD'],
                'products' => $products,
            ], true) . ';',
        );
    }

    /** @param array<string, string>|null $processorConfig */
    private function writeChannel(?array $processorConfig = null): void
    {
        file_put_contents(
            $this->configDir . '/channel.generated.php',
            '<?php return ' . var_export([
                'channel' => ['id' => 'channel-123', 'name' => 'Flow 1'],
                'payment_processor' => [
                    'provider_category' => 'vrio',
                    'name' => 'CC Sandbox Account',
                    'config' => $processorConfig ?? [
                        'api_endpoint' => 'https://api.vrio.app',
                        'api_key' => 'jwt',
                        'campaign_id' => '147',
                        'connection_id' => '1',
                    ],
                ],
            ], true) . ';',
        );
    }

    private function writePayment(?int $shippingProfileId = 1): void
    {
        file_put_contents(
            $this->configDir . '/payment.php',
            '<?php return ' . var_export([
                'adapter' => null,
                'shipping_profile_id' => $shippingProfileId,
                'currency' => 'USD',
            ], true) . ';',
        );
    }

    /** @param array<string, array<string, mixed>> $upsells */
    private function writeUpsells(array $upsells): void
    {
        file_put_contents(
            $this->configDir . '/upsells.php',
            '<?php return ' . var_export(['upsells' => $upsells], true) . ';',
        );
    }

    /** @param array<string, list<array<string, mixed>>> $bumps */
    private function writeCrossSells(array $bumps): void
    {
        file_put_contents(
            $this->configDir . '/cross-sells.php',
            '<?php return ' . var_export(['max_on_page' => 3, 'bumps' => $bumps], true) . ';',
        );
    }
}

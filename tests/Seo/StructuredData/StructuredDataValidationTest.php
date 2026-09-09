<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Seo\StructuredData;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Console\ValidateCommand;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `[24.11]` — a malformed emission is a `config:validate` **error**, so it is
 * caught by the deploy that introduced it rather than by a search console
 * weeks later. Error, not warning: `config:validate` exits **2** on error and
 * 0 on warnings, and only the first of those stops a deploy.
 */
final class StructuredDataValidationTest extends TestCase
{
    private string $configDir;

    private string $rootDir;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = 'test-' . $key;
        }

        $this->configDir = sys_get_temp_dir() . '/structured-data-validate-' . uniqid();
        $this->rootDir = sys_get_temp_dir() . '/structured-data-validate-root-' . uniqid();
        mkdir($this->configDir);
        mkdir($this->rootDir);

        $this->write('channel.generated', [
            'channel' => ['id' => 'channel-123', 'name' => 'Demo Store'],
            'payment_processor' => ['provider_category' => 'vrio', 'name' => 'Vrio', 'config' => ['api_key' => 'x']],
        ]);
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

        foreach (glob($this->configDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->configDir);
        @rmdir($this->rootDir);
    }

    public function testAWellFormedDeploymentPassesAndSaysSo(): void
    {
        $this->writeDeployment();

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Catalog valid.', $tester->getDisplay());
    }

    public function testACatalogNamingNoCurrencyFailsTheDeploy(): void
    {
        // An `Offer` with no `priceCurrency` states an amount in no unit, so
        // the product publishes no offer at all — a listing with a price on
        // the page and none in the structured data.
        $catalog = $this->catalog();
        unset($catalog['channel']['currency']);
        $this->writeDeployment($catalog);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: structured data: /products/metabolic-support/: Product publishes no offer', $tester->getDisplay());
    }

    public function testAnApplicationUrlThatNamesNoHostFailsTheDeploy(): void
    {
        // Every emitted URL would be relative, which is meaningless in a
        // document a crawler fetched from somewhere else.
        $this->writeDeployment(null, ['url' => '']);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('ERROR: structured data:', $tester->getDisplay());
        self::assertStringContainsString('is not an absolute URL', $tester->getDisplay());
    }

    public function testAProductPricedAtNothingFailsTheDeploy(): void
    {
        $catalog = $this->catalog();
        $catalog['products']['metabolic-support']['variants'][0]['price_cents'] = 0;
        $this->writeDeployment($catalog);

        $tester = new CommandTester($this->command());

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('published as free', $tester->getDisplay());
    }

    public function testADeploymentThatPublishesNothingIsNotJudgedOnIt(): void
    {
        // Every emitter off is a decision, not a fault.
        $this->writeDeployment(null, ['seo' => $this->seo([
            'organisation' => false,
            'website' => false,
            'product' => false,
            'breadcrumbs' => false,
            'rx' => false,
        ])]);

        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
    }

    public function testTheStructuralPassIsUnaffectedWhenTheCommandHasNoConfiguration(): void
    {
        // The callers that pass no `Config` have no `app.seo` to be judged
        // against, and inventing one would report a deployment that does not
        // exist.
        $this->writeDeployment();

        $config = Config::load($this->configDir);
        $tester = new CommandTester(new ValidateCommand(
            new CatalogProvider($config),
            new ClientFactory($config, $this->rootDir),
            $this->configDir,
        ));

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('structured data:', $tester->getDisplay());
    }

    // --- fixtures --------------------------------------------------------

    private function command(): ValidateCommand
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

    /**
     * @param array<string, mixed>|null $catalog
     * @param array<string, mixed>      $app
     */
    private function writeDeployment(?array $catalog = null, array $app = []): void
    {
        $this->write('products.generated', $catalog ?? $this->catalog());
        $this->write('app', $app + [
            'url' => 'https://storefront.example',
            'name' => 'Demo Store',
            'emr' => ['base_host' => 'sales.example.test', 'channel_id' => 'channel-123'],
            'seo' => $this->seo(),
        ]);
    }

    /**
     * @param array<string, bool> $structuredData
     *
     * @return array<string, mixed>
     */
    private function seo(array $structuredData = []): array
    {
        return [
            'site_name' => 'Demo Store',
            'default_description' => 'A demonstration storefront.',
            'structured_data' => $structuredData + [
                'organisation' => true,
                'website' => true,
                'product' => true,
                'breadcrumbs' => true,
                'rx' => false,
            ],
        ];
    }

    /** One `otc` product, so the prescription default does not silence the pass. */
    private function catalog(): array
    {
        return [
            'channel' => ['id' => 'channel-123', 'name' => 'Demo Store', 'currency' => 'USD'],
            'products' => [
                'metabolic-support' => [
                    'slug' => 'metabolic-support',
                    'name' => 'Metabolic Support',
                    'kind' => 'otc',
                    'description' => '',
                    'price_cents' => 4600,
                    'image' => '/assets/img/metabolic-support.png',
                    'emr_product_id' => 'abc123',
                    'variants' => [
                        ['id' => 'v1', 'name' => '1 Month', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $values */
    private function write(string $name, array $values): void
    {
        file_put_contents(
            $this->configDir . '/' . $name . '.php',
            '<?php return ' . var_export($values, true) . ';',
        );
    }
}

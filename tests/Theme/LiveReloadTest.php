<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Theme;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The designer's reload loop, and the one thing that must never follow it into
 * production.
 *
 * `npm run dev` rebuilds on every save; `livereload.js` is what turns that
 * into a browser that refreshes itself. It polls the build manifest forever,
 * so a copy left on a production page would be every visitor issuing a request
 * a second for as long as they had the tab open.
 *
 * This is asserted against a *configuration*, not against `APP_ENV=test`. The
 * suite runs non-production, so a case that only checked the shipped config
 * would show the script present and prove nothing about the branch that
 * withholds it.
 */
final class LiveReloadTest extends TestCase
{
    use TempDatabase;
    use ConfigVariant;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function homeInEnvironment(string $env): string
    {
        $app = require dirname(__DIR__, 2) . '/config/app.php';
        $app['env'] = $env;

        $slim = AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            Config::class => $this->configWith(['app' => $app]),
        ]);

        return (string) $slim->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
        )->getBody();
    }

    public function testAProductionPageNeverCarriesTheReloadScript(): void
    {
        self::assertStringNotContainsString('livereload', $this->homeInEnvironment('production'));
    }

    public function testADevelopmentPageCarriesTheReloadScript(): void
    {
        self::assertStringContainsString('livereload', $this->homeInEnvironment('dev'));
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

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
 * Where the patient portal is, and what the storefront does when nobody has
 * said.
 *
 * Three links pointed at nothing before this: the header's "Sign In" and its
 * mobile twin at `#sign-in`, and the receipt's "Go to Patient Portal" at `#`,
 * which carried a comment admitting it was a placeholder. The portal is now
 * where every other per-deployment address lives -- `config/app.php`, read
 * from the environment.
 *
 * The absent case is the one worth pinning. A button that goes nowhere is
 * worse than no button: it is indistinguishable from a broken one, and on the
 * receipt it is the only thing a buyer is told to do next.
 */
final class PatientPortalLinkTest extends TestCase
{
    use TempDatabase;
    use ConfigVariant;

    private const string PORTAL = 'https://portal.example.test/';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function homeWith(?string $portal): string
    {
        $app = require dirname(__DIR__, 2) . '/config/app.php';
        $app['portal'] = ['url' => $portal];

        $slim = AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            Config::class => $this->configWith(['app' => $app]),
        ]);

        return (string) $slim->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
        )->getBody();
    }

    public function testTheHeaderSignInPointsAtTheConfiguredPortal(): void
    {
        $body = $this->homeWith(self::PORTAL);

        self::assertStringContainsString('href="' . self::PORTAL . '"', $body);
        self::assertStringNotContainsString('#sign-in', $body, 'the placeholder anchor is gone');
    }

    /**
     * Both the desktop button and the mobile menu's copy of it, because they
     * are separate markup and fixing one is the obvious way to leave the
     * other pointing at a fragment that does not exist.
     */
    public function testBothCopiesOfSignInAreLinked(): void
    {
        self::assertSame(
            2,
            substr_count($this->homeWith(self::PORTAL), 'href="' . self::PORTAL . '"'),
            'the desktop button and the mobile menu each need the link',
        );
    }

    public function testNoConfiguredPortalRendersNoSignInAtAll(): void
    {
        $body = $this->homeWith(null);

        self::assertStringNotContainsString('Sign In', $body, 'a button that goes nowhere is worse than no button');
        self::assertStringNotContainsString('#sign-in', $body);
    }
}

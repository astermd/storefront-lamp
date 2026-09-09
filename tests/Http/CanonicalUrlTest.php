<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CanonicalUrlTest extends TestCase
{
    private function handle(string $method, string $uri): \Psr\Http\Message\ResponseInterface
    {
        $app = AppFactory::create(dirname(__DIR__, 2));

        // Prefixed with a scheme+host: PHP's parse_url() (used by Slim's
        // UriFactory::createUri()) treats a bare request-target string
        // starting with "//" as a network-path reference and swallows the
        // first segment as the authority/host, not the path. Real requests
        // never hit this ambiguity — Slim builds the Uri straight from
        // $_SERVER['REQUEST_URI'] there, with no such reparsing. Anchoring
        // the test string to an explicit origin avoids that parser quirk
        // without touching the double-slash path this test exists to cover.
        return $app->handle((new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $uri));
    }

    public function testMissingTrailingSlashRedirectsPreservingQuery(): void
    {
        $response = $this->handle('GET', '/treatments?utm_source=x&_amd=abc%2Fdef');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/treatments/?utm_source=x&_amd=abc%2Fdef', $response->getHeaderLine('Location'));
    }

    public function testUppercaseAndDoubleSlashesNormalizeInOneHop(): void
    {
        $response = $this->handle('GET', '//Treatments//Semaglutide');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/treatments/semaglutide/', $response->getHeaderLine('Location'));
    }

    public function testCanonicalPathPassesThrough(): void
    {
        self::assertSame(200, $this->handle('GET', '/')->getStatusCode());
    }

    public function testPostIsNeverRedirected(): void
    {
        $response = $this->handle('POST', '/treatments');
        self::assertNotSame(301, $response->getStatusCode());
    }

    public function testFileLikePathPassesThroughWithoutRedirect(): void
    {
        $response = $this->handle('GET', '/sitemap.xml');
        // No route exists for this path, so it 404s — the point of this test
        // is that it does NOT 301 to /sitemap.xml/ first.
        self::assertNotSame(301, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    public function testUppercasePathStillRedirects(): void
    {
        $response = $this->handle('GET', '/Treatments');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/treatments/', $response->getHeaderLine('Location'));
    }
}

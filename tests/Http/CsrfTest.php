<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function app(): \Slim\App
    {
        return AppFactory::create(dirname(__DIR__, 2));
    }

    public function testGetSeedsTokenAndPasses(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($_SESSION['_csrf'] ?? '');
    }

    public function testPostWithoutTokenIs419(): void
    {
        $app = $this->app();
        $app->post('/echo/', fn ($request, $response) => $response);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/echo/'));
        self::assertSame(419, $response->getStatusCode());
    }

    public function testPostWithValidTokenPasses(): void
    {
        $app = $this->app();
        $app->post('/echo/', fn ($request, $response) => $response->withStatus(204));
        // Seed the token with a GET first.
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/echo/')
            ->withParsedBody(['_csrf' => $_SESSION['_csrf']]);
        self::assertSame(204, $app->handle($request)->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Bootstrap;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Emr\NullSessionEventGateway;
use AsterMD\Storefront\Emr\SessionEventGateway;
use AsterMD\Storefront\Support\AfterResponse;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AppFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testPostToGetOnlyRouteIs405NotThemedServerError(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        // Seed the CSRF token with a GET first, same as tests/Http/CsrfTest.php.
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withParsedBody(['_csrf' => $_SESSION['_csrf']]);
        $response = $app->handle($request);
        self::assertSame(405, $response->getStatusCode());
    }

    public function testHomePageRenders(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $app->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('aria-label="AsterMD home"', (string) $response->getBody());
    }

    /**
     * Page views go out after every page, so under the suite the gateway has
     * to be the null one — a test run that reached the EMR would write to it —
     * and a page leaves nothing queued for `public/index.php` to send.
     */
    public function testUnderTheSuitePageViewsAreBoundToTheNullGatewayAndQueueNothing(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $container = $app->getContainer();
        self::assertNotNull($container);

        self::assertInstanceOf(NullSessionEventGateway::class, $container->get(SessionEventGateway::class));

        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame([], $container->get(AfterResponse::class)->queued());
    }

    public function testUnknownPathIs404WithThemedBody(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/nope/');
        $response = $app->handle($request);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('not', strtolower((string) $response->getBody()));
    }
}

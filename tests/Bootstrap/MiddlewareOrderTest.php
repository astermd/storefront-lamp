<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Bootstrap;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Http\Middleware\AttributionMiddleware;
use AsterMD\Storefront\Http\Middleware\CanonicalUrlMiddleware;
use AsterMD\Storefront\Http\Middleware\CsrfMiddleware;
use AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware;
use AsterMD\Storefront\Http\Middleware\RetiredSessionMiddleware;
use AsterMD\Storefront\Http\Middleware\SeoMiddleware;
use AsterMD\Storefront\Http\Middleware\SessionMiddleware;
use AsterMD\Storefront\Http\Middleware\StepGuardMiddleware;
use AsterMD\Storefront\Http\Middleware\TemplateGlobalsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Locks down the middleware pipeline's ordering contract: the declared
 * outermost-first list in {@see AppFactory::MIDDLEWARE_ORDER} must match
 * both what's registered and what actually runs, in both directions
 * (declared order vs. registration, and declared order vs. the observed
 * innermost-first trace-header order). A silent reordering here would
 * change session/CSRF/canonicalisation semantics without any single
 * middleware's own tests catching it.
 */
final class MiddlewareOrderTest extends TestCase
{
    public function testDeclaredOrderIsTheContract(): void
    {
        self::assertSame([
            CanonicalUrlMiddleware::class,
            SeoMiddleware::class,
            SessionMiddleware::class,
            RetiredSessionMiddleware::class,
            AttributionMiddleware::class,
            CsrfMiddleware::class,
            JourneyStateMiddleware::class,
            StepGuardMiddleware::class,
            TemplateGlobalsMiddleware::class,
        ], AppFactory::MIDDLEWARE_ORDER);
    }

    public function testRuntimeExecutionMatchesDeclaredOrder(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        // Headers are appended on the way OUT (innermost first), so the trace
        // reads innermost→outermost; reversed it must equal the declared order.
        self::assertSame(
            ['globals', 'guard', 'journey', 'csrf', 'attribution', 'retired-session', 'session', 'seo', 'canonical'],
            $response->getHeader('X-MW-Trace'),
        );
    }

    public function testTraceHeaderIsAbsentWhenTraceDisabled(): void
    {
        $middleware = new CanonicalUrlMiddleware(false);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
            $handler,
        );
        self::assertSame([], $response->getHeader('X-MW-Trace'));
    }
}

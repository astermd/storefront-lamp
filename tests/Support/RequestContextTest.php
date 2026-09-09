<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\RequestContext;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RequestContextTest extends TestCase
{
    public function testCdnHeaderWinsOverForwardedForAndRemoteAddr(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('CF-Connecting-IP', '203.0.113.7')
            ->withHeader('X-Forwarded-For', '198.51.100.9, 10.0.0.2');

        self::assertSame('203.0.113.7', RequestContext::fromRequest($request)->clientIp);
    }

    public function testFirstForwardedHopWinsOverRemoteAddr(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', '198.51.100.9, 10.0.0.2');

        self::assertSame('198.51.100.9', RequestContext::fromRequest($request)->clientIp);
    }

    public function testFallsBackToRemoteAddrAndSkipsGarbage(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('CF-Connecting-IP', 'not-an-ip')
            ->withHeader('X-Forwarded-For', 'also-not-an-ip');

        self::assertSame('10.0.0.1', RequestContext::fromRequest($request)->clientIp);
    }

    public function testNoUsableAddressOrAgentYieldsNulls(): void
    {
        $context = RequestContext::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertNull($context->clientIp);
        self::assertNull($context->userAgent);
    }

    public function testUserAgentIsTrimmedAndEmptyBecomesNull(): void
    {
        $factory = new ServerRequestFactory();
        self::assertSame(
            'Mozilla/5.0',
            RequestContext::fromRequest($factory->createServerRequest('GET', '/')->withHeader('User-Agent', ' Mozilla/5.0 '))->userAgent,
        );
        self::assertNull(
            RequestContext::fromRequest($factory->createServerRequest('GET', '/')->withHeader('User-Agent', '   '))->userAgent,
        );
    }
}

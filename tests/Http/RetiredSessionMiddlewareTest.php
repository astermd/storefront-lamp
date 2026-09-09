<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Http\Middleware\RetiredSessionMiddleware;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Journey\SessionOptions;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The server-side half of `[4.18]`, built by hand.
 *
 * A unit test rather than a walk through the app, because what is being pinned
 * is a `Set-Cookie` header's presence and absence against four journey shapes,
 * and the one shape that matters most -- the receipt keeping its own session
 * alive -- is invisible in a full-app test that only ever visits one path.
 */
final class RetiredSessionMiddlewareTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private \PDO $pdo;

    private JourneyStore $journeys;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->journeys = new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo));
    }

    public function testTheReceiptKeepsItsOwnSessionAliveBecauseUpsellsAreReachedFromIt(): void
    {
        // `[4.18]`. Clearing here would strand a buyer who presses back off the
        // page recording the purchase they just made.
        $this->retiredJourney();

        $response = $this->respondTo('/thank-you/');

        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testAnyOtherPageEndsTheSessionSoTheNextBrowseStartsAFreshOne(): void
    {
        $this->retiredJourney();

        $cookie = $this->respondTo('/')->getHeaderLine('Set-Cookie');

        self::assertStringStartsWith('amd_session=;', $cookie);
        self::assertStringContainsString('Max-Age=0', $cookie);
        self::assertStringContainsString('Expires=Thu, 01 Jan 1970 00:00:00 GMT', $cookie);
        self::assertStringContainsString('Path=/', $cookie, 'a different path is a different cookie to a browser');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringNotContainsString(self::SESSION, $cookie, 'nothing is left to identify');
    }

    public function testTheSecureAttributeFollowsTheSchemeTheRequestArrivedOver(): void
    {
        // Attribute mismatch is cookie identity mismatch: a clear that drops
        // `Secure` on a TLS site does not replace the cookie it is aimed at.
        $this->retiredJourney();

        self::assertStringContainsString('Secure', $this->respondTo('/', scheme: 'https')->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString('Secure', $this->respondTo('/')->getHeaderLine('Set-Cookie'));
    }

    public function testAJourneyThatHasNotCompletedKeepsItsSession(): void
    {
        $this->journeys->load(self::SESSION);

        self::assertSame([], $this->respondTo('/')->getHeader('Set-Cookie'));
    }

    public function testAVisitorWithNoJourneyAtAllKeepsWhateverTheyHave(): void
    {
        // Nothing has been proved about them, so nothing is taken from them.
        self::assertSame([], $this->respondTo('/')->getHeader('Set-Cookie'));
    }

    public function testAnUnreachableJourneyStoreLeavesTheCookieAlone(): void
    {
        // `[20.1]`: the visitor's page comes first, and a stale analytics
        // session is the cheaper of the two failures.
        $middleware = new RetiredSessionMiddleware(
            static fn (): JourneyStore => throw new \RuntimeException('the database went away'),
            $this->flow(),
            new SessionOptions(),
        );

        $response = $middleware->process($this->request('/'), $this->handler());

        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testTheResponseIsOtherwiseUntouched(): void
    {
        $this->retiredJourney();

        $response = $this->respondTo('/');

        self::assertSame(418, $response->getStatusCode());
        self::assertSame('handled', (string) $response->getBody());
        self::assertSame('kept', $response->getHeaderLine('X-Downstream'));
    }

    // ---------------------------------------------------------------- fixtures

    private function retiredJourney(): JourneyState
    {
        $state = $this->journeys->load(self::SESSION);
        $state->sessionRetired = true;

        return $state;
    }

    private function respondTo(string $path, string $scheme = 'http'): ResponseInterface
    {
        $journeys = $this->journeys;

        $middleware = new RetiredSessionMiddleware(
            static fn (): JourneyStore => $journeys,
            $this->flow(),
            new SessionOptions(),
        );

        return $middleware->process($this->request($path, $scheme), $this->handler());
    }

    private function flow(): FlowDefinition
    {
        return new FlowDefinition([
            'home' => ['path' => '/', 'requires' => []],
            'receipt' => ['path' => '/thank-you/', 'requires' => []],
        ]);
    }

    private function request(string $path, string $scheme = 'http'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $scheme . '://storefront.test' . $path);
    }

    /** A downstream handler with a body, a status and a header of its own, so "otherwise untouched" is testable. */
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new Response(418))->withHeader('X-Downstream', 'kept');
                $response->getBody()->write('handled');

                return $response;
            }
        };
    }
}

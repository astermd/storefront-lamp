<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http\Middleware;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Domain\StepRouter;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Http\Middleware\StepGuardMiddleware;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * A unit test of {@see StepGuardMiddleware} built by hand, rather than
 * through the whole app: it exercises the self-redirect defence directly,
 * something no full-app test can do against the shipped `config/funnel.php`
 * (see the class docblock on {@see StepGuardMiddleware} for why the shipped
 * router and config structurally never take that branch).
 */
final class StepGuardMiddlewareTest extends TestCase
{
    use TempDatabase;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Builds a flow where `home` itself carries a requirement an empty cart
     * fails — unlike the shipped config, where `home` requires nothing.
     * {@see FunnelRouter::nextStep()} genuinely, unmodified, answers `home`
     * for an empty cart; pointed at a request for `/` that is *also* the
     * step whose own requirement just failed, which is exactly the shape the
     * self-redirect check exists to catch. No fake router is needed: the
     * real one already produces the hazard once the flow asks for it.
     */
    public function testAFailingRequirementNeverRedirectsAStepToItself(): void
    {
        $flow = new FlowDefinition([
            'home' => ['path' => '/', 'requires' => ['cart_not_empty']],
        ]);
        $router = new FunnelRouter(new FakeCatalog([]));
        $journey = new JourneyStore(new SessionRepository(fn (): \PDO => $this->tempPdo()));
        $cartStore = new CartStore($journey);

        $middleware = new StepGuardMiddleware(
            static fn (): array => [$cartStore, $journey],
            $flow,
            $router,
            new StepPreconditions(new FakeCatalog([])),
            new OperatorLog(sys_get_temp_dir() . '/step-guard-middleware-test.log'),
        );

        $handled = new class implements RequestHandlerInterface {
            public bool $wasCalled = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->wasCalled = true;

                return new Response(200);
            }
        };

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
            $handled,
        );

        self::assertTrue($handled->wasCalled, 'The request must reach the handler rather than bounce off itself.');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    /**
     * The stores no longer open a database connection when they are built, so
     * an outage is not what reaches this boundary any more — but the boundary
     * is still the difference between "one collaborator could not be
     * assembled" and a 500 on every guarded page. A closure that throws is the
     * only way left to exercise it, and what it must produce is an unguarded
     * pass-through plus a log line: preconditions evaluated against a
     * fabricated empty cart would bounce a visitor whose real cart is sitting
     * in the PHP session (`[20.1]`).
     */
    public function testStoresThatCannotBeBuiltLetTheRequestThroughUnguardedAndAreLogged(): void
    {
        $logFile = sys_get_temp_dir() . '/step-guard-unavailable-' . bin2hex(random_bytes(6)) . '.log';
        $middleware = new StepGuardMiddleware(
            static fn (): array => throw new \RuntimeException('stores unavailable'),
            new FlowDefinition(['checkout' => ['path' => '/checkout/', 'requires' => ['cart_not_empty']]]),
            new FunnelRouter(new FakeCatalog([])),
            new StepPreconditions(new FakeCatalog([])),
            new OperatorLog($logFile),
        );

        $handled = new class implements RequestHandlerInterface {
            public bool $wasCalled = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->wasCalled = true;

                return new Response(200);
            }
        };

        try {
            $response = $middleware->process(
                (new ServerRequestFactory())->createServerRequest('GET', '/checkout/'),
                $handled,
            );

            self::assertTrue($handled->wasCalled);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('funnel.guard_unavailable', (string) file_get_contents($logFile));
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }

    public function testAGuardWhoseRoutingDecisionNamesTheCurrentStepRefusesThePage(): void
    {
        // A flow whose routing decision points at a step that cannot satisfy its
        // own requirements is a defect in the flow, not a reason to serve an
        // unguarded page. Falling through to the handler here is how someone
        // reaches checkout without a chosen plan.
        $flow = new FlowDefinition([
            'home' => ['path' => '/', 'requires' => []],
            'checkout' => ['path' => '/checkout/', 'requires' => ['cart_not_empty']],
        ]);

        $middleware = $this->guardWith(
            flow: $flow,
            router: new FixedStepRouter('checkout'),   // names the step we are on
            satisfied: false,
        );

        $response = $middleware->process(
            $this->requestFor('/checkout/'),
            $this->handlerReturning(new Response(200)),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testTheRefusalIsLoggedSoAFlowDefectIsVisible(): void
    {
        $log = new CapturedLog();
        $flow = new FlowDefinition([
            'home' => ['path' => '/', 'requires' => []],
            'checkout' => ['path' => '/checkout/', 'requires' => ['cart_not_empty']],
        ]);

        $this->guardWith(flow: $flow, router: new FixedStepRouter('checkout'), satisfied: false, log: $log)
            ->process($this->requestFor('/checkout/'), $this->handlerReturning(new Response(200)));

        self::assertSame('funnel.guard_self_redirect', $log->lastWarning()['event'] ?? null);
        self::assertSame('checkout', $log->lastWarning()['context']['step'] ?? null);
        self::assertSame('cart_not_empty', $log->lastWarning()['context']['requirement'] ?? null);
    }

    public function testAnEntryStepThatFailsItsOwnRequirementStillServesRatherThanLooping(): void
    {
        // The fallback is the flow's entry step. If the entry step itself is what
        // failed, redirecting to it would loop forever, so the guard serves it and
        // logs -- an unguarded home page is not a security boundary.
        $flow = new FlowDefinition([
            'home' => ['path' => '/', 'requires' => ['cart_not_empty']],
        ]);

        $response = $this->guardWith(flow: $flow, router: new FixedStepRouter('home'), satisfied: false)
            ->process($this->requestFor('/'), $this->handlerReturning(new Response(200)));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Builds the middleware over a real session-backed cart, because the
     * preconditions are final and the fact a requirement reads is the cart
     * itself: `$satisfied` decides whether that cart holds a line, which is
     * what makes `cart_not_empty` answer true or false.
     */
    private function guardWith(
        FlowDefinition $flow,
        StepRouter $router,
        bool $satisfied,
        ?CapturedLog $log = null,
    ): StepGuardMiddleware {
        $journey = new JourneyStore(new SessionRepository(fn (): \PDO => $this->tempPdo()));
        $carts = new CartStore($journey);

        if ($satisfied) {
            $cart = $carts->cart();
            $cart->put(new CartLine('example', 'Example', 'otc', null, null, 1, 1000));
            $carts->save($cart);
        }

        return new StepGuardMiddleware(
            static fn (): array => [$carts, $journey],
            $flow,
            $router,
            new StepPreconditions(new FakeCatalog([])),
            ($log ?? new CapturedLog())->log,
        );
    }

    private function requestFor(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path);
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}

/** A router that always names one step, so the guard's own branching is what a test exercises. */
final class FixedStepRouter implements StepRouter
{
    public function __construct(private readonly string $step)
    {
    }

    public function nextStep(Cart $cart, ?JourneyState $state = null): string
    {
        return $this->step;
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Emr\CartGateway;
use AsterMD\Storefront\Emr\CartMirror;
use AsterMD\Storefront\Emr\CartMirrorResult;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrCartGateway;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeCartGateway;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

final class CartMirrorTest extends TestCase
{
    private string $logFile;

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/cart-mirror-log-' . uniqid() . '.log';

        foreach (['ASTERMD_CLIENT_ID' => 'test-client-id', 'ASTERMD_CLIENT_SECRET' => 'test-client-secret'] as $key => $value) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];

        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function log(): OperatorLog
    {
        return new OperatorLog($this->logFile);
    }

    private function cartWithLines(): Cart
    {
        $cart = new Cart();
        $cart->put(new CartLine(slug: 'semaglutide', name: 'Semaglutide', kind: 'rx', emrProductId: 'emr-1', parentSlug: null, quantity: 1));
        $cart->put(new CartLine(slug: 'syringes', name: 'Syringes', kind: 'otc', emrProductId: 'emr-2', parentSlug: 'semaglutide', quantity: 2));

        return $cart;
    }

    public function testTheFirstMirrorCreatesTheRemoteCartAndFlagsTheJourney(): void
    {
        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();
        $state->cartMirrored = false;

        $mirror->mirror('sess-1', $this->cartWithLines(), $state);

        self::assertCount(1, $gateway->createCalls);
        self::assertCount(0, $gateway->updateCalls);
        self::assertSame('sess-1', $gateway->createCalls[0][0]);
        self::assertSame(
            [
                ['product_id' => 'emr-1', 'name' => 'Semaglutide', 'qty' => 1],
                ['product_id' => 'emr-2', 'name' => 'Syringes', 'qty' => 2],
            ],
            $gateway->createCalls[0][1],
        );
        self::assertTrue($state->cartMirrored);
    }

    public function testEverySubsequentMirrorUpdates(): void
    {
        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();
        $state->cartMirrored = true;

        $mirror->mirror('sess-1', $this->cartWithLines(), $state);

        self::assertCount(0, $gateway->createCalls);
        self::assertCount(1, $gateway->updateCalls);
        self::assertTrue($state->cartMirrored);
    }

    public function testAnUpdateAgainstAMissingRemoteCartFallsBackToCreateOnce(): void
    {
        $gateway = new FakeCartGateway(updateResult: CartMirrorResult::NotFound);
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();
        $state->cartMirrored = true;

        $mirror->mirror('sess-1', $this->cartWithLines(), $state);

        self::assertCount(1, $gateway->updateCalls);
        self::assertCount(1, $gateway->createCalls);
        self::assertTrue($state->cartMirrored);
    }

    public function testLinesWithNoEmrIdentifierAreSkipped(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine(slug: 'a', name: 'A', kind: 'otc', emrProductId: 'emr-a', parentSlug: null));
        $cart->put(new CartLine(slug: 'b', name: 'B', kind: 'otc', emrProductId: null, parentSlug: null));
        $cart->put(new CartLine(slug: 'c', name: 'C', kind: 'otc', emrProductId: 'emr-c', parentSlug: null));

        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();

        $mirror->mirror('sess-1', $cart, $state);

        self::assertCount(1, $gateway->createCalls);
        self::assertCount(2, $gateway->createCalls[0][1]);
    }

    public function testACartWithNoMirrorableLinesNeverCallsTheEmr(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine(slug: 'a', name: 'A', kind: 'otc', emrProductId: null, parentSlug: null));

        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();

        $mirror->mirror('sess-1', $cart, $state);

        self::assertCount(0, $gateway->createCalls);
        self::assertCount(0, $gateway->updateCalls);
        self::assertFalse($state->cartMirrored);
    }

    public function testNoSessionMeansNoCall(): void
    {
        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();

        $mirror->mirror(null, $this->cartWithLines(), $state);

        self::assertCount(0, $gateway->createCalls);
        self::assertCount(0, $gateway->updateCalls);
    }

    public function testAFailedMirrorIsSwallowedAndLogged(): void
    {
        $gateway = new FakeCartGateway(createResult: CartMirrorResult::Failed);
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();

        $mirror->mirror('sess-1', $this->cartWithLines(), $state);

        self::assertStringContainsString('cart.mirror_failed', (string) file_get_contents($this->logFile));
        self::assertFalse($state->cartMirrored);
    }

    public function testAThrowingGatewayIsAlsoSwallowed(): void
    {
        $gateway = new class implements CartGateway {
            public function create(string $session, array $items): CartMirrorResult
            {
                throw new \RuntimeException('boom');
            }

            public function update(string $session, array $items): CartMirrorResult
            {
                return CartMirrorResult::Ok;
            }
        };
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();

        $mirror->mirror('sess-1', $this->cartWithLines(), $state);

        self::assertStringContainsString('cart.mirror_failed', (string) file_get_contents($this->logFile));
        self::assertFalse($state->cartMirrored);
    }

    public function testTheLogNeverCarriesProductNames(): void
    {
        $gateway = new FakeCartGateway(createResult: CartMirrorResult::Failed);
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();

        $mirror->mirror('sess-1', $this->cartWithLines(), $state);

        self::assertStringNotContainsString('Semaglutide', (string) file_get_contents($this->logFile));
    }

    /**
     * The same invariant driven through the gateway that actually talks to the
     * provider, because {@see FakeCartGateway} logs nothing and so cannot
     * break it. The provider's response echoes a prescription name, the SDK
     * turns that field into the exception message verbatim, and the whole
     * point of the rule is that the message must not reach disk: redaction is
     * key-based and cannot see inside a string (`[20.6]`). The log must still
     * be worth reading — the exception class and the status code are what an
     * operator diagnoses an outage from.
     */
    public function testTheLogNeverCarriesProductNamesThroughTheRealGateway(): void
    {
        $http = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => [200, [
                'success' => true,
                'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
            ]],
            '/carts/create' => [500, [
                'success' => false,
                'message' => 'Cart rejected: Semaglutide is not stocked for this channel.',
            ]],
        ]);
        $mirror = new CartMirror(
            new EmrCartGateway($this->clientFactory(), $this->log(), $http),
            $this->log(),
        );

        $mirror->mirror('sess-1', $this->cartWithLines(), new JourneyState());

        $log = (string) file_get_contents($this->logFile);

        self::assertStringNotContainsString('Semaglutide', $log);
        self::assertStringContainsString('cart.mirror_request_failed', $log);
        // The log is JSON, so the class name reaches disk with its separators escaped.
        self::assertStringContainsString(trim((string) json_encode(ApiException::class), '"'), $log);
        self::assertStringContainsString('"status":500', $log);
    }

    /**
     * One failed mirror, one canonical `cart.mirror_failed` line. The gateway
     * records its own failed call under a separate name, because a single
     * mirror can make two calls (an update that 404s, then a create) and one
     * shared name made every failure count at least twice.
     */
    public function testAFailedMirrorWritesExactlyOneCanonicalLine(): void
    {
        $http = new FakeEmrHttpClient([
            '/v1/auth/api-credentials/token' => [200, [
                'success' => true,
                'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
            ]],
            '/carts/create' => [500, ['success' => false]],
        ]);
        $mirror = new CartMirror(
            new EmrCartGateway($this->clientFactory(), $this->log(), $http),
            $this->log(),
        );

        $mirror->mirror('sess-1', $this->cartWithLines(), new JourneyState());

        $log = (string) file_get_contents($this->logFile);

        self::assertSame(1, substr_count($log, '"event":"cart.mirror_failed"'));
        self::assertSame(1, substr_count($log, '"event":"cart.mirror_request_failed"'));
    }

    /**
     * `[7.10]` says every mutation is mirrored, and removing the last line is
     * a mutation. Returning early on an empty list left the EMR holding the
     * previous contents for the life of the journey, with nothing able to
     * bring the two back into agreement.
     */
    public function testEmptyingAMirroredCartIsSentAsAnUpdateCarryingAnEmptyList(): void
    {
        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();
        $state->cartMirrored = true;

        $mirror->mirror('sess-1', new Cart(), $state);

        self::assertCount(1, $gateway->updateCalls);
        self::assertCount(0, $gateway->createCalls);
        self::assertSame([], $gateway->updateCalls[0][1]);
        self::assertTrue($state->cartMirrored);
    }

    /** Nothing has been mirrored, so an empty cart still has nothing to create. */
    public function testAnEmptyCartThatWasNeverMirroredStillCallsNothing(): void
    {
        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());
        $state = new JourneyState();

        $mirror->mirror('sess-1', new Cart(), $state);

        self::assertCount(0, $gateway->createCalls);
        self::assertCount(0, $gateway->updateCalls);
        self::assertFalse($state->cartMirrored);
    }

    /**
     * Pins the degraded shape: with no journey state there is nowhere to
     * record that the remote cart exists, so every mutation issues another
     * create. Documented on {@see CartMirror::mirror()} as a deliberate
     * consequence rather than an oversight — the alternative is a second
     * durable home for the flag — and asserted here so a change of mind is a
     * change to a test rather than a silent shift in provider traffic.
     */
    public function testWithNoJourneyStateEveryMutationIssuesAnotherCreate(): void
    {
        $gateway = new FakeCartGateway();
        $mirror = new CartMirror($gateway, $this->log());

        $mirror->mirror('sess-1', $this->cartWithLines(), null);
        $mirror->mirror('sess-1', $this->cartWithLines(), null);

        self::assertCount(2, $gateway->createCalls);
        self::assertCount(0, $gateway->updateCalls);
    }

    private function clientFactory(): ClientFactory
    {
        $dir = sys_get_temp_dir() . '/cart-mirror-emr-' . uniqid();
        mkdir($dir);
        $this->tempDirs[] = $dir;
        file_put_contents(
            $dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return new ClientFactory(Config::load($dir), $dir);
    }
}

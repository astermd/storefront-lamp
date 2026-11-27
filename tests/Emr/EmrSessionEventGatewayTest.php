<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Sdk\Enum\PageType;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\DeferredSessionEventGateway;
use AsterMD\Storefront\Emr\EmrSessionEventGateway;
use AsterMD\Storefront\Emr\PageView;
use AsterMD\Storefront\Support\AfterResponse;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

final class EmrSessionEventGatewayTest extends TestCase
{
    private const array TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private string $dir;
    private string $rootDir;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->dir = sys_get_temp_dir() . '/emr-session-events-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-session-events-root-' . uniqid();
        mkdir($this->rootDir);
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testAPageViewPostsTheTypeAndPathAndOnlyTheDetailsItKnows(): void
    {
        $http = $this->http();
        $gateway = new EmrSessionEventGateway($this->clients(), (new CapturedLog())->log, $http);

        $gateway->pageView('sess-1', new PageView(
            type: PageType::Product,
            path: '/products/semaglutide/',
            url: 'https://shop.example.com/products/semaglutide/?utm_source=x',
            title: 'Semaglutide',
            productId: '64b2f0c7e3a1d2b3c4e5f6b1',
        ));

        [$path, $body] = $this->lastEvent($http);
        self::assertStringEndsWith('/v1/sales/session-events/create', $path);
        self::assertSame([
            'session' => 'sess-1',
            'event' => 'page_view',
            'page' => [
                'type' => 'product',
                'path' => '/products/semaglutide/',
                'url' => 'https://shop.example.com/products/semaglutide/?utm_source=x',
                'title' => 'Semaglutide',
                'product_id' => '64b2f0c7e3a1d2b3c4e5f6b1',
            ],
        ], $body, 'no referrer was known, so none is sent — not a blank one');
    }

    /** The EMR refuses the whole page view for a product id that is not an object id. */
    public function testAProductIdThatIsNotAnObjectIdIsLeftOutRatherThanCostingTheEvent(): void
    {
        $http = $this->http();
        $gateway = new EmrSessionEventGateway($this->clients(), (new CapturedLog())->log, $http);

        $gateway->pageView('sess-1', new PageView(PageType::Product, '/products/lab/', productId: 'REPLACE_ME_PRODUCT_ID'));

        [, $body] = $this->lastEvent($http);
        self::assertSame(['type' => 'product', 'path' => '/products/lab/'], $body['page']);
    }

    public function testBeginningVerificationPostsTheBareEvent(): void
    {
        $http = $this->http();
        $gateway = new EmrSessionEventGateway($this->clients(), (new CapturedLog())->log, $http);

        $gateway->beginIdentityVerification('sess-1');

        [$path, $body] = $this->lastEvent($http);
        self::assertStringEndsWith('/v1/sales/session-events/create', $path);
        self::assertSame(['session' => 'sess-1', 'event' => 'begin_identity_verification'], $body);
    }

    /**
     * A refused event is swallowed — it is tracking — and logged without the
     * provider's words, which can quote a product page's title back (`[20.6]`).
     */
    public function testARefusalIsSwallowedAndLoggedByClassAndStatus(): void
    {
        $log = new CapturedLog();
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/session-events/create' => [400, ['success' => false, 'message' => 'Unknown page "Semaglutide"']],
        ]);
        $gateway = new EmrSessionEventGateway($this->clients(), $log->log, $http);

        $gateway->pageView('sess-1', new PageView(PageType::Product, '/products/semaglutide/', title: 'Semaglutide'));

        $line = $log->lines()[0];
        self::assertSame('session_event.request_failed', $line['event']);
        self::assertSame('page_view', $line['context']['event']);
        self::assertSame(400, $line['context']['status']);
        self::assertStringNotContainsString('Semaglutide', $log->contents());
    }

    /** Nothing leaves while the page is being built; everything leaves once it has gone. */
    public function testTheDeferredGatewaySendsNothingUntilTheResponseIsOut(): void
    {
        $http = $this->http();
        $after = new AfterResponse((new CapturedLog())->log);
        $gateway = new DeferredSessionEventGateway(
            new EmrSessionEventGateway($this->clients(), (new CapturedLog())->log, $http),
            $after,
        );

        $gateway->pageView('sess-1', new PageView(PageType::Home, '/'));
        $gateway->beginIdentityVerification('sess-1');

        self::assertSame([], $http->requests);
        self::assertSame(['page_view', 'begin_identity_verification'], $after->queued());

        $after->run();

        $events = [];
        foreach ($http->requests as $request) {
            if (str_ends_with($request->getUri()->getPath(), '/session-events/create')) {
                $events[] = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR)['event'];
            }
        }
        self::assertSame(['page_view', 'begin_identity_verification'], $events);
    }

    private function http(): FakeEmrHttpClient
    {
        return new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/session-events/create' => [201, ['success' => true, 'data' => ['_id' => '6a8b39941076171b7c0e2f2c']]],
        ]);
    }

    private function clients(): ClientFactory
    {
        return new ClientFactory(Config::load($this->dir), $this->rootDir);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function lastEvent(FakeEmrHttpClient $http): array
    {
        $request = end($http->requests);
        self::assertNotFalse($request);

        return [
            $request->getUri()->getPath(),
            (array) json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
        ];
    }
}

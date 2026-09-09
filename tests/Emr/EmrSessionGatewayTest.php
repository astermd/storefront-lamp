<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrSessionGateway;
use AsterMD\Storefront\Emr\NullSessionGateway;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * `tests/fixtures/session-view.json` carries the field shape of a real
 * `sessions()->view()` response: a `data` object keyed by session UUID, each
 * entry carrying an aggregated `data` read-model and an `events` timeline
 * whose entries name themselves under `event`. Its values are invented, but
 * its keys are not — they were taken from a live read rather than from the
 * SDK's prose, which is why the gateway's extractors can be trusted to look
 * in the right places. Event names come from the SDK's own vocabulary
 * (`\AsterMD\Sdk\Enum\Event`, `\AsterMD\Sdk\Enum\CheckoutEvent`) plus the
 * implicit `visit_page` the server records on session creation.
 */
final class EmrSessionGatewayTest extends TestCase
{
    private const TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private string $dir;
    private string $rootDir;
    private string $logFile;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->dir = sys_get_temp_dir() . '/emr-session-gateway-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-session-gateway-root-' . uniqid();
        mkdir($this->rootDir);
        $this->logFile = sys_get_temp_dir() . '/emr-session-gateway-log-' . uniqid() . '.log';
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

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function clientFactory(): ClientFactory
    {
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return new ClientFactory(Config::load($this->dir), $this->rootDir);
    }

    private function log(): OperatorLog
    {
        return new OperatorLog($this->logFile);
    }

    public function testCreateReturnsTheSessionUuidAndForwardsTheVisitorsIpAndAgent(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/sessions/create' => [200, ['success' => true, 'data' => ['session' => 'sess-1234567890abcdef']]],
        ]);
        $gateway = new EmrSessionGateway($this->clientFactory(), $this->log(), $http);

        $uuid = $gateway->create(['referrer' => 'https://partner.example/'], 'Mozilla/5.0', '203.0.113.7');

        self::assertSame('sess-1234567890abcdef', $uuid);
        $create = array_values(array_filter(
            $http->requests,
            static fn ($request): bool => str_contains($request->getUri()->getPath(), '/sessions/create'),
        ))[0];
        self::assertSame('Mozilla/5.0', $create->getHeaderLine('User-Agent'));
        self::assertSame('203.0.113.7', $create->getHeaderLine('X-Original-Client-Ip'));
        self::assertStringContainsString('partner.example', (string) $create->getBody());
    }

    public function testCreateReturnsNullAndLogsWhenTheEmrFails(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/create' => [500, ['success' => false]]]);

        self::assertNull((new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->create([], null, null));
        self::assertStringContainsString('session.create_failed', (string) file_get_contents($this->logFile));
    }

    public function testCreateReturnsNullWhenNoSessionIdentifierComesBack(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/create' => [200, ['success' => true, 'data' => []]]]);

        self::assertNull((new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->create([], null, null));
    }

    public function testCreateReturnsNullAndLogsWhenTheSessionIdentifierIsNotAString(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/create' => [200, ['success' => true, 'data' => ['session' => 123456]]]]);

        self::assertNull((new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->create([], null, null));
        self::assertStringContainsString('session.create_failed', (string) file_get_contents($this->logFile));
    }

    public function testViewReadsTheOpportunityAndTheAlreadyRecordedEventsFromTheRealFixture(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/session-view.json'), true);
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [200, $fixture]]);

        $snapshot = (new EmrSessionGateway($this->clientFactory(), $this->log(), $http))
            ->view('4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30');

        self::assertTrue($snapshot['exists']);
        self::assertSame('0a9d7c3e-5b2f-4d81-8e64-1f3a6c9b2d7e', $snapshot['opportunity_id']);
        self::assertContains('visit_page', $snapshot['events']);
    }

    public function testReadsTheOpportunityAndEventNamesFromTheRecordedReadModelShape(): void
    {
        // Recorded from a live session read: the opportunity sits at
        // `data.opportunity_id` and each event carries its name under `event`
        // -- the first entries in the gateway's own path and key lists.
        $uuid = 'bf303af0-2ce0-4548-a13c-4438959f716b';
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [200, [
            'success' => true,
            'message' => 'Sessions retrieved.',
            'data' => [
                $uuid => [
                    'data' => [
                        'ip' => '203.0.113.7',
                        'channel_id' => 'channel-123',
                        'ua' => 'Mozilla/5.0 (probe)',
                        'utm' => null,
                        'product_id' => null,
                        'opportunity_id' => 'opp-9182',
                        'patient_id' => null,
                        'master_order_id' => null,
                        'intake_completion_mode' => null,
                        'status' => 'active',
                        'last_step' => 'visit_page',
                    ],
                    'events' => [
                        ['event_id' => 'e1', 'event' => 'visit_page', 'meta' => null],
                        ['event_id' => 'e2', 'event' => 'intake_initiated', 'meta' => null],
                    ],
                ],
            ],
            'meta' => [],
        ]]]);

        $snapshot = (new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->view($uuid);

        self::assertTrue($snapshot['exists']);
        self::assertSame('opp-9182', $snapshot['opportunity_id']);
        self::assertSame(['visit_page', 'intake_initiated'], $snapshot['events']);
    }

    public function testViewFindsTheOpportunityIdWhetherItSitsUnderDataOrAtTheTopLevelOfTheEntry(): void
    {
        $nested = 'sess-opp-nested-0001';
        $flat = 'sess-opp-flat-00001';
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [200, [
            'success' => true,
            'data' => [
                $nested => ['data' => ['opportunity_id' => 'opp-both-levels'], 'events' => []],
                $flat => ['opportunity_id' => 'opp-both-levels', 'events' => []],
            ],
        ]]]);
        $gateway = new EmrSessionGateway($this->clientFactory(), $this->log(), $http);

        self::assertSame('opp-both-levels', $gateway->view($nested)['opportunity_id']);
        self::assertSame('opp-both-levels', $gateway->view($flat)['opportunity_id']);
    }

    public function testViewReportsAnUnknownSessionAsNotExistingRatherThanAsAFailure(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [200, ['success' => true, 'data' => []]]]);
        $snapshot = (new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->view('sess-unknown-000000');

        self::assertFalse($snapshot['exists']);
        self::assertNull($snapshot['opportunity_id']);
        self::assertSame([], $snapshot['events']);
    }

    public function testViewReturnsNullWhenTheReadItselfFails(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [503, ['success' => false]]]);

        self::assertNull((new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->view('sess-1234567890abcdef'));
    }

    public function testBlankCredentialsDegradeToNoSessionInsteadOfThrowing(): void
    {
        $clientId = $_ENV['ASTERMD_CLIENT_ID'] ?? null;
        $_ENV['ASTERMD_CLIENT_ID'] = '';
        try {
            self::assertNull((new EmrSessionGateway($this->clientFactory(), $this->log()))->create([], null, null));
        } finally {
            $_ENV['ASTERMD_CLIENT_ID'] = $clientId;
        }
    }

    public function testViewExtractsEventNamesFromEventNameAndBareStringTimelineShapesAndSkipsAnUnrecognisedOne(): void
    {
        $uuid = 'sess-shapes-0000001';
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [200, [
            'success' => true,
            'data' => [
                $uuid => [
                    'data' => ['opportunity_id' => 'opp-shapes-1'],
                    'events' => [
                        ['event' => 'visit_page'],
                        ['name' => 'intake_initiated'],
                        'checkout_visited',
                        ['unrecognised_key' => 'order_placed'],
                    ],
                ],
            ],
        ]]]);

        $snapshot = (new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->view($uuid);

        self::assertSame(['visit_page', 'intake_initiated', 'checkout_visited'], $snapshot['events']);
    }

    /**
     * A wrong extractor path and a genuinely fresh session produce the same
     * snapshot, so the only way an operator can tell them apart is a line in
     * the log saying the read-model yielded nothing recognisable.
     */
    public function testAnExistingSessionWhoseReadModelYieldsNothingIsRecordedForOperators(): void
    {
        $uuid = 'sess-empty-00000001';
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [200, [
            'success' => true,
            'data' => [$uuid => ['data' => ['something_unexpected' => 'x'], 'events' => []]],
        ]]]);

        $snapshot = (new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->view($uuid);
        $log = (string) file_get_contents($this->logFile);

        self::assertTrue($snapshot['exists']);
        self::assertStringContainsString('session.view_read_model_empty', $log);
        self::assertStringContainsString('"level":"info"', $log);
    }

    public function testAReadModelThatYieldsSomethingIsNotReportedAsEmpty(): void
    {
        $uuid = 'sess-nonempty-0001';
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/sessions/view' => [200, [
            'success' => true,
            'data' => [$uuid => ['data' => ['opportunity_id' => 'opp-1'], 'events' => []]],
        ]]]);

        (new EmrSessionGateway($this->clientFactory(), $this->log(), $http))->view($uuid);

        self::assertStringNotContainsString('session.view_read_model_empty', (string) @file_get_contents($this->logFile));
    }

    public function testTheNullGatewayNeitherCreatesNorFindsAnything(): void
    {
        $gateway = new NullSessionGateway();

        self::assertNull($gateway->create([], null, null));
        self::assertSame(['exists' => false, 'opportunity_id' => null, 'events' => []], $gateway->view('sess-1234567890abcdef'));
    }
}

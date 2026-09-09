<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Forms\EmrIntakeGateway;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

final class EmrIntakeGatewayTest extends TestCase
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

        $this->dir = sys_get_temp_dir() . '/emr-intake-gateway-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-intake-gateway-root-' . uniqid();
        mkdir($this->rootDir);
        $this->logFile = sys_get_temp_dir() . '/emr-intake-gateway-log-' . uniqid() . '.log';
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

    /** @return list<array{id: string, name: string, label: string, type: string, value: list<array<string, mixed>>}> */
    private function data(): array
    {
        return [[
            'id' => 'f-1',
            'name' => 'weight_loss_history',
            'label' => 'Have you taken a GLP-1 before?',
            'type' => 'radio',
            'value' => [['value' => 'yes', 'label' => 'Yes']],
        ]];
    }

    /** The one request that actually carried the submission, past the token call. */
    private function submissionRequest(FakeEmrHttpClient $http): \Psr\Http\Message\RequestInterface
    {
        $matches = array_values(array_filter(
            $http->requests,
            static fn ($request): bool => str_contains($request->getUri()->getPath(), '/intake-submissions/'),
        ));

        self::assertCount(1, $matches, 'exactly one submission call per record()');

        return $matches[0];
    }

    /**
     * The SDK's own docblock ties the event to the call: an `*Initiated` event
     * is what brings the server-side record into existence, so it must POST to
     * create rather than update a record that does not exist yet.
     */
    public function testAnInitiatedEventCreatesTheSubmission(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/intake-submissions/create' => [200, ['success' => true, 'data' => ['_id' => 'sub-1']]],
        ]);
        $gateway = new EmrIntakeGateway($this->clientFactory(), new OperatorLog($this->logFile), $http);

        $recorded = $gateway->record('sess-1', Event::IntakeInitiated, 'tf-1', $this->data(), ['page' => 1, 'total' => 5]);

        self::assertTrue($recorded);
        $request = $this->submissionRequest($http);
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/intake-submissions/create', $request->getUri()->getPath());
    }

    public function testAnInProgressEventUpdatesTheSubmissionForThatSession(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/intake-submissions/update' => [200, ['success' => true, 'data' => ['_id' => 'sub-1']]],
        ]);
        $gateway = new EmrIntakeGateway($this->clientFactory(), new OperatorLog($this->logFile), $http);

        $recorded = $gateway->record('sess-1', Event::IntakeInProgress, 'tf-1', $this->data(), ['page' => 2, 'total' => 5]);

        self::assertTrue($recorded);
        $request = $this->submissionRequest($http);
        self::assertSame('PUT', $request->getMethod());
        self::assertStringContainsString('/intake-submissions/update/sess-1', $request->getUri()->getPath());
    }

    /**
     * A failed save degrades silently (`[20.2]`) and must never trap someone in
     * a form (`[10.26]`), so the failure reaches the operator log and nothing
     * else. What it may not carry is the provider's own message: the SDK builds
     * it from the response envelope's `message` field, this payload is clinical
     * answers, and key-based redaction cannot see inside a string (`[20.6]`).
     * The exception class and the status have to be enough.
     */
    public function testATransportFailureReportsFalseAndLogsWithoutTheProvidersMessage(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/intake-submissions/create' => [503, [
                'success' => false,
                'message' => 'Rejected answer "yes" to Have you taken a GLP-1 before?',
            ]],
        ]);
        $gateway = new EmrIntakeGateway($this->clientFactory(), new OperatorLog($this->logFile), $http);

        $recorded = $gateway->record('sess-1', Event::IntakeInitiated, 'tf-1', $this->data(), null);

        self::assertFalse($recorded);
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('intake.submission_failed', $log);
        self::assertStringContainsString('ApiException', $log);
        self::assertStringContainsString('"status":503', $log);
        self::assertStringNotContainsString('GLP-1', $log);
        self::assertStringNotContainsString('Rejected answer', $log);
    }
}

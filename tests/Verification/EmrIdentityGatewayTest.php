<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Verification;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use AsterMD\Storefront\Verification\EmrIdentityGateway;
use AsterMD\Storefront\Verification\NullIdentityGateway;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Every one of these runs against a fake PSR-18 transport. No test here makes
 * a network call, and the pass case could not be recorded even if it did:
 * twenty live calls on 2026-08-25 across nine identities and all three checks
 * returned `valid: false` without exception.
 *
 * The three outcome channels are tested separately on purpose. Collapsing them
 * is the defect this class exists to avoid: a 400 is our bug, a `valid: false`
 * body is the buyer's, and everything else is nobody's.
 */
final class EmrIdentityGatewayTest extends TestCase
{
    private const TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private const IDENTITY = [
        'firstName' => 'Ada',
        'lastName' => 'Lovelace',
        'phone' => '+14155550132',
        'ssn' => '123456789',
        'dob' => '1815-12-10',
        'email' => 'ada@example.com',
    ];

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

        $this->dir = sys_get_temp_dir() . '/emr-identity-gateway-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-identity-gateway-root-' . uniqid();
        mkdir($this->rootDir);
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

    public function testTheNullGatewayIsInconclusiveAndNeverPasses(): void
    {
        // A gateway that is switched off must not be able to satisfy a
        // blocking placement. "Nobody checked" is not "everybody passed".
        $gateway = new NullIdentityGateway();
        $verdict = $gateway->verify('crosscheck', self::IDENTITY);

        self::assertFalse($gateway->isEnabled());
        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertFalse($verdict->isPassed());
        self::assertSame('crosscheck', $verdict->check);
    }

    public function testARefusedIdentityIsAFailedVerdict(): void
    {
        $http = $this->identityRoute(200, ['success' => true, 'data' => [
            'provider' => 'vouched',
            'check' => 'crosscheck',
            'valid' => false,
            'basis' => 'score',
            'score' => 0,
            'threshold' => 0.75,
            'reasons' => [['code' => 'address_invalid', 'message' => 'x'], ['code' => 'phone_invalid', 'message' => 'y']],
            'cached' => false,
        ]]);

        $verdict = $this->gateway($http)->verify('crosscheck', self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_FAILED, $verdict->status);
        self::assertSame(['address_invalid', 'phone_invalid'], $verdict->reasons);
        self::assertSame(0, $verdict->score);
        self::assertSame(0.75, $verdict->threshold);
    }

    public function testACorroboratedIdentityIsAPassedVerdict(): void
    {
        // Stubbed, because no input produces this on the live sandbox.
        $http = $this->identityRoute(200, ['success' => true, 'data' => [
            'provider' => 'vouched',
            'check' => 'crosscheck',
            'valid' => true,
            'basis' => 'score',
            'score' => 0.93,
            'threshold' => 0.75,
            'reasons' => [],
            'cached' => false,
        ]]);

        self::assertTrue($this->gateway($http)->verify('crosscheck', self::IDENTITY)->isPassed());
    }

    public function testAProviderThatDecidesNothingIsInconclusiveRatherThanAFailedIdentity(): void
    {
        $http = $this->identityRoute(200, ['success' => true, 'data' => [
            'check' => 'ssn_verify',
            'valid' => null,
            'basis' => 'match',
            'score' => null,
            'threshold' => null,
            'reasons' => [],
            'cached' => false,
        ]]);

        $verdict = $this->gateway($http)->verify('ssn_verify', self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertFalse($verdict->isFailed());
    }

    public function testAMalformedRequestIsInconclusiveRatherThanAFailedIdentity(): void
    {
        // Recorded: `dob_verify` without `phone` throws ApiException 400
        // "Phone number is required." That is our bug, and `[20.1]` forbids
        // presenting it to the buyer as a failed identity check.
        $captured = new CapturedLog();
        $http = $this->identityRoute(400, ['success' => false, 'message' => 'Phone number is required.']);

        $verdict = $this->gateway($http, $captured)->verify('dob_verify', self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertFalse($verdict->isFailed());
        self::assertFalse($verdict->isPassed());
    }

    public function testAMalformedRequestIsLoggedAtWarningBecauseItIsOurBugAndNotTheBuyers(): void
    {
        $captured = new CapturedLog();
        $http = $this->identityRoute(400, ['success' => false, 'message' => 'Phone number is required.']);

        $this->gateway($http, $captured)->verify('dob_verify', self::IDENTITY);

        $lines = $captured->eventsNamed('identity.request_rejected');
        self::assertCount(1, $lines);
        self::assertSame('warning', $lines[0]['level']);
        self::assertSame('dob_verify', $lines[0]['context']['check']);
        self::assertSame(400, $lines[0]['context']['status']);
        self::assertSame([], $captured->eventsNamed('identity.checked'));
    }

    public function testAnInactiveIntegrationIsInconclusiveAndNotAnAlarm(): void
    {
        // A 403 is the expected state until the grant lands, exactly as it is
        // for the email and address checks. An alert an operator is told to
        // expect is one they learn to ignore (`[20.10]`).
        $captured = new CapturedLog();
        $http = $this->identityRoute(403, [
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);

        $verdict = $this->gateway($http, $captured)->verify('crosscheck', self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        $lines = $captured->eventsNamed('identity.unavailable');
        self::assertCount(1, $lines);
        self::assertSame('info', $lines[0]['level']);
        self::assertSame(403, $lines[0]['context']['status']);
        self::assertSame([], $captured->eventsNamed('identity.request_rejected'));
    }

    public function testATransportFailureIsInconclusiveRatherThanEscaping(): void
    {
        // A check that cannot run must never present as a buyer failing
        // (`[20.1]`), and it must never take the journey down either.
        $captured = new CapturedLog();
        $unreachable = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Connection refused.');
            }
        };

        $verdict = $this->gateway($unreachable, $captured)->verify('crosscheck', self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertCount(1, $captured->eventsNamed('identity.unavailable'));
    }

    public function testAnUnknownCheckSlugIsInconclusiveAndNeverReachesTheProvider(): void
    {
        // A configuration error, not a buyer outcome. `config/verification.php`
        // is a file an operator edits, and a typo there must not silently mean
        // "everybody passes" or "everybody fails".
        $captured = new CapturedLog();
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE);

        $verdict = $this->gateway($http, $captured)->verify('passport_scan', self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertSame([], $http->requests);
        $lines = $captured->eventsNamed('identity.check_unknown');
        self::assertCount(1, $lines);
        self::assertSame('warning', $lines[0]['level']);
    }

    public function testTheCheckIsSelectedByTheEnumSoTheRequestCarriesTheSlugWeNamed(): void
    {
        $http = $this->identityRoute(200, ['success' => true, 'data' => ['valid' => false]]);

        $this->gateway($http)->verify('ssn_verify', self::IDENTITY);

        $body = json_decode((string) $http->requests[1]->getBody(), true);
        self::assertSame('ssn_verify', $body['slug']);
        self::assertSame('Ada', $body['firstName']);
    }

    public function testTheIdentityBeingCheckedNeverReachesTheOperatorLog(): void
    {
        // Absence assertion, and the one that matters most here. `[22.21]`
        // says identity documents must never enter debug logs, and this
        // payload carries an SSN and a date of birth. The SDK already
        // suppresses the request body from its own debug log; this asserts we
        // do not put it back on the way past.
        $captured = new CapturedLog();
        $http = $this->identityRoute(200, ['success' => true, 'data' => [
            'check' => 'ssn_verify',
            'valid' => false,
            'basis' => 'match',
            'reasons' => [['code' => 'ssn_mismatch', 'message' => 'x']],
        ]]);

        $this->gateway($http, $captured)->verify('ssn_verify', self::IDENTITY);

        $contents = $captured->contents();
        foreach (['123456789', '1815-12-10', 'Lovelace', 'ada@example.com', '4155550132'] as $secret) {
            self::assertStringNotContainsString($secret, $contents);
        }
        self::assertNotSame([], $captured->eventsNamed('identity.checked'));
    }

    public function testAProviderErrorMessageNeverReachesTheOperatorLogEither(): void
    {
        // Absence assertion. An API error can quote its own input straight
        // back, so the failure line carries the status and the exception class
        // and never the message.
        $captured = new CapturedLog();
        $http = $this->identityRoute(400, [
            'success' => false,
            'message' => 'The SSN 123456789 supplied for Ada Lovelace is malformed.',
        ]);

        $this->gateway($http, $captured)->verify('ssn_verify', self::IDENTITY);

        self::assertStringNotContainsString('123456789', $captured->contents());
        self::assertStringNotContainsString('Lovelace', $captured->contents());
    }

    public function testTheOutcomeIsRecordedInTheLogWithoutTheFieldsThatProducedIt(): void
    {
        $captured = new CapturedLog();
        $http = $this->identityRoute(200, ['success' => true, 'data' => [
            'check' => 'crosscheck',
            'valid' => false,
            'basis' => 'score',
            'score' => 0,
            'threshold' => 0.75,
            'reasons' => [['code' => 'address_invalid', 'message' => 'x']],
            'cached' => true,
        ]]);

        $this->gateway($http, $captured)->verify('crosscheck', self::IDENTITY);

        $lines = $captured->eventsNamed('identity.checked');
        self::assertCount(1, $lines);
        $context = $lines[0]['context'];
        self::assertSame('crosscheck', $context['check']);
        self::assertSame(JourneyState::VERIFICATION_FAILED, $context['status']);
        self::assertSame('score', $context['basis']);
        self::assertSame(['address_invalid'], $context['reasons']);
        self::assertTrue($context['cached']);
    }

    public function testTheGatewayReportsItselfEnabled(): void
    {
        self::assertTrue($this->gateway(new FakeEmrHttpClient(self::TOKEN_ROUTE))->isEnabled());
    }

    /** @param array<string, mixed> $body */
    private function identityRoute(int $status, array $body): FakeEmrHttpClient
    {
        return new FakeEmrHttpClient(self::TOKEN_ROUTE + ['/extensions/identity-verify' => [$status, $body]]);
    }

    private function gateway(ClientInterface $http, ?CapturedLog $captured = null): EmrIdentityGateway
    {
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return new EmrIdentityGateway(
            new ClientFactory(Config::load($this->dir), $this->rootDir),
            ($captured ?? new CapturedLog())->log,
            $http,
        );
    }
}

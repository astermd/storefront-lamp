<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrVerificationGateway;
use AsterMD\Storefront\Emr\NullVerificationGateway;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The 403 test is the one that matters. This storefront's credential is
 * refused for the whole `verification()` resource — verified against a token
 * minted immediately after a cache clear, so it is a genuine refusal and not a
 * stale permission snapshot. The gateway therefore has to treat a denial as an
 * ordinary Tuesday: null, an info line, and an order that still goes through.
 */
final class VerificationGatewayTest extends TestCase
{
    private const TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
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

        $this->dir = sys_get_temp_dir() . '/emr-verification-gateway-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-verification-gateway-root-' . uniqid();
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

    private function clientFactory(): ClientFactory
    {
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return new ClientFactory(Config::load($this->dir), $this->rootDir);
    }

    public function testTheNullGatewayReportsItselfDisabledAndDeterminesNothing(): void
    {
        // The bound gateway whenever features.emr_verification is off, which
        // is its default and always the case under the test suite.
        $gateway = new NullVerificationGateway();

        self::assertFalse($gateway->isEnabled());
        self::assertNull($gateway->emailIsDeliverable('ada@example.com'));
        self::assertNull($gateway->normaliseAddress('350 5th Avenue, New York, NY 10118'));
    }

    public function testADeliverableEmailComesBackTrue(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/email-verify' => [200, ['success' => true, 'data' => ['result' => 'valid']]],
        ]);

        self::assertTrue($this->gateway($http)->emailIsDeliverable('ada@example.com'));
    }

    public function testAnUndeliverableEmailComesBackFalse(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/email-verify' => [200, [
                'success' => true,
                'data' => ['result' => 'invalid', 'reason' => 'mailbox_not_found', 'code' => 5],
            ]],
        ]);

        self::assertFalse($this->gateway($http)->emailIsDeliverable('nobody@example.invalid'));
    }

    public function testAnUnknownVerdictIsInconclusiveRatherThanAFailure(): void
    {
        // The SDK is explicit that `unknown` also covers the provider being
        // rate-limited. Reading it as false would call a real buyer's address
        // wrong because someone else's traffic was heavy.
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/email-verify' => [200, ['success' => true, 'data' => ['result' => 'unknown']]],
        ]);

        self::assertNull($this->gateway($http)->emailIsDeliverable('ada@example.com'));
    }

    public function testARefusedCredentialAnswersNullAndIsLoggedAtInfo(): void
    {
        // The recorded live behaviour: the whole verification() resource is
        // 403 on this credential. Expected, therefore info -- an alert an
        // operator is told to expect is one they are trained to ignore
        // ([20.10]).
        $captured = new CapturedLog();
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/email-verify' => [403, [
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ]],
        ]);

        $answer = (new EmrVerificationGateway($this->clientFactory(), $captured->log, $http))
            ->emailIsDeliverable('ada@example.com');

        self::assertNull($answer);
        $line = $captured->eventsNamed('verification.unavailable')[0];
        self::assertSame('info', $line['level']);
        self::assertSame('email', $line['context']['check']);
        self::assertSame(403, $line['context']['status']);
    }

    public function testARefusedAddressCheckAnswersNullAndIsLoggedAtInfo(): void
    {
        $captured = new CapturedLog();
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/address-verify' => [403, [
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ]],
        ]);

        $answer = (new EmrVerificationGateway($this->clientFactory(), $captured->log, $http))
            ->normaliseAddress('350 5th Avenue, New York, NY 10118');

        self::assertNull($answer);
        $line = $captured->eventsNamed('verification.unavailable')[0];
        self::assertSame('info', $line['level']);
        self::assertSame('address', $line['context']['check']);
    }

    public function testNothingAboutTheBuyerReachesTheLogWhenACheckFails(): void
    {
        // An API error message can quote its own input, so the failure line
        // carries the exception class and the status and never the message.
        $captured = new CapturedLog();
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/email-verify' => [400, ['success' => false, 'message' => 'ada@example.com is not valid']],
        ]);

        (new EmrVerificationGateway($this->clientFactory(), $captured->log, $http))
            ->emailIsDeliverable('ada@example.com');

        self::assertStringNotContainsString('ada@example.com', $captured->contents());
    }

    public function testATransportErrorAnswersNullRatherThanEscaping(): void
    {
        // A verification outage degrades the form; it never takes the order
        // down with it ([20.1]).
        $captured = new CapturedLog();
        $unreachable = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Connection refused.');
            }
        };

        $gateway = new EmrVerificationGateway($this->clientFactory(), $captured->log, $unreachable);

        self::assertNull($gateway->emailIsDeliverable('ada@example.com'));
        self::assertNull($gateway->normaliseAddress('350 5th Avenue, New York, NY 10118'));
        self::assertCount(2, $captured->eventsNamed('verification.unavailable'));
    }

    public function testAValidatedAddressComesBackInTheProvidersOwnSpelling(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/address-verify' => [200, ['success' => true, 'data' => [
                'valid' => true,
                'formatted_address' => '350 5th Ave, New York, NY 10118, USA',
                'verdict' => ['validationGranularity' => 'PREMISE', 'addressComplete' => true],
            ]]],
        ]);

        self::assertSame(
            ['formatted_address' => '350 5th Ave, New York, NY 10118, USA'],
            $this->gateway($http)->normaliseAddress('350 5th Avenue, New York, NY 10118'),
        );
    }

    public function testAnAddressTheProviderRejectsNormalisesToNothing(): void
    {
        // There is no third return state to say "determined bad" apart from
        // "could not look", and neither may block an order, so both are null.
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/address-verify' => [200, ['success' => true, 'data' => [
                'valid' => false,
                'formatted_address' => '',
            ]]],
        ]);

        self::assertNull($this->gateway($http)->normaliseAddress('nowhere at all'));
    }

    public function testTheEmrGatewayReportsItselfEnabled(): void
    {
        // It is only ever bound when the feature flag is on, so a caller can
        // read isEnabled() instead of re-reading config.
        self::assertTrue($this->gateway(new FakeEmrHttpClient(self::TOKEN_ROUTE))->isEnabled());
    }

    private function gateway(ClientInterface $http): EmrVerificationGateway
    {
        return new EmrVerificationGateway($this->clientFactory(), (new CapturedLog())->log, $http);
    }
}

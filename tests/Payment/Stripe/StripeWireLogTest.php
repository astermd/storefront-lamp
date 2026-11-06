<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Stripe;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Stripe\StripeRefusingTransport;
use AsterMD\Storefront\Payment\Stripe\StripeWireLog;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * The Stripe transcript: what it writes, that it changes nothing about the
 * call, and that only the switch attaches it.
 */
final class StripeWireLogTest extends TestCase
{
    use ConfigVariant;

    private const array STRIPE_CHANNEL = [
        'channel' => ['id' => 'wire-log-test-channel', 'name' => 'Wire Log Test'],
        'payment_processor' => [
            'provider_category' => 'stripe',
            'name' => 'Wire Log Test Processor',
            'config' => ['api_key' => 'sk_test_not_a_real_key', 'publishable_key' => 'pk_test_not_a_real_key'],
        ],
    ];

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/stripe-wire-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testEachCallIsOneLineWithTheRequestAndStripesAnswer(): void
    {
        $answer = ['{"id":"pi_123","amount":20000}', 200, ['Request-Id' => 'req_abc']];

        $returned = (new StripeWireLog($this->answering($answer), $this->file))->request(
            'post',
            'https://api.stripe.com/v1/payment_intents',
            ['Authorization: Bearer sk_test_key'],
            ['amount' => 20000, 'currency' => 'usd'],
            false,
        );

        self::assertSame($answer, $returned, 'the SDK gets exactly what Stripe sent');

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true);

        self::assertSame('POST', $entry['request']['method']);
        self::assertSame('https://api.stripe.com/v1/payment_intents', $entry['request']['url']);
        self::assertSame(['amount' => 20000, 'currency' => 'usd'], $entry['request']['params']);
        self::assertSame(200, $entry['response']['status']);
        self::assertSame('req_abc', $entry['response']['request_id']);
        self::assertSame(['id' => 'pi_123', 'amount' => 20000], $entry['response']['body']);
    }

    public function testACallThatNeverReachedStripeIsWrittenAndStillFails(): void
    {
        $failing = new class implements ClientInterface {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                throw new ApiConnectionException('Could not connect to Stripe');
            }
        };

        try {
            (new StripeWireLog($failing, $this->file))
                ->request('get', 'https://api.stripe.com/v1/payment_intents', [], [], false);
            self::fail('the connection failure must reach the SDK');
        } catch (ApiConnectionException) {
        }

        $entry = json_decode((string) file_get_contents($this->file), true);
        self::assertStringContainsString('Could not connect to Stripe', $entry['response']['transport_error']);
    }

    public function testTheTransportIsUndecoratedUnlessTheSwitchIsOn(): void
    {
        self::assertInstanceOf(StripeRefusingTransport::class, $this->transport('false'));

        $on = $this->transport('true');
        self::assertInstanceOf(StripeWireLog::class, $on);
        self::assertInstanceOf(
            StripeRefusingTransport::class,
            (new \ReflectionProperty($on, 'inner'))->getValue($on),
            'the log wraps the fence, so the suite still cannot reach Stripe',
        );
    }

    /** @param array{0: string, 1: int, 2: array<string, string>} $answer */
    private function answering(array $answer): ClientInterface
    {
        return new class ($answer) implements ClientInterface {
            /** @param array{0: string, 1: int, 2: array<string, string>} $answer */
            public function __construct(private readonly array $answer)
            {
            }

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return $this->answer;
            }
        };
    }

    private function transport(string $wireLog): ?ClientInterface
    {
        $restore = $_ENV['WIRE_LOG'] ?? null;
        $_ENV['WIRE_LOG'] = $wireLog;

        try {
            $container = AppFactory::create(dirname(__DIR__, 3), [
                \PDO::class => new \PDO('sqlite::memory:'),
                \AsterMD\Storefront\Support\Config::class => $this->configWith([
                    'channel.generated' => self::STRIPE_CHANNEL,
                ]),
            ])->getContainer();
        } finally {
            if ($restore === null) {
                unset($_ENV['WIRE_LOG']);
            } else {
                $_ENV['WIRE_LOG'] = $restore;
            }
        }

        self::assertNotNull($container);
        $adapter = $container->get(PaymentAdapter::class);
        while (!property_exists($adapter, 'apiFactory')) {
            $adapter = (new \ReflectionProperty($adapter, 'inner'))->getValue($adapter);
        }
        $factory = (new \ReflectionProperty($adapter, 'apiFactory'))->getValue($adapter);

        return (new \ReflectionProperty($factory, 'transport'))->getValue($factory);
    }
}

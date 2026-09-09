<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\RefusingTransport;
use AsterMD\VrioClient\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The fence around the payment provider in the test environment.
 *
 * Every EMR port has a Null implementation that the test environment binds, so
 * a test cannot reach the EMR even by accident. Payment has no such port
 * substitution — the adapter is chosen from synced channel data rather than
 * from the environment (`[14.3]`) — so the container hands out a real provider
 * adapter under the suite. What stops a forgotten stub from placing a real
 * order is the transport beneath it, and that is what these assert.
 */
final class RefusingTransportTest extends TestCase
{
    public function testTheTransportRefusesToSend(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to send/');

        (new RefusingTransport())->send(new Request('POST', 'https://example.test/order', [], ''));
    }

    /**
     * The binding, not the class.
     *
     * Asserting that a refusing transport refuses proves nothing about the
     * application; what matters is that the container actually reaches for one
     * under the test environment. Decorators are peeled by looking for the
     * property rather than by naming a class, so a wrapper added later cannot
     * quietly turn this into an assertion about nothing.
     */
    public function testTheContainerGivesTheTestEnvironmentARefusingTransport(): void
    {
        $root = dirname(__DIR__, 2);

        // A deployment that has never synced a channel resolves no provider at
        // all — the registry answers with a null adapter, which reaches nothing
        // and so needs no fence. Skipping is the honest outcome rather than
        // asserting vacuously: a fresh clone ships no `channel.generated.php`,
        // and the suite it ships with must pass on it.
        if (!is_file($root . '/config/channel.generated.php')) {
            self::markTestSkipped('needs a synced channel for a provider adapter to resolve');
        }

        $adapter = AppFactory::create($root)->getContainer()?->get(PaymentAdapter::class);

        self::assertNotNull($adapter);

        $seen = 0;
        while (!property_exists($adapter, 'apiFactory')) {
            $adapter = (new \ReflectionProperty($adapter, 'inner'))->getValue($adapter);
            self::assertIsObject($adapter, 'a decorator chain that does not end in an object');
            self::assertLessThan(10, ++$seen, 'the decorator chain does not terminate');
        }

        $factory = (new \ReflectionProperty($adapter, 'apiFactory'))->getValue($adapter);
        $transport = (new \ReflectionProperty($factory, 'transport'))->getValue($factory);

        // The wire log wraps the transport rather than replacing it, so on a
        // machine with that switch on the refusal sits one layer down. What
        // matters is the innermost client — the only one that could reach the
        // provider — so peel to it rather than asserting on the outermost.
        $seen = 0;
        while ($transport !== null && !$transport instanceof RefusingTransport) {
            self::assertTrue(
                property_exists($transport, 'inner'),
                'the provider transport chain ends in something that can reach the network: ' . $transport::class,
            );
            $transport = (new \ReflectionProperty($transport, 'inner'))->getValue($transport);
            self::assertLessThan(10, ++$seen, 'the transport chain does not terminate');
        }

        self::assertInstanceOf(RefusingTransport::class, $transport);
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\CaptureOrderCommand;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Tests\Support\UnreadableOrdersPdo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `payment:capture` over recorded provider responses.
 *
 * Nothing here reaches the network. What the cases are about is which refusals
 * happen *before* one would: the local record is consulted first precisely so
 * that an obvious mistake costs nothing, and the boundary between "the row
 * justifies refusing" and "only the provider can say" is the whole design.
 */
final class CaptureOrderCommandTest extends TestCase
{
    use TempDatabase;

    private const SESSION = 'sess-capture-0000000';

    private \PDO $pdo;

    private function command(
        FakeVrioTransport $transport,
        ?\PDO $pdo = null,
        ?PaymentAdapter $adapter = null,
    ): CaptureOrderCommand {
        $pdo ??= $this->pdo;

        return new CaptureOrderCommand(
            fn (): PaymentAdapter => $adapter ?? new VrioAdapter(
                new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
                new VrioApiFactory($transport),
                (new CapturedLog())->log,
                shippingProfileId: 1,
            ),
            new OrderRepository(static fn (): \PDO => $pdo),
        );
    }

    private function seedOrder(string $reference, string $settlement): void
    {
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);
        (new OrderRepository(fn (): \PDO => $this->pdo))->insert(
            [
                'session_uuid' => self::SESSION,
                'provider_reference' => $reference,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 12000,
                'currency' => 'USD',
                'status' => 'placed',
                'settlement' => $settlement,
            ],
            [],
            [],
        );
    }

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    public function testAnAuthorizedOrderIsCapturedAndExitsZero(): void
    {
        $this->seedOrder('36727', 'authorize');
        $transport = new FakeVrioTransport();
        $transport->queue(200, json_encode(['order_id' => 36727], JSON_THROW_ON_ERROR));

        $tester = new CommandTester($this->command($transport));

        self::assertSame(0, $tester->execute(['reference' => '36727']));
        self::assertStringContainsString('Captured order 36727', $tester->getDisplay());
    }

    public function testAnOrderNoLocalRowKnowsAboutIsRefusedWithoutCallingTheProvider(): void
    {
        // Cheap protection against a mistyped reference. It is advisory rather
        // than authoritative -- see the unreadable case below -- but a reference
        // with no row at all is far more likely to be a typo than a lost write.
        $transport = new FakeVrioTransport();

        $tester = new CommandTester($this->command($transport));

        self::assertSame(1, $tester->execute(['reference' => '99999']));
        self::assertStringContainsString('No local order is recorded', $tester->getDisplay());
        self::assertSame([], $transport->requests);
    }

    public function testAnOrderThatWasChargedAtCheckoutHasNothingToCapture(): void
    {
        $this->seedOrder('34660', 'capture');
        $transport = new FakeVrioTransport();

        $tester = new CommandTester($this->command($transport));

        self::assertSame(1, $tester->execute(['reference' => '34660']));
        self::assertStringContainsString('was recorded as capture', $tester->getDisplay());
        self::assertSame([], $transport->requests);
    }

    public function testADatabaseFaultDoesNotStopACaptureTheProviderCouldStillHonour(): void
    {
        // The deliberate asymmetry. A hold expires on the acquirer's clock, and
        // letting somebody's reserved funds lapse because a local SELECT failed
        // would be the worse outcome by a wide margin -- the provider is the
        // authority on what it holds.
        $transport = new FakeVrioTransport();
        $transport->queue(200, json_encode(['order_id' => 36727], JSON_THROW_ON_ERROR));

        $tester = new CommandTester($this->command($transport, UnreadableOrdersPdo::alongside($this->pdo)));

        self::assertSame(0, $tester->execute(['reference' => '36727']));
        self::assertStringContainsString('could not be read; asking the provider anyway', $tester->getDisplay());
    }

    public function testARefusalFromTheProviderCarriesItsOwnCodeAndExitsOne(): void
    {
        $this->seedOrder('36727', 'authorize');
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-capture-unauthorized.json');

        $tester = new CommandTester($this->command($transport));

        self::assertSame(1, $tester->execute(['reference' => '36727']));
        self::assertStringContainsString('order_unauthorized', $tester->getDisplay());
    }

    public function testAProviderThatCannotCaptureSaysSoRatherThanCalling(): void
    {
        // A deployment with no provider configured, or one whose adapter has no
        // authorize support, has an order that should never have been
        // authorized. The fix is configuration, not a retry, and the message
        // has to say which.
        $this->seedOrder('36727', 'authorize');
        $transport = new FakeVrioTransport();

        $tester = new CommandTester($this->command($transport, adapter: new NullPaymentAdapter()));

        self::assertSame(1, $tester->execute(['reference' => '36727']));
        self::assertStringContainsString('does not support authorize-and-capture', $tester->getDisplay());
        self::assertSame([], $transport->requests);
    }
}

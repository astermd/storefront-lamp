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
use AsterMD\Storefront\Tests\Support\RecordingCaptureAdapter;
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

    /** @param list<array<string, mixed>> $lines */
    private function seedOrder(string $reference, string $settlement, array $lines = []): void
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
            $lines,
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

    public function testADatabaseFaultIsAStatedFailureRatherThanAHopefulCapture(): void
    {
        // This rule was the other way round until the second provider landed,
        // on the reasoning that a hold must not lapse because a local SELECT
        // failed. That assumed every provider can settle from the reference
        // alone. One cannot: a pre-authorized order there carries no line items
        // until the settling call supplies them, and re-reading it from the
        // provider returns the same empty list -- so `order_lines` is the only
        // place they still exist.
        //
        // Going on without them buys nothing. The provider refuses, the order
        // stays partial and the funds stay held, which is the same outcome as
        // not trying, reached more slowly and reported as a cart problem. A
        // stated failure an operator can act on is strictly better.
        $transport = new FakeVrioTransport();
        $transport->queue(200, json_encode(['order_id' => 36727], JSON_THROW_ON_ERROR));

        $tester = new CommandTester($this->command($transport, UnreadableOrdersPdo::alongside($this->pdo)));

        self::assertSame(1, $tester->execute(['reference' => '36727']));
        self::assertStringContainsString('could not be read', $tester->getDisplay());
        self::assertStringContainsString('the authorization is untouched', $tester->getDisplay());
        self::assertSame([], $transport->requests, 'nothing was sent on a guess');
    }

    public function testTheOrdersOwnLinesAreHandedToTheAdapter(): void
    {
        // The reason the local row is load-bearing rather than advisory: for one
        // provider these lines are the only thing that can settle the order.
        $this->seedOrder('36727', 'authorize', [
            ['slug' => 'nad-500', 'name' => 'NAD+ (500mg)', 'kind' => 'rx', 'provider_offer' => '459', 'provider_item' => '15271', 'unit_price_cents' => 12000, 'quantity' => 2],
        ]);
        $transport = new FakeVrioTransport();
        $transport->queue(200, json_encode(['order_id' => 36727], JSON_THROW_ON_ERROR));
        $adapter = new RecordingCaptureAdapter();

        $tester = new CommandTester($this->command($transport, adapter: $adapter));

        self::assertSame(0, $tester->execute(['reference' => '36727']));
        self::assertCount(1, $adapter->seen?->lines ?? []);
        self::assertSame('459', $adapter->seen?->lines[0]->providerOffer);
        self::assertSame('15271', $adapter->seen?->lines[0]->providerItem);
        self::assertSame(2, $adapter->seen?->lines[0]->quantity);
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


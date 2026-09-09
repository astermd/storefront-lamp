<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\ProviderPingCommand;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `provider:ping` over the recorded provider responses. Nothing here reaches
 * the network: a command that did would be smoke-testing the live sandbox
 * every time the suite ran.
 */
final class ProviderPingCommandTest extends TestCase
{
    public function testAReachableProviderPrintsItsCampaignAndExitsZero(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-campaign-items.json');

        $tester = new CommandTester(new ProviderPingCommand(fn (): PaymentAdapter => $this->vrio($transport)));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Provider: vrio', $tester->getDisplay());
        self::assertStringContainsString('campaign 147', $tester->getDisplay());
        self::assertStringContainsString('20 items', $tester->getDisplay());
    }

    public function testThePciPostureIsPrintedBesideTheConnectivityLine(): void
    {
        // `[15.15]`: an operator must know their scope before going live, and
        // the moment they are proving the provider is reachable is that moment.
        //
        // Asserted against the active adapter's own declaration rather than a
        // copy of one sentence, because what this command owes the operator is
        // whatever that adapter declares. A literal here pins the posture of
        // whichever provider happened to be shipped when the test was written,
        // and goes red for a posture correction that is the whole point of the
        // rule -- which is exactly what it did when the recorded provider
        // turned out to support reference-order reuse.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-campaign-items.json');
        $adapter = $this->vrio($transport);

        $tester = new CommandTester(new ProviderPingCommand(static fn (): PaymentAdapter => $adapter));
        $tester->execute([]);

        self::assertNotSame('', $adapter->capabilities()->pciPosture, 'an adapter with nothing to declare would make this vacuous');
        self::assertStringContainsString(
            'PCI posture: ' . $adapter->capabilities()->pciPosture,
            $tester->getDisplay(),
        );
    }

    public function testAnUnreachableProviderExitsOneAndSaysWhy(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queue(0, '', 'Could not resolve host: api.vrio.app');

        $tester = new CommandTester(new ProviderPingCommand(fn (): PaymentAdapter => $this->vrio($transport)));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('api.vrio.app', $tester->getDisplay());
    }

    public function testAnUnconfiguredChannelPrintsTheNullAdaptersRefusalRatherThanCrashing(): void
    {
        // A deployment whose channel has not been synced yet must still get an
        // answer from this command, and its posture line is still true.
        $tester = new CommandTester(new ProviderPingCommand(static fn (): PaymentAdapter => new NullPaymentAdapter()));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Provider: none', $tester->getDisplay());
        self::assertStringContainsString('No payment provider is configured', $tester->getDisplay());
        self::assertStringContainsString('PCI posture: No provider configured', $tester->getDisplay());
    }

    public function testAnAdapterThatCannotEvenBeBuiltIsReportedRatherThanThrown(): void
    {
        $tester = new CommandTester(new ProviderPingCommand(static function (): PaymentAdapter {
            throw new \InvalidArgumentException('The payment processor configuration carries no api_key.');
        }));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Provider ping failed', $tester->getDisplay());
    }

    private function vrio(FakeVrioTransport $transport): VrioAdapter
    {
        return new VrioAdapter(
            new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
            new VrioApiFactory($transport),
            (new CapturedLog())->log,
            shippingProfileId: 1,
        );
    }
}

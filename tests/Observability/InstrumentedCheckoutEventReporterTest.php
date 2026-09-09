<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Observability;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Observability\BoundaryTimer;
use AsterMD\Storefront\Observability\Instrumentation;
use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

/**
 * `[20.14]` over the four funnel events the checkout tests never reach.
 *
 * Every functional checkout test supplies its own bare reporter to watch what
 * the checkout *did*, which is the right thing for those tests and means the
 * decorator's placement half — the four events that carry money, a provider's
 * decline sentence and an upsell's display name — was running in production
 * with nothing asserting what it writes. The redaction was correct; it was just
 * unpinned, so an edit that added the provider's `$reason` to the log context
 * would have broken `[20.14]` and passed the suite.
 *
 * What is pinned here is that shape, not the plumbing: for each of the four,
 * the inner port still receives every argument untouched (the decorator may not
 * change what happens), the line carries counts and this application's own
 * identifiers, and the foreign text — a decline reason, a product's display
 * name — is not in the file at all.
 */
final class InstrumentedCheckoutEventReporterTest extends TestCase
{
    /** The provider's own sentence. Nothing this codebase composed. */
    private const string DECLINE_REASON = 'Declined: AVS mismatch for dana@example.test, card ending 4444';

    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/checkout-events-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testAPlacementIsTimedWithItsTotalAndAnOrderCountRatherThanAnOrderList(): void
    {
        $inner = new RecordingCheckoutEventReporter();
        $reporter = $this->reporter($inner);

        $reporter->orderPlaced('sess-1', Totals::of(19_900, null), 'card', ['ref-a', 'ref-b']);

        $line = $this->onlyLine();
        self::assertSame('external.emr.checkout_event', $line['event']);
        $context = $line['context'];
        self::assertSame('order_placed', $context['operation']);
        self::assertSame('sess-1', $context['session']);
        self::assertSame(19_900, $context['total_cents']);
        self::assertSame('card', $context['payment_method']);
        self::assertSame(2, $context['orders'], 'the count is what an outage asks about');
        self::assertGreaterThanOrEqual(0, $context['latency_ms']);
        self::assertArrayNotHasKey('order_references', $context);
        self::assertStringNotContainsString('ref-a', $this->logContents(), 'the references belong to the order rows');
    }

    public function testThePlacementCallReachesTheInnerPortWithEveryArgumentUntouched(): void
    {
        $inner = new RecordingCheckoutEventReporter();
        $totals = Totals::of(19_900, null);

        $this->reporter($inner)->orderPlaced('sess-1', $totals, 'card', ['ref-a', 'ref-b']);

        self::assertSame(
            [['orderPlaced', 'sess-1', $totals, 'card', ['ref-a', 'ref-b']]],
            $inner->calls,
        );
    }

    /**
     * `[20.14]`: the provider's `$reason` never reaches the log context.
     *
     * This is the assertion the absence was hiding. The decline sentence is
     * text a payment provider composed about our buyer's card and identity —
     * the example here carries an email address and four digits, which is the
     * mild end of what an acquirer will put in one — and neither defence at the
     * log sink can see inside it: {@see \AsterMD\Storefront\Support\OperatorLog}
     * redacts by key and cannot reach inside a string, and
     * {@see \AsterMD\Storefront\Support\CardScrubber} masks what passes a Luhn
     * check and nothing else. The reference and the total are this
     * application's own, and are the point.
     */
    public function testADeclineIsTimedWithoutTheProvidersSentence(): void
    {
        $inner = new RecordingCheckoutEventReporter();

        $this->reporter($inner)->orderDeclined(
            'sess-1',
            Totals::of(19_900, null),
            'card',
            'ref-declined',
            self::DECLINE_REASON,
        );

        $context = $this->onlyLine()['context'];
        self::assertSame('order_declined', $context['operation']);
        self::assertSame(19_900, $context['total_cents']);
        self::assertSame('card', $context['payment_method']);
        self::assertSame('ref-declined', $context['reference'], 'our own reference is what makes it followable');
        self::assertArrayNotHasKey('reason', $context);

        $contents = $this->logContents();
        self::assertStringNotContainsString('AVS mismatch', $contents);
        self::assertStringNotContainsString('dana@example.test', $contents);
        self::assertStringNotContainsString('4444', $contents);
    }

    /**
     * The reason is dropped from the *line*, not from the call.
     *
     * The local trail records it, scrubbed, where an operator can look it up —
     * so a decorator that swallowed it on the way through would lose the only
     * copy anybody can follow.
     */
    public function testTheDeclineReasonStillReachesTheInnerPortInFull(): void
    {
        $inner = new RecordingCheckoutEventReporter();
        $totals = Totals::of(19_900, null);

        $this->reporter($inner)->orderDeclined('sess-1', $totals, 'card', 'ref-declined', self::DECLINE_REASON);

        self::assertSame(
            [['orderDeclined', 'sess-1', $totals, 'card', 'ref-declined', self::DECLINE_REASON]],
            $inner->calls,
        );
    }

    /**
     * An accepted upsell is logged by slug, and the display name is not logged.
     *
     * The slug is this application's own catalog key and is what a funnel
     * report groups by; `$name` is copy, it is what `[20.11]`'s conversion
     * figures would drift against the moment marketing edited it, and it is one
     * of the two arguments here that came from outside the code.
     */
    public function testAnAcceptedUpsellIsLoggedBySlugAndNotByItsDisplayName(): void
    {
        $inner = new RecordingCheckoutEventReporter();

        $this->reporter($inner)->upsellAccepted('sess-1', 'sermorelin', 'Sermorelin — 3 month supply');

        $context = $this->onlyLine()['context'];
        self::assertSame('upsell_accepted', $context['operation']);
        self::assertSame('sermorelin', $context['slug']);
        self::assertArrayNotHasKey('name', $context);
        self::assertStringNotContainsString('3 month supply', $this->logContents());

        self::assertSame(
            [['upsellAccepted', 'sess-1', 'sermorelin', 'Sermorelin — 3 month supply']],
            $inner->calls,
        );
    }

    /**
     * A bump carries its verdict, and both verdicts are recorded.
     *
     * `false` is the interesting half and the one a filter loses: an offered
     * bump nobody takes and a bump that was never offered are different
     * conversion facts, and only the first produces a line.
     */
    public function testABumpIsLoggedWithItsVerdictInBothDirections(): void
    {
        $inner = new RecordingCheckoutEventReporter();
        $reporter = $this->reporter($inner);

        $reporter->orderBump('sess-1', 'zinc', true);
        $reporter->orderBump('sess-1', 'zinc', false);

        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertSame('order_bump', $lines[0]['context']['operation']);
        self::assertSame('zinc', $lines[0]['context']['slug']);
        self::assertTrue($lines[0]['context']['accepted']);
        self::assertFalse(
            $lines[1]['context']['accepted'] ?? true,
            'a declined bump is a conversion fact, not an absence',
        );

        self::assertSame(
            [['orderBump', 'sess-1', 'zinc', true], ['orderBump', 'sess-1', 'zinc', false]],
            $inner->calls,
        );
    }

    /**
     * `[20.1]`: the decorator may not change what happens.
     *
     * A placement that has already taken the buyer's money must fail exactly
     * the way it failed before this class existed — same class, same message —
     * so the checkout's own policy is what decides, not the instrumentation's.
     */
    public function testAnInnerFailurePassesThroughUnchangedAndIsStillRecorded(): void
    {
        $inner = new class extends RecordingCheckoutEventReporter {
            public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
            {
                throw new \RuntimeException('the EMR did not answer');
            }
        };

        try {
            $this->reporter($inner)->orderPlaced('sess-1', Totals::of(19_900, null), 'card', ['ref-a']);
            self::fail('the exception must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('the EMR did not answer', $e->getMessage());
        }

        $context = $this->onlyLine()['context'];
        self::assertSame('exception', $context['outcome']);
        self::assertSame('RuntimeException', $context['failure']);
        self::assertStringNotContainsString(
            'the EMR did not answer',
            $this->logContents(),
            'a failure is named by its class, never by its message',
        );
    }

    private function reporter(CheckoutEventReporter $inner): CheckoutEventReporter
    {
        return (new Instrumentation(new BoundaryTimer(new OperatorLog($this->logFile))))
            ->checkoutEventReporter($inner);
    }

    private function logContents(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        $lines = [];

        foreach (explode("\n", trim($this->logContents())) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            $lines[] = $decoded;
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function onlyLine(): array
    {
        $lines = $this->lines();
        self::assertCount(1, $lines);

        return $lines[0];
    }
}

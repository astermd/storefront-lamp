<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use PHPUnit\Framework\TestCase;

/**
 * The one step that happens after the money may have moved.
 *
 * Three properties, and the buyer never sees any of them. A write that failed
 * must not reach them as a failure; it must reach an operator as something to
 * go and look at; and the message it arrives with must not carry a card,
 * because a driver quotes the statement it failed on and that statement was
 * built from a payment.
 */
final class PostChargeGuardTest extends TestCase
{
    public function testAFailedWriteIsLoggedRatherThanRaised(): void
    {
        // The buyer is holding a charged card. An error page in front of them
        // is the one outcome that leaves nobody with a record of the order.
        $log = new CapturedLog();

        (new PostChargeGuard($log->log))->run('record_order', '34788', static function (): never {
            throw new \RuntimeException('the orders table is gone');
        });

        $lines = $log->eventsNamed('checkout.post_charge_write_failed');

        self::assertCount(1, $lines);
        self::assertSame('error', $lines[0]['level']);
        self::assertSame('record_order', $lines[0]['context']['step']);
        self::assertSame('34788', $lines[0]['context']['reference']);
    }

    public function testTheProviderReferenceTravelsWithEveryFailureSoTheOrderCanBeFound(): void
    {
        // The reference is the only identifier every other party in this system
        // knows, so a reconciliation item without it is not one.
        $log = new CapturedLog();
        $guard = new PostChargeGuard($log->log);

        $guard->run('clear_cart', '34788', static fn (): never => throw new \RuntimeException('one'));
        $guard->run('report_order_placed', '34788', static fn (): never => throw new \RuntimeException('two'));

        foreach ($log->eventsNamed('checkout.post_charge_write_failed') as $line) {
            self::assertSame('34788', $line['context']['reference']);
        }
    }

    public function testADriverMessageQuotingTheStatementItFailedOnIsScrubbed(): void
    {
        // End to end, through the logger that actually ships: the guard scrubs
        // the message and the sink scrubs every value again, and what this
        // asserts is the property both exist for rather than either pass on its
        // own. The card is Luhn-valid on purpose — the scrubber only masks a
        // digit run that passes Luhn, so an invalid one would exercise nothing
        // and would pass against no scrubber at all.
        $log = new CapturedLog();

        (new PostChargeGuard($log->log))->run('record_order', '34788', static function (): never {
            throw new \RuntimeException('SQLSTATE[HY000]: near "4111111100084444 737": syntax error');
        });

        $line = (string) json_encode($log->eventsNamed('checkout.post_charge_write_failed')[0]);

        self::assertStringNotContainsString('4111111100084444', $line);
        self::assertStringContainsString(CardScrubber::MASK, $line);
        self::assertStringNotContainsString('4111111100084444', $log->contents(), 'nor on disk');
    }

    public function testAStepThatSucceedsSaysNothing(): void
    {
        // A guard that logged on the happy path would bury the failures it
        // exists to surface.
        $log = new CapturedLog();
        $ran = 0;

        (new PostChargeGuard($log->log))->run('clear_cart', '34788', static function () use (&$ran): mixed {
            ++$ran;

            return null;
        });

        self::assertSame(1, $ran);
        self::assertSame([], $log->lines());
    }

    public function testAnErrorRatherThanAnExceptionIsContainedToo(): void
    {
        // A `TypeError` from a shape that moved under a collaborator is the
        // same problem as a dead database: it is not the buyer's, and it must
        // not become their error page.
        $log = new CapturedLog();

        (new PostChargeGuard($log->log))->run('report_order_placed', null, static function (): never {
            throw new \TypeError('the reporter was handed the wrong shape');
        });

        $lines = $log->eventsNamed('checkout.post_charge_write_failed');

        self::assertCount(1, $lines);
        self::assertNull($lines[0]['context']['reference'], 'a declined order has no reference and still gets a line');
    }
}

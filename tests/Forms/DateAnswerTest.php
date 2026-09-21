<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\DateAnswer;
use PHPUnit\Framework\TestCase;

final class DateAnswerTest extends TestCase
{
    public function testWhatThePickerWritesBecomesAnIsoDate(): void
    {
        // `theme/js/datepicker.js` writes `MM / DD / YYYY`, spaces included.
        self::assertSame('2026-02-28', DateAnswer::iso('02 / 28 / 2026'));
    }

    public function testATypedDateIsAcceptedInTheShapesThePickerItselfAccepts(): void
    {
        // Its own parser takes one or two digits either side and any spacing
        // around the slashes, so a value it would put back in the box has to
        // convert the same way.
        self::assertSame('2026-02-28', DateAnswer::iso('02/28/2026'));
        self::assertSame('2026-02-08', DateAnswer::iso('2/8/2026'));
        self::assertSame('1990-12-01', DateAnswer::iso('  12 / 1 / 1990  '));
    }

    public function testMonthComesFirstBecauseThatIsWhatTheFieldAsksFor(): void
    {
        // The one transposition that silently produces a valid wrong date.
        // The placeholder says MM / DD / YYYY and the picker writes it that
        // way, so 03/04 is March the fourth.
        self::assertSame('2026-03-04', DateAnswer::iso('03/04/2026'));
    }

    public function testAValueAlreadyInIsoIsLeftExactlyAsItIs(): void
    {
        // The conversion runs at an outbound boundary that a resubmit reaches
        // twice; converting a converted value must not move the date.
        self::assertSame('2026-02-28', DateAnswer::iso('2026-02-28'));
    }

    public function testADateThatDoesNotExistIsNotInvented(): void
    {
        // 31 February would roll forward to March if it were handed to a
        // permissive parser, which turns a typo into a plausible wrong answer
        // on a clinical record.
        self::assertNull(DateAnswer::iso('02/31/2026'));
        self::assertNull(DateAnswer::iso('13/01/2026'));
    }

    public function testAnythingItCannotReadIsLeftForSomeoneElseToJudge(): void
    {
        self::assertNull(DateAnswer::iso(''));
        self::assertNull(DateAnswer::iso('sometime last year'));
        self::assertNull(DateAnswer::iso('28-02-2026'));
        self::assertNull(DateAnswer::iso('02/28/26'));
    }
}

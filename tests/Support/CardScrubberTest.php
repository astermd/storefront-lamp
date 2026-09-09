<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\CardScrubber;
use PHPUnit\Framework\TestCase;

final class CardScrubberTest extends TestCase
{
    /**
     * The number and the security code both go, and the amount stays.
     *
     * This assertion used to require `cvv=737` to survive, on the reasoning
     * that only the number was this pass's business. That reasoning was wrong
     * twice over: PCI DSS treats the security code as sensitive authentication
     * data, which may not be stored *at all* -- a stricter rule than the one
     * covering the PAN -- and inside a single `gateway_request_text` value the
     * key-based pass cannot reach it, so nothing else was ever going to.
     */
    public function testAPanEmbeddedInFreeTextIsMaskedAndSoIsTheSecurityCode(): void
    {
        $scrubbed = CardScrubber::scrub('type=1&card=4111111111111111&cvv=737&amount=120.00');

        self::assertStringNotContainsString('4111111111111111', (string) $scrubbed);
        self::assertStringNotContainsString('737', (string) $scrubbed);
        self::assertStringContainsString(CardScrubber::MASK, (string) $scrubbed);
        self::assertStringContainsString('type=1', (string) $scrubbed, 'the log still has to be readable');
        self::assertStringContainsString('amount=120.00', (string) $scrubbed);
    }

    public function testSpacedAndDashedNumbersAreMaskedToo(): void
    {
        self::assertStringNotContainsString('4111', (string) CardScrubber::scrub('card 4111 1111 1111 1111 ok'));
        self::assertStringNotContainsString('4111', (string) CardScrubber::scrub('card 4111-1111-1111-1111 ok'));
    }

    public function testANumberThatFailsLuhnIsLeftAlone(): void
    {
        // Order ids, transaction ids and timestamps are what an operator log is
        // for; masking them would make the log useless to read.
        self::assertSame('4111111111111112', CardScrubber::scrub('4111111111111112'));
        self::assertSame('order 34660 transaction 225477', CardScrubber::scrub('order 34660 transaction 225477'));
    }

    public function testARunTooShortOrTooLongToBeAPanIsLeftAlone(): void
    {
        self::assertSame('411111111111', CardScrubber::scrub('411111111111'), '12 digits is not a PAN');
        self::assertSame('41111111111111111119', CardScrubber::scrub('41111111111111111119'), '20 digits is not a PAN');
    }

    public function testTheScrubReachesEveryDepthOfAStructureAndKeepsItsShape(): void
    {
        $scrubbed = CardScrubber::scrub([
            'order_id' => 34660,
            'transaction' => [
                'gateway_request_text' => 'pan=4111111111111111',
                'notes' => ['see 4111 1111 1111 1111'],
                'approved' => true,
            ],
        ]);

        self::assertIsArray($scrubbed);
        self::assertSame(34660, $scrubbed['order_id']);
        self::assertTrue($scrubbed['transaction']['approved']);
        self::assertStringNotContainsString('4111111111111111', json_encode($scrubbed, JSON_THROW_ON_ERROR));
    }

    public function testAStringTheRegexEngineCannotFinishIsDroppedRatherThanPassedThrough(): void
    {
        // An unscanned string is precisely the one that must not be written
        // out, so a PCRE failure loses the value rather than the guarantee.
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $scrubbed = CardScrubber::scrub(str_repeat('card 4111 1111 1111 1111 ', 200));
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }

        self::assertSame(CardScrubber::UNSCANNABLE, $scrubbed);
    }

    public function testKeysAreLeftUntouched(): void
    {
        // Key-based redaction is OperatorLog's job; this pass is about values,
        // and rewriting a key would change the shape of what an operator reads.
        $scrubbed = CardScrubber::scrub(['gateway_request_text' => 'safe']);

        self::assertSame(['gateway_request_text' => 'safe'], $scrubbed);
    }

    public function testAPanNextToAShortDigitRunIsStillMasked(): void
    {
        // The shape a gateway echo actually has: the PAN, a separator, and the
        // next field. The whole run fails Luhn together, which must not be
        // read as "no card here".
        $cases = [
            'REQ 4111111100084444 737 APPROVED',
            '4111111100084444 7',
            '4111111100084444 12 30',
            'card 4111111100084444-737 end',
            'ref 9 4111111100084444',
            '378282246310005 1234',
        ];

        foreach ($cases as $case) {
            $scrubbed = (string) CardScrubber::scrub($case);
            self::assertStringNotContainsString('4111111100084444', $scrubbed, $case);
            self::assertStringNotContainsString('378282246310005', $scrubbed, $case);
            self::assertStringContainsString(CardScrubber::MASK, $scrubbed, $case);
        }
    }

    public function testADotIsASeparatorBecauseABuyerCanTypeOne(): void
    {
        // `PaymentCredential::card()` strips every non-digit, so a dotted
        // number is a live PAN by the time it reaches the provider.
        self::assertSame(CardScrubber::MASK, CardScrubber::scrub('4111.1111.0008.4444'));
        self::assertSame(CardScrubber::MASK, CardScrubber::scrub('4111-1111.0008 4444'));
    }

    public function testEveryPanLengthTheSchemesIssueIsCovered(): void
    {
        // 13 (old Visa), 14 (Diners), 15 (Amex), 16 (Visa/Mastercard) and 19
        // (co-branded Visa) are all issued lengths, and all five are Luhn-valid
        // here. 14 is the one the original length table missed.
        foreach (['4222222222222', '36227206271667', '378282246310005', '4111111100084444', '4111111111111111110'] as $pan) {
            self::assertSame(CardScrubber::MASK, CardScrubber::scrub($pan), $pan . ' is a card number');
        }
    }

    public function testAPanIsFoundWhereverItSitsInTheString(): void
    {
        self::assertSame(CardScrubber::MASK . ' declined', CardScrubber::scrub('4111111100084444 declined'));
        self::assertSame('pan ' . CardScrubber::MASK, CardScrubber::scrub('pan 4111111100084444'));
        self::assertSame(
            'The card ' . CardScrubber::MASK . ' was declined by the issuer.',
            CardScrubber::scrub('The card 4111111100084444 was declined by the issuer.'),
        );
        self::assertSame(
            CardScrubber::MASK . ' ' . CardScrubber::MASK,
            CardScrubber::scrub('4111111100084444 5555555555554444'),
        );
        self::assertSame(
            CardScrubber::MASK . ' ' . CardScrubber::MASK,
            CardScrubber::scrub('4111 1111 0008 4444 5555 5555 5555 4444'),
        );
    }

    public function testTheLongestReadingOfARunWins(): void
    {
        // `9103299344812586` is Luhn-valid, and so is its own last 13 digits.
        // Taking the shorter reading first would mask the 13 and leave the
        // leading `910` in the log -- three digits of a card number, and a
        // pointer to which card it was.
        self::assertSame(CardScrubber::MASK, CardScrubber::scrub('910 3299344812586'));
        self::assertSame(CardScrubber::MASK, CardScrubber::scrub('9103299344812586'));
    }

    public function testTheShortFieldsBesideAPanGoWithIt(): void
    {
        // A gateway request dumped as space-separated fields names nothing, so
        // the security code and the expiry beside the number can only be found
        // by where they sit. Anything long enough to be a reference an operator
        // needs is left readable.
        $scrubbed = (string) CardScrubber::scrub('REQ 4111111100084444 737 1230 APPROVED');

        self::assertStringNotContainsString('737', $scrubbed);
        self::assertStringNotContainsString('1230', $scrubbed);
        self::assertStringStartsWith('REQ ', $scrubbed);
        self::assertStringEndsWith(' APPROVED', $scrubbed);

        self::assertStringContainsString(
            '34660',
            (string) CardScrubber::scrub('order 34660 4111111100084444 737'),
            'the order reference is what the operator looks the charge up by',
        );
    }

    public function testCvvAndExpiryPairsAreMaskedWhereverTheyAppearInFreeText(): void
    {
        // Neither is Luhn-checkable, so the number pass cannot see them, and
        // inside one gateway string the key pass cannot either -- which is the
        // whole premise of a value-level scrub.
        $pairs = [
            'cvv=737' => '737',
            'CVV: 737' => '737',
            'cvc=737' => '737',
            'card_cvv=737' => '737',
            'card_cvc=737' => '737',
            'security_code=737' => '737',
            '"cvv":"737"' => '737',
            'ccexp=1230' => '1230',
            'exp=1230' => '1230',
            'card_exp_month=09' => '=09',
            'card_exp_year=2030' => '2030',
            'card_expiry=12/30' => '12/30',
            'expiry: 12-2030' => '12-2030',
        ];

        foreach ($pairs as $pair => $secret) {
            $scrubbed = (string) CardScrubber::scrub('sale ' . $pair . ' amount=120.00');

            self::assertStringNotContainsString($secret, $scrubbed, $pair . ' reached the log');
            self::assertStringContainsString(CardScrubber::MASK, $scrubbed, $pair);
            self::assertStringContainsString('amount=120.00', $scrubbed, $pair . ' took the amount with it');
        }
    }

    public function testTheSensitiveKeyRuleDoesNotSwallowUnrelatedText(): void
    {
        // `exp` is short enough to appear inside other words and other keys.
        self::assertSame('regexp=1230', CardScrubber::scrub('regexp=1230'));
        self::assertSame('experiment=1230', CardScrubber::scrub('experiment=1230'));
        self::assertSame('exp=1234567', CardScrubber::scrub('exp=1234567'), 'seven digits is not an expiry');
        self::assertSame('amount=120.00', CardScrubber::scrub('amount=120.00'));
        self::assertSame('card_last_four=4444', CardScrubber::scrub('card_last_four=4444'));
        self::assertSame('order 34660 transaction 225477', CardScrubber::scrub('order 34660 transaction 225477'));
    }

    public function testEveryEncodingAnExpiryActuallyTravelsInIsMasked(): void
    {
        // `MMYY` was the only unseparated form the first bound covered, and
        // `MMYYYY` is just as ordinary -- a six-digit expiry survived a rule
        // written to mask expiries.
        $pairs = [
            'ccexp=122030' => '122030',
            'ccexp=012030' => '012030',
            'ccexp=1230' => '1230',
            'card_expiry=12/30' => '12/30',
            'card_expiry=12/2030' => '12/2030',
            'card_expiry=12-2030' => '12-2030',
            'card_expiry=12.2030' => '2030',
            'card_expiry=12 / 30' => '12 / 30',
            'expiry=2030-12' => '2030-12',
            'card_exp_year=2030' => '2030',
            'cvv=737' => '737',
            'cid=1234' => '1234',
        ];

        foreach ($pairs as $pair => $secret) {
            $scrubbed = (string) CardScrubber::scrub('type=1&' . $pair . '&amount=120.00');

            self::assertStringNotContainsString($secret, $scrubbed, $pair . ' reached the log');
            self::assertStringContainsString('amount=120.00', $scrubbed, $pair . ' took the amount with it');
        }
    }

    public function testTheWholeGatewayRequestLineIsContained(): void
    {
        // The shape this pass exists for: number, expiry and security code in
        // one provider-formatted string, under keys no redaction list names.
        $scrubbed = (string) CardScrubber::scrub('type=1&ccnumber=4111111100084444&ccexp=122030&cvv=737');

        self::assertStringNotContainsString('4111111100084444', $scrubbed);
        self::assertStringNotContainsString('122030', $scrubbed);
        self::assertStringNotContainsString('737', $scrubbed);
        self::assertStringContainsString('type=1', $scrubbed);
    }

    /**
     * A correlation identifier must survive the scrub intact.
     *
     * An all-digit uuid is a dash-joined run of 32 digits, and any three of
     * its groups that add up to a PAN length are Luhn-valid about one time in
     * ten. A correlation identifier corrupted for a fraction of journeys is
     * worse than no identifier at all, because the fraction is invisible: the
     * log still looks like it has one.
     */
    public function testASessionUuidSurvivesTheScrubIntact(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';

        self::assertSame(['session' => $uuid], CardScrubber::scrubArray(['session' => $uuid]));
        self::assertSame(
            'journey resumed session=' . $uuid,
            CardScrubber::scrub('journey resumed session=' . $uuid),
        );
    }

    /**
     * The uuid shape is an exemption for that shape and nothing wider: a real
     * number quoted beside a uuid, in the free text this pass exists for, is
     * still masked.
     */
    public function testAPanBesideAUuidInProviderTextIsStillMasked(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $scrubbed = (string) CardScrubber::scrub(
            'gateway_request_text=ref:' . $uuid . ' ccnumber=4111111100084444 approved',
        );

        self::assertStringNotContainsString('4111111100084444', $scrubbed);
        self::assertStringContainsString(CardScrubber::MASK, $scrubbed);
        self::assertStringContainsString($uuid, $scrubbed, 'the reference is what makes the entry followable');
    }

    /**
     * The accepted cost of the uuid exemption, asserted rather than only
     * described.
     *
     * A PAN whose digits are deliberately laid out in the canonical 8-4-4-4-12
     * grouping passes the shape guard: sixteen of them as groups 1-3
     * (`8+4+4`), or as groups 4-5 (`4+12`). {@see CardScrubber::UUID} prices
     * this and takes it on purpose — that is not a shape provider text
     * produces, and the leak the pass was built for was a gateway echoing its
     * own request format, not one disguising it, while the alternative is a
     * correlation identifier silently corrupted for about one journey in ten.
     *
     * It is pinned because a priced cost that nothing asserts is
     * indistinguishable from one nobody knew about: the next reader to widen
     * the exemption gets a green suite either way, and this test plus
     * {@see self::testTheUuidExemptionIsForThatShapeAndNoShapeNear()} is what
     * makes the two directions of that edit visible.
     *
     * `4111111100084444` throughout, because it is Luhn-valid. The commonly
     * reeled-off `4111222233334444` is not, so a test written with it never
     * reaches the number pass at all and would pass against a scrubber that
     * did nothing.
     */
    public function testAPanDressedInTheUuidGroupingPassesAndThatCostIsDeliberate(): void
    {
        $pan = '4111111100084444';

        self::assertSame(
            CardScrubber::MASK,
            CardScrubber::scrub($pan),
            'the control: this number is Luhn-valid, so the pass does reach it',
        );

        // Groups 1-3 spell the PAN: 41111111 0008 4444.
        self::assertSame(
            '41111111-0008-4444-abcd-abcdefabcdef',
            CardScrubber::scrub('41111111-0008-4444-abcd-abcdefabcdef'),
        );

        // Groups 4-5 spell it: 4111 111100084444.
        self::assertSame(
            'abcdef01-abcd-abcd-4111-111100084444',
            CardScrubber::scrub('abcdef01-abcd-abcd-4111-111100084444'),
        );

        // And the second priced limit, one the uuid shape has no part in: an
        // unbroken run longer than any issued PAN is never windowed, because
        // spans are only ever tested at group boundaries.
        self::assertSame(
            '41111111000844447777777777777777',
            CardScrubber::scrub('41111111000844447777777777777777'),
        );
    }

    /**
     * The exemption is for the canonical shape and for nothing beside it.
     *
     * This is the half that has to hold if the one above is to stay affordable.
     * A uuid is a *fixed* five-group hex string of exactly 8-4-4-4-12
     * characters; anything with a group of the wrong width, or a group too few
     * or too many, is not one, and a PAN wearing it is masked exactly as it
     * would be in prose. Widening {@see CardScrubber::UUID} by so much as one
     * variable-length group is what would turn the priced cost into a leak, and
     * this is the assertion that refuses to let that happen quietly.
     */
    public function testTheUuidExemptionIsForThatShapeAndNoShapeNear(): void
    {
        $near = [
            'four groups' => '41111111-0008-4444-abcdefabcdef',
            'three groups' => '41111111-0008-4444',
            'six groups' => '41111111-0008-4444-abcd-abcdefabcde-f',
            'right group count, wrong widths' => '4111111-1000-8444-4abc-abcdefabcdef',
        ];

        foreach ($near as $why => $value) {
            // The mask, not the absence of the digits: every one of these
            // writes the PAN with separators in it, so asserting that the
            // unbroken sixteen digits are missing would hold even against a
            // scrubber that did nothing at all.
            self::assertStringContainsString(
                CardScrubber::MASK,
                (string) CardScrubber::scrub($value),
                $why . ' is not a uuid, so the number in it is not exempt',
            );
        }
    }
}

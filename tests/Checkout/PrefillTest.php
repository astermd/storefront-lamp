<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\Prefill;
use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\TeleformMetadata;
use PHPUnit\Framework\TestCase;

final class PrefillTest extends TestCase
{
    /**
     * The db_fields map as the live channel actually publishes it — an answer
     * name to a dotted record path, single-rooted on `opportunity`.
     */
    private function metadata(): TeleformMetadata
    {
        return new TeleformMetadata(
            id: 'tf-1',
            identifier: 'acct/org/intake_13_1787491225.json',
            type: 'intake',
            layout: 'five-column',
            dbFields: [
                'first_name' => 'opportunity.first_name',
                'last_name' => 'opportunity.last_name',
                'email_address' => 'opportunity.email',
                'phone_number' => 'opportunity.phone',
                'date_of_birth' => 'opportunity.dob',
                'street_address' => 'opportunity.address.line1',
                'city_name' => 'opportunity.address.city',
                'state_code' => 'opportunity.address.state',
                'zip' => 'opportunity.address.postal_code',
                'bmi_measurement' => 'opportunity.clinical.bmi',
            ],
        );
    }

    public function testEveryCheckoutFieldTheIntakeAlreadyAnsweredIsPrefilled(): void
    {
        // [13.5b]: the same mapping that builds the EMR record, read backwards.
        // One mapping, not two -- so a form that renames its question keeps
        // prefilling, because the record path did not move.
        $answers = AnswerSet::fromArray([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email_address' => 'ada@example.com',
            'phone_number' => '2125551234',
            'street_address' => '350 5th Avenue',
            'city_name' => 'New York',
            'state_code' => 'NY',
            'zip' => '10118',
        ]);

        $prefilled = Prefill::from($this->metadata(), $answers, []);

        self::assertSame('Ada', $prefilled['first_name']);
        self::assertSame('Lovelace', $prefilled['last_name']);
        self::assertSame('ada@example.com', $prefilled['email']);
        self::assertSame('2125551234', $prefilled['phone']);
        self::assertSame('350 5th Avenue', $prefilled['address_line']);
        self::assertSame('New York', $prefilled['city']);
        self::assertSame('NY', $prefilled['territory']);
        self::assertSame('10118', $prefilled['postal_code']);
    }

    public function testAnswersWithNoCheckoutFieldAreIgnored(): void
    {
        // The clinical BMI has a record path but no box on the checkout page.
        $prefilled = Prefill::from($this->metadata(), AnswerSet::fromArray(['bmi_measurement' => '31.6']), []);

        self::assertArrayNotHasKey('bmi_measurement', $prefilled);
        self::assertArrayNotHasKey('bmi', $prefilled);
    }

    public function testStoredAnswersWinOverLocalWorkingState(): void
    {
        // [13.5c]: server-stored answers first, working state as fallback.
        $prefilled = Prefill::from(
            $this->metadata(),
            AnswerSet::fromArray(['email_address' => 'from-intake@example.com']),
            ['email' => 'from-working-state@example.com'],
        );

        self::assertSame('from-intake@example.com', $prefilled['email']);
    }

    public function testWorkingStateFillsWhatTheIntakeDidNotAnswer(): void
    {
        $prefilled = Prefill::from(
            $this->metadata(),
            AnswerSet::fromArray(['email_address' => 'ada@example.com']),
            ['city' => 'New York', 'postal_code' => '10118'],
        );

        self::assertSame('ada@example.com', $prefilled['email']);
        self::assertSame('New York', $prefilled['city']);
    }

    public function testAnEmptyAnswerDoesNotShadowTheWorkingState(): void
    {
        // Someone who skipped an optional question and then typed the value at
        // checkout must not have it wiped by a blank answer.
        $prefilled = Prefill::from(
            $this->metadata(),
            AnswerSet::fromArray(['phone_number' => '']),
            ['phone' => '2125551234'],
        );

        self::assertSame('2125551234', $prefilled['phone']);
    }

    public function testASkippedIntakeStillPrefillsFromWorkingState(): void
    {
        // [13.5g]: when intake was skipped, checkout collects everything, from
        // the same template. A decline that sent the buyer back must still
        // re-fill the form ([13.30]).
        $prefilled = Prefill::from(null, null, ['email' => 'ada@example.com', 'city' => 'New York']);

        self::assertSame('ada@example.com', $prefilled['email']);
        self::assertSame('New York', $prefilled['city']);
    }

    public function testAMappingWithNoRecognisedRecordPathIsSkippedRatherThanGuessed(): void
    {
        $metadata = new TeleformMetadata('tf-1', 'id', 'intake', 'five-column', [
            'favourite_colour' => 'opportunity.custom.colour',
        ]);

        self::assertSame([], Prefill::from($metadata, AnswerSet::fromArray(['favourite_colour' => 'blue']), []));
    }

    public function testATerritoryIsUppercasedOnTheWayIn(): void
    {
        // [13.1]: the territory is a two-letter code, uppercased.
        $prefilled = Prefill::from($this->metadata(), AnswerSet::fromArray(['state_code' => 'ny']), []);

        self::assertSame('NY', $prefilled['territory']);
    }

    public function testAMultiValuedAnswerIsFlattenedTheSameWayTheRecordMapperFlattensIt(): void
    {
        // Form authors routinely build a one-answer question out of a
        // multi-select control -- the live channel's own sex_at_birth is
        // authored that way. RecordMapper already flattens a single-element
        // list; prefill must agree or the two records diverge.
        $prefilled = Prefill::from($this->metadata(), AnswerSet::fromArray(['state_code' => ['NY']]), []);

        self::assertSame('NY', $prefilled['territory']);
    }
}

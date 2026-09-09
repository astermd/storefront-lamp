<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Upsell;

use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Upsell\UpsellQueue;
use AsterMD\Storefront\Upsell\Upsells;
use PHPUnit\Framework\TestCase;

/**
 * The journey's position in its upsell queue (`[16.3]`–`[16.6]`).
 *
 * Exhaustive on purpose. Everything here is a pure decision over two integers
 * and an array, and every off-by-one in it costs a buyer an offer or shows
 * them one they have already answered — neither of which any later layer can
 * detect, because both look like a queue behaving normally.
 */
final class UpsellQueueTest extends TestCase
{
    private CapturedLog $log;

    protected function setUp(): void
    {
        $this->log = new CapturedLog();
    }

    public function testTheCurrentOfferIsTheOneAtTheCursor(): void
    {
        $state = $this->journey(['wellness-pack', 'sleep-kit'], 0);

        $current = $this->queue()->current($state);

        self::assertNotNull($current);
        self::assertSame('wellness-pack', $current->key);
        self::assertSame(0, $state->upsellCursor, 'reading the current offer is not answering it');
        self::assertSame([], $state->upsellOutcomes);
    }

    public function testACursorPartWayThroughTheQueueReadsTheEntryItPointsAt(): void
    {
        $current = $this->queue()->current($this->journey(['wellness-pack', 'sleep-kit'], 1));

        self::assertNotNull($current);
        self::assertSame('sleep-kit', $current->key);
    }

    public function testAnEntryThatNoLongerResolvesIsSkippedAndTheNextIsReturned(): void
    {
        $state = $this->journey(['ghost', 'wellness-pack'], 0);

        $current = $this->queue()->current($state);

        self::assertNotNull($current);
        self::assertSame('wellness-pack', $current->key);
        self::assertSame(1, $state->upsellCursor, 'the skip moved the cursor, so a refresh does not re-skip');
        self::assertSame(JourneyState::UPSELL_SKIPPED, $state->upsellOutcomes['ghost']);
        self::assertNotSame([], $this->log->eventsNamed('upsell.skipped'));
    }

    public function testSeveralUnresolvableEntriesInARowAreAllSkippedInOneRequest(): void
    {
        // `[16.4]` is "skipped silently and the queue advances", not "one skip
        // per render": three dead entries in front of a live one must not cost
        // the buyer three redirects to reach it.
        $state = $this->journey(['ghost', 'ghost-two', 'ghost-three', 'sleep-kit'], 0);

        $current = $this->queue()->current($state);

        self::assertNotNull($current);
        self::assertSame('sleep-kit', $current->key);
        self::assertSame(3, $state->upsellCursor);
    }

    public function testAQueueOfNothingButUnresolvableEntriesIsSpent(): void
    {
        $state = $this->journey(['ghost', 'ghost-two'], 0);

        self::assertTrue($this->queue()->isSpent($state));
        self::assertNull($this->queue()->current($state));
        self::assertSame(2, $state->upsellCursor);
        self::assertSame(
            ['ghost' => JourneyState::UPSELL_SKIPPED, 'ghost-two' => JourneyState::UPSELL_SKIPPED],
            $state->upsellOutcomes,
        );
    }

    public function testACursorPastTheEndIsSpent(): void
    {
        $state = $this->journey(['wellness-pack'], 1);

        self::assertTrue($this->queue()->isSpent($state));
        self::assertNull($this->queue()->current($state));
    }

    public function testAnEmptyQueueIsSpent(): void
    {
        self::assertTrue($this->queue()->isSpent($this->journey([], 0)));
    }

    public function testACursorBeyondTheArrayLengthIsSpentRatherThanErroring(): void
    {
        // Durable state outliving a shortened queue is the ordinary case, not a
        // corruption: the queue is written once at checkout and read over many
        // later requests, and a redeploy can change what a key resolves to.
        $state = $this->journey(['wellness-pack'], 97);

        self::assertTrue($this->queue()->isSpent($state));
        self::assertNull($this->queue()->current($state));
        self::assertSame(97, $state->upsellCursor, 'a spent queue does not keep counting');
    }

    public function testANegativeCursorIsSpentRatherThanReOfferingTheFirstEntry(): void
    {
        // The fail-safe direction for a cursor that cannot be trusted is the
        // receipt. Re-reading the queue from the front would offer an add-on
        // the buyer may already have been charged for.
        $state = $this->journey(['wellness-pack'], -1);

        self::assertTrue($this->queue()->isSpent($state));
        self::assertNull($this->queue()->current($state));
    }

    public function testAnEntryThatIsNotAStringIsAdvancedPastRatherThanEndingTheQueue(): void
    {
        // The queue comes back out of a JSON column, so a non-string entry is
        // reachable. Treating it as the end would silently drop every offer
        // behind it.
        $state = new JourneyState();
        $state->upsellQueue = [null, 'wellness-pack'];
        $state->upsellCursor = 0;

        $current = $this->queue()->current($state);

        self::assertNotNull($current);
        self::assertSame('wellness-pack', $current->key);
    }

    public function testAdvanceRecordsTheOutcomeAgainstTheCurrentKey(): void
    {
        $state = $this->journey(['wellness-pack', 'sleep-kit'], 0);

        $this->queue()->advance($state, JourneyState::UPSELL_ACCEPTED);

        self::assertSame(['wellness-pack' => JourneyState::UPSELL_ACCEPTED], $state->upsellOutcomes);
        self::assertSame(1, $state->upsellCursor);
    }

    public function testAdvanceOnASpentQueueRecordsNothingAndDoesNotMoveTheCursor(): void
    {
        $state = $this->journey(['wellness-pack'], 1);

        $this->queue()->advance($state, JourneyState::UPSELL_ACCEPTED);

        self::assertSame([], $state->upsellOutcomes);
        self::assertSame(1, $state->upsellCursor, 'a cursor that runs away leaves the receipt unreachable');
    }

    public function testAdvanceRecordsAgainstTheKeyAtTheCursorRatherThanTheFirstUnanswered(): void
    {
        // The absence this pins: nothing here may look the current key up by
        // "the first entry with no outcome yet". A journey whose first offer
        // was skipped would then have its second answer written onto the first
        // key, and the buyer would be charged for an offer they were shown
        // once and answered never.
        $state = $this->journey(['ghost', 'wellness-pack'], 1);
        $state->upsellOutcomes = [];

        $this->queue()->advance($state, JourneyState::UPSELL_DECLINED);

        self::assertSame(['wellness-pack' => JourneyState::UPSELL_DECLINED], $state->upsellOutcomes);
        self::assertArrayNotHasKey('ghost', $state->upsellOutcomes);
    }

    public function testPositionCountsOnlyResolvableEntries(): void
    {
        // `ghost` sits between the two live offers, so the live ones are
        // "1 of 2" and "2 of 2" rather than "1 of 3" and "3 of 3".
        $queue = $this->queue();

        self::assertSame([1, 2], $queue->position($this->journey(['wellness-pack', 'ghost', 'sleep-kit'], 0)));
        self::assertSame([2, 2], $queue->position($this->journey(['wellness-pack', 'ghost', 'sleep-kit'], 2)));
    }

    public function testPositionOfASingleOfferQueueIsOneOfOne(): void
    {
        self::assertSame([1, 1], $this->queue()->position($this->journey(['wellness-pack'], 0)));
    }

    public function testPositionOfAQueueWithNothingLiveInItIsZeroOfZero(): void
    {
        self::assertSame([0, 0], $this->queue()->position($this->journey(['ghost'], 0)));
    }

    /** @param list<mixed> $keys */
    private function journey(array $keys, int $cursor): JourneyState
    {
        $state = new JourneyState();
        $state->upsellQueue = $keys;
        $state->upsellCursor = $cursor;

        return $state;
    }

    private function queue(): UpsellQueue
    {
        return new UpsellQueue($this->upsells(), $this->log->log);
    }

    private function upsells(): Upsells
    {
        return Upsells::fromConfig(
            ['upsells' => [
                'wellness-pack' => ['slug' => 'wellness-pack', 'offer_after' => ['semaglutide']],
                'ghost' => ['slug' => 'not-in-the-catalog', 'offer_after' => ['semaglutide']],
                'ghost-two' => ['slug' => 'also-not-in-the-catalog', 'offer_after' => ['semaglutide']],
                'ghost-three' => ['slug' => 'still-not-in-the-catalog', 'offer_after' => ['semaglutide']],
                'sleep-kit' => ['slug' => 'sleep-kit', 'offer_after' => ['ramelteon']],
            ]],
            new FakeCatalog([
                'wellness-pack' => [
                    'slug' => 'wellness-pack',
                    'name' => 'Wellness Pack',
                    'kind' => 'otc',
                    'price_cents' => 899,
                    'variants' => [
                        ['id' => 'wp-1m', 'price_cents' => 899, 'provider' => ['offer_id' => '412', 'product_id' => '3600']],
                    ],
                ],
                'sleep-kit' => [
                    'slug' => 'sleep-kit',
                    'name' => 'Sleep Kit',
                    'kind' => 'otc',
                    'price_cents' => 2400,
                    'variants' => [
                        ['id' => 'sk-1', 'price_cents' => 2400, 'provider' => ['offer_id' => '413', 'product_id' => '3601']],
                    ],
                ],
            ]),
            $this->log->log,
        );
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Completion;

use AsterMD\Storefront\Attribution\Attribution;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Completion\Completion;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Tests\Support\UnreadableOrdersPdo;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The completion step, against a real migrated database and a recording
 * reporter.
 *
 * The database is real because the receipt is built from the order rows and
 * `[16.10]`'s arithmetic is over them — a repository double would assert the
 * sum against itself. The reporter is a double because what is worth pinning
 * here is *when* each call happened relative to the wipe, and it resolves the
 * first-touch source through the same lazy closure the shipped reporter does
 * so that reading it too late shows up as a null rather than as nothing.
 */
final class CompletionTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private \PDO $pdo;

    private CapturedLog $log;

    private JourneyStore $journeys;

    private JourneyState $state;

    private RecordingCheckoutEventReporter $reporter;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->log = new CapturedLog();
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);

        $this->journeys = new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo));
        $this->state = $this->journeys->load(self::SESSION);
        $this->reporter = new RecordingCheckoutEventReporter(fn (): ?string => $this->state->attribution?->get('utm_source'));
    }

    public function testTheCompletionActionsFireExactlyOnceHoweverOftenTheReceiptIsLoaded(): void
    {
        // `[17.1]`, `[18.1]`: once per checkout, and the guard flag is what a
        // refresh runs into.
        $this->journeyHasPlaced(['34788']);
        $completion = $this->completion();

        $first = $completion->complete();
        $completion->complete();
        $third = $completion->complete();

        self::assertSame(1, $this->reporter->finalEventCount);
        self::assertSame(1, count($this->reporter->treatmentSyncBatches));
        self::assertSame($first->references, $third->references, 'a refresh still shows the order [4.17]');
        self::assertSame(12000, $third->paidTotalCents, 'and still shows the money');
    }

    public function testTheFinalEventReportsEveryOrderTheJourneyPlacedInOneBatch(): void
    {
        // `[17.2]`: the checkout order plus every accepted upsell, one call.
        $this->journeyHasPlaced(['34788', '34790', '34792']);

        $this->completion()->complete();

        self::assertSame([['34788', '34790', '34792']], $this->reporter->treatmentSyncBatches);
        self::assertSame(['34788', '34790', '34792'], $this->reporter->lastFinalEvent['references'] ?? null);
    }

    public function testTheFirstTouchSourceIsStillReadableWhenTheTreatmentSyncRuns(): void
    {
        // `[17.4]`. The wipe clears the attribution, so a sync that ran after
        // it would forward a null and nobody would notice.
        $this->journeyHasPlaced(['34788']);
        $this->state->attribution = Attribution::fromArray(['params' => ['utm_source' => 'google']]);

        $this->completion()->complete();

        self::assertSame('google', $this->reporter->lastSyncUtmSource);
        self::assertNull($this->state->attribution, 'and the wipe still happened');
    }

    public function testTheOpportunityReferenceReachesTheFinalEvent(): void
    {
        // `[17.6]`.
        $this->journeyHasPlaced(['34788']);
        $this->state->opportunityId = 'opp-1';

        $this->completion()->complete();

        self::assertSame('opp-1', $this->reporter->lastFinalEvent['opportunity_id'] ?? null);
    }

    public function testTheReceiptIsBuiltBeforeTheWipeAndSurvivesIt(): void
    {
        // `[4.16]` clears the buyer contact and `[4.17]` keeps the receipt, so
        // the shipping block only exists on the page if it was read first.
        $this->journeyHasPlaced(['34788']);
        $this->state->buyer = [
            'email' => 'buyer@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'address_line' => '123 Main St',
            'city' => 'New York',
            'postal_code' => '10001',
            'country' => 'US',
        ];

        $view = $this->completion()->complete();

        self::assertSame('buyer@example.com', $view->buyerEmail);
        self::assertSame('123 Main St', $view->shipping['address_line']);
        self::assertSame('Ada', $view->buyerFirstName);
        self::assertSame([], $this->state->buyer, 'the working copy is gone [4.16]');
        self::assertNotNull($this->state->receipt, 'the snapshot is not [4.17]');
    }

    public function testThePlacedOrderListSurvivesTheWipeSoTheReceiptStaysReachable(): void
    {
        // The cart is cleared the moment an order is placed, so this list is
        // the only thing that tells the funnel a completed journey bought
        // something. A wipe that took it would strand the buyer off their own
        // receipt on the next request.
        $this->journeyHasPlaced(['34788']);

        $this->completion()->complete();

        self::assertSame(['34788'], $this->state->placedOrders);
        self::assertTrue($this->state->isComplete());
    }

    public function testTheJourneyIsMarkedRetiredSoTheNextVisitStartsANewSession(): void
    {
        // `[4.18]`, and it has to survive the wipe: the request that clears the
        // cookie is a later one and has to find the decision already made.
        $this->journeyHasPlaced(['34788']);

        $this->completion()->complete();

        self::assertTrue($this->state->sessionRetired);
    }

    public function testAnEmptyBatchIsNotSentToASyncThatAnswersFourHundredForIt(): void
    {
        // Recorded: `sync(session, [])` answers 400, "No suborders found in any
        // of the provided orders".
        $this->journeyHasPlaced([]);

        $this->completion()->complete();

        self::assertSame([], $this->reporter->treatmentSyncBatches);
        self::assertSame(1, $this->reporter->finalEventCount, 'the funnel event still fires');
    }

    public function testTheFinalEventIsOrderDeclinedWhenNothingSucceeded(): void
    {
        // `[17.5]`: order-placed when at least one order succeeded,
        // order-declined when none did.
        $this->journeyHasPlaced([]);

        $this->completion()->complete();

        self::assertFalse($this->reporter->lastFinalEvent['placed'] ?? true);
        self::assertSame([], $this->reporter->lastFinalEvent['references'] ?? null);
        self::assertArrayHasKey('payment_method', (array) $this->reporter->lastFinalEvent);
        self::assertNull(((array) $this->reporter->lastFinalEvent)['payment_method'], 'a method nobody paid by is not invented');
    }

    public function testTheFinalEventNamesTheDeclinedReferencesWhenNothingWasCharged(): void
    {
        // `[17.5]`'s "or the declined ones when nothing succeeded". A placement
        // that failed the card still has a provider reference and still has a
        // row (`[13.26]`), and naming it is what lets support reconcile a
        // journey that bought nothing.
        $this->seedOrder('34789', status: 'declined', amountCents: 12000);
        $this->state->placedOrders = ['34789'];

        $view = $this->completion()->complete();

        self::assertFalse($this->reporter->lastFinalEvent['placed'] ?? true);
        self::assertSame(['34789'], $this->reporter->lastFinalEvent['references'] ?? null);
        self::assertSame(0, $this->reporter->lastFinalEvent['paid_total_cents'] ?? null, 'nobody was charged');
        self::assertSame([], $this->reporter->treatmentSyncBatches, 'and the EMR is told about nothing');
        self::assertSame(['34789'], $view->declinedReferences);
    }

    public function testTheFinalEventReportsTheChargedFigureRatherThanTheQuotedOne(): void
    {
        // The order rows hold the debit, so the one event `[18.1]` calls
        // once-per-checkout is the one that leaves the EMR holding it too.
        $this->seedOrder('34788', amountCents: 27000, discountCents: 1200, linePriceCents: 12000);
        $this->state->placedOrders = ['34788'];

        $this->completion()->complete();

        self::assertSame(12000, $this->reporter->lastFinalEvent['order_value_cents'] ?? null);
        self::assertSame(27000, $this->reporter->lastFinalEvent['paid_total_cents'] ?? null);
    }

    public function testANullJourneyStillRendersAReceiptRatherThanErroring(): void
    {
        // `[20.1]`: a session the storefront never saw start must not lock a
        // visitor out of a page, and the final event deliberately opens for an
        // unknowable journey rather than throwing.
        $completion = new Completion(
            new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo)),
            new OrderRepository(fn (): \PDO => $this->pdo),
            $this->reporter,
            new PostChargeGuard($this->log->log),
            $this->log->log,
            'USD',
        );

        $view = $completion->complete();

        self::assertSame([], $view->references);
        self::assertFalse($view->hasOrder());
        self::assertSame(0, $this->reporter->finalEventCount, 'there is nothing and nobody to report');
    }

    public function testEveryCompletionActionFailingStillRendersTheReceiptAndStillMarksItDone(): void
    {
        // `[20.1]` past the charge: the money moved before this page loaded, so
        // an exception here would show a 500 to someone holding a debited card.
        // Each action is individually wrapped, so the guard is still set --
        // `[17.1]`'s "exactly once" is the stronger promise, and a re-fire on
        // refresh would double-report the funnel event for the one action that
        // did succeed. What is left behind is `[21.8]`'s reconciliation item:
        // an order row whose `treatment_reference` is still null.
        $this->journeyHasPlaced(['34788']);
        $this->reporter->breakEverything();

        $view = $this->completion()->complete();

        self::assertSame(['34788'], $view->references, 'the buyer still sees their order');
        self::assertTrue($this->state->isComplete(), 'and is not asked to re-fire what already half-ran');
        self::assertSame(
            ['completion.final_event', 'completion.treatment_sync'],
            array_map(
                static fn (array $line): string => (string) ($line['context']['step'] ?? ''),
                $this->log->eventsNamed('checkout.post_charge_write_failed'),
            ),
            'each failure is its own reconciliation line',
        );
    }

    public function testAReferenceWithNoLocalRowCostsALineOnTheReceiptNotTheReceipt(): void
    {
        // The money moves before the local write, so a write that failed leaves
        // a charged buyer with no row. They are still entitled to a page.
        $this->journeyHasPlaced(['34788']);
        $this->state->placedOrders = ['34788', '34790'];

        $view = $this->completion()->complete();

        self::assertSame(['34788'], $view->references, 'the receipt names only what it could read back');
        self::assertSame(
            [['34788', '34790']],
            $this->reporter->treatmentSyncBatches,
            'but the EMR is told about both, because the journey knows it placed both',
        );
        self::assertSame(['34788', '34790'], $this->reporter->lastFinalEvent['references'] ?? null);
    }

    public function testAPlacementWhoseLocalWriteWasLostIsStillReportedAsPlaced(): void
    {
        // The post-charge `INSERT INTO orders` failed and everything else in
        // the database worked. `placedOrders` is the journey's own record of
        // what the provider accepted and the receipt is what could be read
        // back: they answer different questions, and only the second may be
        // blank. Deriving "did this journey place anything" from the rows told
        // the EMR that a charged order *declined*, overwriting the truthful
        // `order_placed` record — and then stamped `completedAt`, so no later
        // load could put it right.
        $this->state->recordPlacedOrder('34660');

        $view = $this->completion()->complete();

        $event = (array) $this->reporter->lastFinalEvent;
        self::assertTrue($event['placed'] ?? false, 'the journey placed 34660 and says so');
        self::assertSame(['34660'], $event['references'] ?? null, 'and names it, so the record can be reconciled');
        self::assertSame([['34660']], $this->reporter->treatmentSyncBatches, 'the treatment is synced too');
        self::assertSame([], $view->references, 'the receipt stays thin: it is only what could be read back');

        $stated = $this->log->eventsNamed('completion.placement_not_recorded');
        self::assertCount(1, $stated, 'and the gap between the two is a reconciliation line, not an inference');
        self::assertSame(['34660'], $stated[0]['context']['references'] ?? null);
    }

    public function testALostWriteNeverReportsADeclineAndNeverSyncsAnEmptyBatch(): void
    {
        // The absences that a fix reading the rows instead of the journey would
        // leave uncovered: an `order_declined` event for a charged order, a
        // `placed` claim carrying no reference for anyone to reconcile against,
        // and an empty treatment batch — which the endpoint answers 400 for.
        $this->state->recordPlacedOrder('34660');

        $this->completion()->complete();

        $event = (array) $this->reporter->lastFinalEvent;
        self::assertNotSame(false, $event['placed'] ?? null, 'a charged order is never reported as a decline');
        self::assertNotSame([], $event['references'] ?? null, 'a placed journey never reports an empty reference list');
        self::assertNotContains([], $this->reporter->treatmentSyncBatches, 'and no empty batch is ever sent');
        self::assertSame(['34660'], $this->state->placedOrders, 'the proof of purchase is untouched by completion');
        self::assertSame(
            [],
            ($this->state->receipt ?? [])['references'] ?? null,
            'and nothing unprovable is frozen into the receipt',
        );
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * A completion whose order reads all fail, which is a database blip rather
     * than a missing row — the distinction the whole class now turns on.
     */
    public function testAnUnreadableOrderDefersCompletionRatherThanReportingItDeclined(): void
    {
        // The failure this class is most likely to meet and least likely to
        // survive. A read that throws is not a row that is absent: swallowing
        // the two together reported a charged order to the EMR as *declined*
        // with a zero total, overwriting the record that said otherwise, and
        // froze a blank receipt durably — so no later load could put it right
        // and the reconciliation sweep could not find it either, because the
        // rows it looks for were already stamped.
        $this->journeyHasPlaced(['34660']);

        $view = $this->completionThatCannotReadOrders()->complete();

        self::assertSame(0, $this->reporter->finalEventCount, 'nothing is reported about a journey we cannot read');
        self::assertSame([], $this->reporter->treatmentSyncBatches);
        self::assertNull($this->state->completedAt, 'and the journey stays open, so the next load retries');
        self::assertNull($this->state->receipt, 'nothing false is frozen');
        self::assertSame(['34660'], $this->state->placedOrders, 'the proof of purchase is untouched');
        self::assertNotNull($view, 'the buyer still gets a page');
    }

    public function testOnceTheOrdersAreReadableAgainCompletionFiresNormally(): void
    {
        // The other half: deferring is only correct if it is recoverable.
        $this->journeyHasPlaced(['34660']);
        $this->completionThatCannotReadOrders()->complete();

        $view = $this->completion()->complete();

        self::assertSame(1, $this->reporter->finalEventCount);
        self::assertSame([['34660']], $this->reporter->treatmentSyncBatches);
        self::assertNotNull($this->state->completedAt);
        self::assertSame(['34660'], $view->references);
    }

    public function testAMissingRowStillCompletesBecauseThatIsADifferentFact(): void
    {
        // An absent row means a post-charge write was lost — we know what the
        // journey bought and simply cannot show every line. That is a thin
        // receipt, not an unknown one, and it must still complete: deferring on
        // it would leave the journey open forever, since the row is never
        // coming back.
        $this->state->recordPlacedOrder('34660');

        $view = $this->completion()->complete();

        self::assertSame(1, $this->reporter->finalEventCount);
        self::assertNotNull($this->state->completedAt, 'a lost write does not hold the journey open');
        self::assertTrue($this->state->sessionRetired, 'and the teardown still runs');
        self::assertNotNull($view);
    }

    public function testReachingTheReceiptRecordsItAsTheFurthestStepTheJourneyCompleted(): void
    {
        // `[21.7]`. Nothing past the questionnaires used to advance this field,
        // so the last thing a completed journey said about itself was whichever
        // form it had submitted.
        $this->journeyHasPlaced(['34788']);

        $this->completion()->complete();

        self::assertSame('receipt', $this->state->furthestStep);
    }

    public function testTheFurthestStepSurvivesTheCompletionWipe(): void
    {
        // The wipe clears everything the receipt was built from, and it runs
        // last. A step recorded after it would be recorded onto a journey
        // nothing else is left on.
        $this->journeyHasPlaced(['34788']);

        $this->completion()->complete();

        self::assertSame([], $this->state->cart, 'the wipe did run');
        self::assertSame('receipt', $this->state->furthestStep);
    }

    public function testADeferredCompletionDoesNotClaimTheJourneyReachedTheReceipt(): void
    {
        // The absence that matters: an unreadable order leaves the journey open
        // for the next load to retry, so nothing about it -- including how far
        // it got -- may be stamped as final.
        $this->journeyHasPlaced(['34660']);

        $this->completionThatCannotReadOrders()->complete();

        self::assertNull($this->state->furthestStep);
    }

    public function testAJourneyWithNoStateAtAllStillRendersWithoutRecordingProgress(): void
    {
        // `[20.1]`, `[20.8]`: there is nowhere to write and that is not an
        // error. The receipt is still served.
        $journeys = new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo));

        $view = (new Completion(
            $journeys,
            new OrderRepository(fn (): \PDO => $this->pdo),
            $this->reporter,
            new PostChargeGuard($this->log->log),
            $this->log->log,
            'USD',
        ))->complete();

        self::assertNotNull($view);
        self::assertSame(0, $this->reporter->finalEventCount);
    }
    private function completionThatCannotReadOrders(): Completion
    {
        $blind = UnreadableOrdersPdo::alongside($this->pdo);

        return new Completion(
            $this->journeys,
            new OrderRepository(static fn (): \PDO => $blind),
            $this->reporter,
            new PostChargeGuard($this->log->log),
            $this->log->log,
            'USD',
        );
    }

    private function completion(): Completion
    {
        return new Completion(
            $this->journeys,
            new OrderRepository(fn (): \PDO => $this->pdo),
            $this->reporter,
            new PostChargeGuard($this->log->log),
            $this->log->log,
            'USD',
        );
    }

    /** @param list<string> $references */
    private function journeyHasPlaced(array $references): void
    {
        foreach ($references as $reference) {
            $this->seedOrder($reference);
            $this->state->recordPlacedOrder($reference);
        }
    }

    private function seedOrder(
        string $reference,
        string $status = 'placed',
        int $amountCents = 12000,
        int $discountCents = 0,
        int $linePriceCents = 12000,
    ): void {
        (new OrderRepository(fn (): \PDO => $this->pdo))->insert(
            [
                'session_uuid' => self::SESSION,
                'provider_reference' => $reference,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => $amountCents,
                'currency' => 'USD',
                'status' => $status,
                'discount_cents' => $discountCents,
                'payment_method' => 'card',
                'card_last_four' => '4444',
            ],
            [[
                'slug' => 'tirzepatide',
                'name' => 'Tirzepatide',
                'kind' => 'rx',
                'unit_price_cents' => $linePriceCents,
                'quantity' => 1,
            ]],
            [],
        );
    }
}

/**
 * A recording {@see CheckoutEventReporter} that resolves the first-touch source
 * the way the shipped one does.
 *
 * The closure is the point: `EmrCheckoutEventReporter` is handed a
 * `\Closure(): ?string` rather than a value so it never holds journey state,
 * which means the source it forwards is whatever journey state says *at the
 * moment of the call*. A double that captured a value up front would record
 * `google` however late the sync ran, and the one ordering rule this class
 * exists to protect would go untested.
 */
final class RecordingCheckoutEventReporter implements CheckoutEventReporter
{
    public int $finalEventCount = 0;

    /** @var list<list<string>> */
    public array $treatmentSyncBatches = [];

    /** @var array<string, mixed>|null */
    public ?array $lastFinalEvent = null;

    public ?string $lastSyncUtmSource = null;

    private bool $broken = false;

    /** @param \Closure(): ?string $utmSource */
    public function __construct(private readonly \Closure $utmSource)
    {
    }

    /** Makes every method throw, the way a dead database or a dead EMR client would. */
    public function breakEverything(): void
    {
        $this->broken = true;
    }

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
    }

    /** @param list<string> $orderReferences */
    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
        $this->guard();
        $this->lastSyncUtmSource = ($this->utmSource)();
        $this->treatmentSyncBatches[] = $orderReferences;
    }

    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
    {
    }

    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
    {
    }

    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
    {
    }

    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
    {
    }

    /** @param list<string> $orderReferences */
    public function journeyCompleted(
        ?string $sessionUuid,
        bool $anyOrderPlaced,
        int $orderValueCents,
        int $paidTotalCents,
        ?string $paymentMethod,
        array $orderReferences,
        ?string $opportunityId,
    ): void {
        $this->guard();
        ++$this->finalEventCount;
        $this->lastFinalEvent = [
            'session' => $sessionUuid,
            'placed' => $anyOrderPlaced,
            'order_value_cents' => $orderValueCents,
            'paid_total_cents' => $paidTotalCents,
            'payment_method' => $paymentMethod,
            'references' => $orderReferences,
            'opportunity_id' => $opportunityId,
        ];
    }

    private function guard(): void
    {
        if ($this->broken) {
            throw new \RuntimeException('the reporting boundary is down');
        }
    }
}

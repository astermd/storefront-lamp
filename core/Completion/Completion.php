<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Completion;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Funnel\FurthestStep;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * What reaching the receipt does: the completion actions once, then teardown
 * (`[17.1]`–`[17.7]`).
 *
 * **The order of the steps is the whole class.** `wipeForCompletion()` clears
 * the attribution the treatment sync forwards (`[17.4]`) and the buyer contact
 * the receipt's shipping block is built from (`[4.16]`), so every action that
 * reads journey state runs before it and the wipe is the last thing that
 * happens. Nothing in `JourneyState` can enforce that — it is a method that
 * clears fields, and it cannot know who still needs them — so the ordering
 * lives here and its test is what holds it.
 *
 * **Fire-once is a durable flag, not a request-scoped one** (`[17.1]`,
 * `[4.15]`). A refresh of the receipt re-enters this class, finds
 * `JourneyState::isComplete()` true, and renders the stored snapshot without
 * re-firing anything. That is why the snapshot exists at all: by the second
 * load the cart, the buyer and the credential are gone, and rebuilding the page
 * from what is left would show a buyer less than they were shown a moment
 * earlier.
 *
 * **Every action is individually wrapped, and the guard is still set when one
 * fails.** By the time this runs the money has moved, so `[20.1]` forbids any
 * failure here from reaching the buyer — a 500 in front of someone holding a
 * debited card is the one outcome that leaves nobody with a record of the
 * order. Each action therefore runs inside {@see PostChargeGuard}, which logs
 * what could not be written as a reconciliation item and lets the rest proceed.
 * The guard flag is then set regardless, because `[17.1]`'s "exactly once" is
 * the stronger promise: a retry on the next load would re-fire whichever
 * actions *did* succeed, and a double-reported funnel event is worse than a
 * missing one. What a failure leaves behind is `[21.8]`'s reconciliation item —
 * an order row whose `treatment_reference` is still null, which is a query, not
 * a guess.
 *
 * The one failure that does leave the guard unset is the durable write of the
 * flag itself, and this class cannot see it: the three assignments below are
 * in-memory and cannot throw, and persisting them is
 * {@see \AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware}'s flush.
 * A flush that fails logs `journey.save_failed` and leaves `completed_at` null
 * in the row, so the next load retries the whole step — which is the intended
 * behaviour, reached through the persistence layer rather than through a branch
 * here.
 *
 * **No case id is rendered.** The EMR's treatment record carries a null
 * `provider_case_id` at the moment a treatment is created, so there is nothing
 * true to print; {@see CheckoutEventReporter::treatmentsSynced()} therefore
 * returns nothing and stays a report rather than a read (`[17.2]`). Reading a
 * case id back once the clinical side has assigned one is §29's work.
 */
final class Completion
{
    /**
     * The funnel step this class is the end of, named once rather than written
     * at the call site: `config/funnel.php` owns the vocabulary and a literal
     * buried in a method body is where a private spelling starts.
     */
    private const string RECEIPT_STEP = 'receipt';

    /**
     * @param OrderRepository $orders   read, never written: the rows are the durable record of what was
     *                                  charged and this step is a reader of them (`[16.10]`)
     * @param string          $currency the configured currency, used when no order row can name one
     */
    public function __construct(
        private readonly JourneyStore $journeys,
        private readonly OrderRepository $orders,
        private readonly CheckoutEventReporter $events,
        private readonly PostChargeGuard $guard,
        private readonly OperatorLog $log,
        private readonly string $currency,
    ) {
    }

    /**
     * Completes the journey if it has not been completed, and answers with the
     * page either way.
     *
     * Returns rather than redirects, and never throws: this is the last page of
     * a purchase, and the visitor is entitled to it whatever the storefront can
     * or cannot prove about them (`[20.1]`).
     */
    public function complete(): ReceiptViewModel
    {
        $state = $this->journeys->state();

        if ($state === null) {
            // A journey the server never saw start, or one whose session could
            // not be resolved at all. There is nothing to fire and nothing to
            // wipe, and inventing a session to report under is forbidden
            // (`[20.8]`).
            return ReceiptViewModel::empty($this->currency);
        }

        if ($state->isComplete()) {
            return ReceiptViewModel::fromReceipt(Receipt::fromArray($state->receipt ?? []));
        }

        // `[17.6]` and `[17.4]`: read before anything clears them. The
        // opportunity reference is taken here rather than at the call below so
        // that reordering the steps cannot silently turn it into a null, and
        // the first-touch source is resolved inside the reporter -- which is
        // exactly why the sync has to happen before the wipe rather than after.
        $opportunityId = $state->opportunityId;
        $sessionUuid = $this->journeys->sessionUuid();

        [$receipt, $readable, $unrecorded] = $this->receiptFor($state);
        $reference = $receipt->references[0] ?? null;

        // A journey whose orders could not be read is not a journey that
        // completed. Reporting it would tell the EMR a charged order declined
        // and overwrite the record that said otherwise, and freezing the
        // snapshot would make that permanent — the guard flag stops any later
        // load from putting it right, and the reconciliation sweep cannot find
        // it either, because the rows it looks for are already stamped.
        //
        // So nothing is reported, nothing is frozen and the journey is left
        // open. The buyer still gets their page, built from whatever was
        // readable, and the next load tries again.
        if (!$readable) {
            $this->log->error('completion.deferred', [
                'session' => $sessionUuid,
                'placed_orders' => count($state->placedOrders),
                'reason' => 'an order could not be read, so nothing about this journey is safe to report yet',
            ]);

            return ReceiptViewModel::fromReceipt($receipt);
        }

        $placed = $this->placedReferences($state, $receipt, $unrecorded);

        if ($unrecorded !== []) {
            // A reconciliation line, not a failure: the buyer's page is already
            // being served and the report below is about to name these anyway.
            // What it records is that the money figures under this journey are
            // the readable rows' and not the journey's — a total that is short
            // by an order is not a total anybody should reconcile against
            // without knowing that first.
            $this->log->warning('completion.placement_not_recorded', [
                'session' => $sessionUuid,
                'references' => $unrecorded,
                'reason' => 'the journey placed these and no local row holds them, so the reported totals exclude them',
            ]);
        }

        $this->guard->run('completion.final_event', $reference, fn (): mixed => $this->events->journeyCompleted(
            $sessionUuid,
            $placed !== [],
            $receipt->subtotalCents,
            $receipt->paidTotalCents,
            $placed === [] ? null : $receipt->paymentMethod,
            $placed !== [] ? $placed : $receipt->declinedReferences,
            $opportunityId,
        ));

        if ($placed !== []) {
            // Skipped for an empty batch: the endpoint answers 400 for one, so
            // the call would buy a warning line about nothing.
            $this->guard->run('completion.treatment_sync', $reference, fn (): mixed => $this->events->treatmentsSynced(
                $sessionUuid,
                $placed,
            ));
        }

        $state->receipt = $receipt->toArray();
        $state->completedAt = gmdate('c');
        $state->sessionRetired = true;

        // `[21.7]`: the last rung of the funnel, recorded here because this is
        // the only place that knows the journey actually reached it. It sits
        // with the three assignments above rather than after the wipe for the
        // reason the whole ordering exists — and with them it is in-memory work
        // that cannot throw, so `[20.1]`'s rule that nothing here may reach a
        // buyer holding a debited card still holds without a guard around it.
        //
        // Deliberately after the deferral branch above: a journey whose orders
        // could not be read has not completed, and stamping the final step on
        // one would say it had.
        FurthestStep::advance($state, self::RECEIPT_STEP);

        $state->wipeForCompletion();

        $this->log->info('completion.journey_completed', [
            'session' => $sessionUuid,
            'orders' => count($receipt->references),
            'declined' => count($receipt->declinedReferences),
        ]);

        // Built from the snapshot's own source rather than re-read from the
        // now-wiped state, so the first render and every later one are the same
        // page.
        return ReceiptViewModel::fromReceipt($receipt);
    }

    /**
     * The receipt for everything this journey placed.
     *
     * `placedOrders` is the reference list rather than a query by session,
     * because it is the journey's own record of what it bought and it survives
     * the wipe for exactly that reason. A reference with no row costs a line on
     * the receipt rather than the receipt: the money moves before the local
     * write, so a write that failed leaves a charged buyer with no row, and
     * that buyer is still owed a page.
     *
     * The whole read is wrapped for the same reason, one reference at a time,
     * so an unreadable table degrades to a thin receipt instead of a 500.
     */
    /**
     * @return array{0: Receipt, 1: bool, 2: list<string>} the receipt; whether every placed
     *                                                     reference could actually be read — false
     *                                                     means the figures below it are not this
     *                                                     journey's, merely what survived; and the
     *                                                     references the journey placed that no
     *                                                     local row holds
     */
    private function receiptFor(JourneyState $state): array
    {
        $rows = [];
        $complete = true;
        $unrecorded = [];

        foreach ($state->placedOrders as $reference) {
            try {
                $row = $this->orders->findByReference($reference);
                if ($row !== null) {
                    $rows[] = $row;
                } else {
                    // The read worked and there is nothing to find: the money
                    // moved before the local write and the write was lost. The
                    // journey still knows the provider accepted this reference,
                    // so it is missing from the receipt and present in what is
                    // reported.
                    $unrecorded[] = $reference;
                }
            } catch (\Throwable $error) {
                // **A read that threw is not a row that was absent**, and the
                // difference decides everything downstream. An absent row means
                // a post-charge write was lost and the receipt is thin; an
                // unreadable table means we do not know what this journey
                // bought, and every figure derived from it is a guess.
                //
                // Swallowing the two together is what turned a database blip
                // into a paid order reported to the EMR as declined, with a
                // blank receipt frozen durably so no later load could correct
                // it. Reported here, decided by the caller.
                $complete = false;
                $this->log->error('completion.order_unreadable', [
                    'reference' => $reference,
                    'session' => $this->journeys->sessionUuid(),
                    'reason' => CardScrubber::scrub($error->getMessage()),
                ]);
            }
        }

        return [Receipt::fromOrderRows($rows, $state->buyer, $this->currency), $complete, $unrecorded];
    }

    /**
     * What this journey placed, as opposed to what could be read back.
     *
     * **`placedOrders` and the receipt answer different questions, and only the
     * second may legitimately be blank.** `placedOrders` is the journey's own
     * record of the references the provider accepted, written the moment the
     * money moved; the receipt is built from the local rows, which are written
     * afterwards and can be lost. Deriving "did this journey place anything"
     * from the rows is what let one failed `INSERT INTO orders` report a
     * charged order to the EMR as *declined* — and because the EMR keeps one
     * checkout-event record per session, updated in place, that overwrote the
     * truthful `order_placed` record and the guard flag then made it permanent.
     *
     * A row that *was* read and says the order was not placed is not promoted:
     * the row is a better answer than the list for a reference it holds, and
     * `[16.12]`'s reason for keeping declines out of the placed list is exactly
     * that a decline reported as an order names something that does not exist.
     * Only a reference with no row at all is taken from the journey.
     *
     * The order is the journey's, so the reference that opened the checkout
     * stays first whatever the rows could produce.
     *
     * @param list<string> $unrecorded references the journey placed that no local row holds
     *
     * @return list<string>
     */
    private function placedReferences(JourneyState $state, Receipt $receipt, array $unrecorded): array
    {
        return array_values(array_filter(
            $state->placedOrders,
            static fn (string $reference): bool => in_array($reference, $receipt->references, true)
                || in_array($reference, $unrecorded, true),
        ));
    }
}

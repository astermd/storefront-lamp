<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Upsell;

use AsterMD\Storefront\Checkout\CheckoutAttempt;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\IdempotencyKey;
use AsterMD\Storefront\Checkout\OrderRecorder;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\FurthestStep;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\RequestContext;

/**
 * The post-purchase upsell step: what to render, and what happens when the
 * buyer answers (§16).
 *
 * **A declined upsell is not a failure the buyer has to act on** (`[16.11]`).
 * Everything below the charge therefore reports and advances rather than
 * refusing, and {@see self::accept()} returns a redirect on every path
 * including the unhappy ones. That is the opposite of
 * {@see \AsterMD\Storefront\Checkout\CheckoutService::submit()}, which
 * re-renders and stops, and the difference is deliberate: at checkout the
 * buyer has not bought anything yet and a stop is the only honest answer,
 * while here they have, and trapping them on an optional add-on would cost
 * them the order they already completed.
 *
 * **The duplicate guard is the checkout's, not a second one.** The provider has
 * no idempotency of its own — an identical payload posted twice creates two
 * orders and charges both — so an accepted upsell claims a row in the same
 * `checkout_attempts` table, marks it sent before the call, and settles it
 * after, with the same ownership token on every write. A second guard would be
 * a second thing to get wrong. It also reuses
 * {@see CheckoutAttempt::releasesKey()} rather than re-deriving which statuses
 * mean a charge cannot be ruled out; that list is a whitelist and a second copy
 * of it would fall behind the first.
 *
 * **There is no promotion and nothing is re-quoted** (`[13.17]`). The checkout
 * drops the promotion on success precisely so it cannot bleed into this flow,
 * so the envelope assembled here carries `promotionCode: null` and
 * `discountCents: 0`. An upsell is one line at the price the buyer was shown.
 *
 * **The whole shipping block is re-sent** (`[16.8]`). The recorded provider does
 * not resolve a country from the stored customer — omitting the block is
 * refused with an empty country in the message — which is why the buyer's
 * contact details have to survive until completion rather than being wiped at
 * checkout.
 *
 * Nothing here inspects the credential handle (`[15.13]`). It is read out of
 * journey state, handed to the adapter, and replaced by whatever the adapter
 * hands back — and only when it hands one back, because nulling a working
 * handle on the strength of a response that merely did not repeat it would
 * leave the next offer in the queue unchargeable for no reason.
 */
final class UpsellService
{
    /** `[13.8]`'s flood guard, applied to the one action here that costs money. */
    public const string RATE_LIMITED_NOTICE = 'Too many attempts from this connection. Please wait a few minutes and try again.';

    /**
     * An identical acceptance of this offer is still with the provider.
     *
     * Deliberately not "your payment failed" and not "your add-on was
     * declined". Nobody knows either: the card may already have been charged
     * for it, and the only honest thing to say is that submitting again is not
     * how to find out. The buyer is not stuck — the decline button on the same
     * page moves them on without touching the attempt at all.
     */
    public const string CONFIRMING_NOTICE = 'This add-on is still being confirmed. Please reload this page rather than submitting again — your card may already have been charged for it.';

    /**
     * Where the two buttons post.
     *
     * Constants rather than flow steps, for the reason
     * {@see \AsterMD\Storefront\Http\Controller\CheckoutController}'s own
     * `CHECKOUT_PATH` is one: a sub-action is a round trip to the page it was
     * posted from, not a funnel routing decision, so neither belongs in
     * `funnel.php` as a step a visitor could be sent to. The upsell *page*
     * itself does come from the flow definition, since that one is a step.
     */
    public const string ACCEPT_PATH = '/upsell/accept/';

    public const string DECLINE_PATH = '/upsell/decline/';

    /**
     * Shown when the offer's price moved between the render and the click.
     *
     * Phrased as a change to review rather than a failure to retry: nothing
     * went wrong and the buyer did nothing incorrect, so the message asks them
     * to look rather than apologising or telling them to try again.
     */
    public const string PRICE_MOVED_NOTICE = 'The price of this offer changed while you were deciding. Please review it before adding it to your order.';

    /**
     * Shown when the answer arrived for an offer that is no longer on screen.
     *
     * A stale tab rather than an error, so the buyer is put back on the offer
     * that is actually current instead of being charged for one they were not
     * looking at.
     */
    public const string OFFER_MOVED_NOTICE = 'That offer is no longer the one on screen. Here is your current offer.';

    /**
     * Shown when the answer arrived for an offer nothing recorded showing.
     *
     * Deliberately makes no claim about the price, because nothing here knows
     * that the price did anything. Saying it changed — which is what folding
     * this into the drift branch used to say — tells a buyer looking at a
     * correctly-rendered offer something false about their money.
     */
    public const string UNQUOTED_NOTICE = 'We could not confirm this offer. Please take another look and try again.';

    /** The step this service belongs to, and the only key it asks the flow for. */
    private const string UPSELL_STEP = 'upsell';

    /** @var \Closure(): string the current ISO-8601 timestamp */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): string)|null $clock injected so a test does not depend on the wall clock
     */
    public function __construct(
        private readonly JourneyStore $journeys,
        private readonly UpsellQueue $queue,
        private readonly PaymentAdapter $adapter,
        private readonly CheckoutAttemptRepository $attempts,
        private readonly DatabaseRateLimiter $limiter,
        private readonly OrderRecorder $orders,
        private readonly CheckoutEventReporter $events,
        private readonly PostChargeGuard $guard,
        private readonly FlowDefinition $flow,
        private readonly Config $config,
        private readonly OperatorLog $log,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('c');
    }

    /**
     * The one offer to present now, or null when there is none (`[16.3]`,
     * `[16.6]`).
     *
     * Null is the caller's instruction to send the visitor to the receipt, and
     * it covers three cases that are the same thing from the page's point of
     * view: a journey that does not exist, a queue that was never built, and a
     * queue that has been answered to the end.
     *
     * The offered event fires **once per upsell** (`[16.7]`), guarded on the
     * outcome map rather than on a per-request flag, because a refresh is not a
     * second offer and the buyer may reload this page any number of times.
     * The mark is written whether or not the report reached its destination,
     * for the reason the checkout's visit event is: retrying a report is worth
     * less than not double-counting one.
     */
    public function view(): ?UpsellViewModel
    {
        $state = $this->journeys->state();
        if ($state === null) {
            return null;
        }

        $upsell = $this->queue->current($state);
        if ($upsell === null) {
            return null;
        }

        if (!isset($state->upsellOutcomes[$upsell->key])) {
            $state->upsellOutcomes[$upsell->key] = JourneyState::UPSELL_OFFERED;
            $this->events->upsellOffered($this->journeys->sessionUuid(), $upsell->slug, $upsell->name);
        }

        // The figure the buyer is about to be shown is the figure they may be
        // charged, so it is frozen here rather than re-read when the answer
        // arrives. Rewritten on every render, which is what lets a refused
        // drift resolve itself: the buyer is sent back to this page, sees the
        // new price, and the next click agrees with what is on screen.
        $state->upsellQuotes[$upsell->key] = $upsell->priceCents;

        [$position, $total] = $this->queue->position($state);

        return new UpsellViewModel(
            key: $upsell->key,
            eyebrow: $upsell->eyebrow,
            headline: $upsell->headline,
            body: $upsell->body,
            bullets: $upsell->bullets,
            image: $upsell->image,
            productName: $upsell->name,
            priceCents: $upsell->priceCents,
            currency: $this->currency(),
            acceptLabel: $upsell->acceptLabel,
            declineLabel: $upsell->declineLabel,
            footnote: $upsell->footnote,
            acceptPath: self::ACCEPT_PATH,
            declinePath: self::DECLINE_PATH,
            position: $position,
            total: $total,
        );
    }

    /**
     * The buyer said yes: charge the stored instrument for this one offer and
     * move them along, whatever the provider says (`[16.8]`, `[16.11]`).
     *
     * The order of operations is {@see \AsterMD\Storefront\Checkout\CheckoutService::submit()}'s
     * steps 5–10, not a second discipline: refuse a flood before anything costs
     * money, claim the key, mark it sent before the call, place, settle, and run
     * every write after the call through {@see PostChargeGuard}. What differs is
     * only the ending — there is no branch here that leaves the buyer where they
     * were.
     *
     * A line the provider has no mapping for is deliberately **not** checked
     * here. The adapter refuses an order it cannot charge for before it reaches
     * the wire, and that refusal releases the key, so the one guard is the one
     * that also protects the checkout rather than a copy that could disagree
     * with it.
     *
     * **There is no stale-claim takeover, deliberately.** The checkout has one
     * because a buyer whose first submission died has to be able to buy the
     * thing they came for, so the cost of refusing them forever is the order
     * itself. Here the cost is one optional add-on, and the risk on the other
     * side is a second charge for it — the provider has no idempotency of its
     * own. So a claim nobody finished blocks *that offer* for good, and the
     * buyer is never stuck: declining is one click away and touches no attempt
     * row at all.
     *
     * @param ?string $answeringKey the offer key the page that posted this was showing. It can
     *                              only **confirm** which offer is being answered, never choose
     *                              one: the offer charged is always the queue's current, and a
     *                              key that disagrees refuses instead of selecting.
     *
     *                              Null is still accepted here and still skips the check, but no
     *                              production caller passes it any more: a request body that
     *                              names nothing is forwarded as a confirmation naming nothing,
     *                              which this method's own disagreement branch then refuses. The
     *                              parameter stays nullable because the decline path deliberately
     *                              does not require it -- declining costs an offer rather than
     *                              money, and a buyer with no way off this page would be worse
     *                              than a decline nobody confirmed.
     */
    public function accept(?RequestContext $context = null, ?string $answeringKey = null): UpsellResult
    {
        $state = $this->journeys->state();
        if ($state === null) {
            return new UpsellResult($this->receiptPath());
        }

        // 1. Before anything costs money. Its own bucket rather than the
        //    checkout's, because this is a different action with a different
        //    honest allowance: an upsell is one click and the buyer has no
        //    form to get wrong.
        if (!$this->limiter->allow('upsell.accept', $this->rateLimitIdentity($context))) {
            $this->log->info('upsell.rate_limited', []);

            return new UpsellResult($this->upsellPath(), self::RATE_LIMITED_NOTICE);
        }

        // 2. Which offer this is an answer to. None means the queue emptied
        //    under a replayed POST, and the receipt is where that goes.
        $upsell = $this->queue->current($state);
        if ($upsell === null) {
            return new UpsellResult($this->receiptPath());
        }

        // 2b. The answer has to be about the offer that is on screen. Two tabs
        //     are all it takes for it not to be: one renders offer A, the other
        //     answers it, the cursor moves, and the first tab's button now
        //     posts against offer B — charging the buyer for something they
        //     never saw, at a price they never saw either.
        //
        //     The check is a confirmation and never a selection. What is
        //     charged is still whatever the cursor points at; a key that
        //     disagrees is refused outright rather than honoured, so a
        //     hand-made POST cannot reach further down the queue than the
        //     buyer has been shown.
        if ($answeringKey !== null && $answeringKey !== $upsell->key) {
            $this->log->info('upsell.stale_answer', [
                'answering' => $answeringKey,
                'current' => $upsell->key,
            ]);

            return new UpsellResult($this->upsellPath(), self::OFFER_MOVED_NOTICE);
        }

        // 3. `[15.13]`. No handle means no charge is possible, which is a
        //    property of the deployment's provider rather than anything the
        //    buyer did -- so the offer is recorded as never put, the queue
        //    advances, and **nothing is said to the buyer**. A message about a
        //    capability their provider lacks would ask them to act on
        //    something they cannot affect.
        $credential = $state->reusableCredential();
        if ($credential === null || !$credential->isReusable()) {
            $this->log->info('upsell.no_credential', ['key' => $upsell->key]);
            $this->answered($state, JourneyState::UPSELL_SKIPPED);

            return new UpsellResult($this->onwardPath($state));
        }

        // 3b. The price the buyer was shown, and the only one they may be
        //     charged. A configuration deploy landing between the render and
        //     this click moves `$upsell->priceCents` under a page that is
        //     already on screen, and charging the new figure would take money
        //     nobody agreed to -- silently, because the expected total moves
        //     with it and leaves the discrepancy check nothing to compare.
        //
        //     A drift is refused rather than absorbed, for the reason the
        //     promotion re-quote refuses one: showing the stale figure charges
        //     the wrong number and charging the fresh one charges an unagreed
        //     one, so the only honest answer is to put the new price in front
        //     of the buyer and let them decide. The render above rewrites the
        //     quote, so the next click agrees with the screen.
        //
        //     An offer with no stored quote is one nothing rendered -- a POST
        //     with no GET before it -- and is refused the same way.
        $quotedCents = $state->upsellQuotes[$upsell->key] ?? null;

        // Two different facts, told apart because only one of them is about
        // money. A stored quote that disagrees with the live price is a real
        // drift and the buyer needs to see the new figure. **No** stored quote
        // is not a price change at all — it is an answer to an offer nothing
        // recorded showing, which a journey write that failed between the
        // render and the click produces just as readily as a hand-made POST.
        //
        // Folding them together told a buyer looking at a correctly-rendered
        // $8.99 offer that its price had changed, and fired a warning about a
        // drift that had not happened. Both refuse, and both recover on the
        // next render; they simply must not claim the same thing.
        if ($quotedCents === null) {
            $this->log->info('upsell.unquoted_answer', [
                'key' => $upsell->key,
                'live_cents' => $upsell->priceCents,
            ]);

            return new UpsellResult($this->upsellPath(), self::UNQUOTED_NOTICE);
        }

        if ($quotedCents !== $upsell->priceCents) {
            $this->log->warning('upsell.price_moved', [
                'key' => $upsell->key,
                'quoted_cents' => $quotedCents,
                'live_cents' => $upsell->priceCents,
            ]);

            return new UpsellResult($this->upsellPath(), self::PRICE_MOVED_NOTICE);
        }

        // 4. `[13.37]`. The offer, not the cart, is the material: the cart was
        //    cleared when the first order was placed.
        //
        //    **The price is deliberately not key material.** Two clicks on one
        //    offer are the same purchase whatever the catalog says in between,
        //    and keying on a figure that can move meant a deploy landing
        //    between them derived two keys and let both reach a provider with
        //    no idempotency of its own. What identifies the purchase is the
        //    journey and the offer; the price is an attribute of it, and is
        //    guarded above instead.
        $key = IdempotencyKey::forUpsell(
            $this->journeys->sessionUuid(),
            $this->sessionKey(),
            $upsell->key,
            $upsell->slug,
            $upsell->variantId,
        );

        // Read **once** and carried, because it is the ownership token every
        // later write against this row is conditional on. Reading the clock
        // again would produce a token one second out on the requests that
        // straddle a second boundary, which `markSent()` reads as a takeover
        // and `release()` silently ignores -- so the buyer would be told their
        // add-on was being confirmed, or the row would be left standing, at
        // random and only sometimes.
        $claimedAt = ($this->clock)();

        try {
            $claimed = $this->attempts->claim($key, $this->sessionKey(), $claimedAt);
        } catch (\Throwable $error) {
            // An unrecordable attempt refuses at checkout, because a charge
            // nobody can reconcile is worse than a retry. Here the same
            // reasoning ends differently: the offer is optional, so the safe
            // answer is not to charge at all and to let the buyer move on.
            $this->log->error('upsell.attempt_unrecordable', [
                'key' => $upsell->key,
                'reason' => CardScrubber::scrub($error->getMessage()),
            ]);
            $this->answered($state, JourneyState::UPSELL_SKIPPED);

            return new UpsellResult($this->onwardPath($state));
        }

        if (!$claimed) {
            return $this->duplicate($state, $key, $upsell);
        }

        $envelope = $this->envelope($state, $upsell, $key, $context);

        // 5. Written before the call, for the reason the checkout writes it
        //    there: a request that dies mid-charge leaves whatever this row
        //    last said, and a row still saying `claimed` invites a takeover to
        //    re-post an identical payload to a provider that would charge it
        //    again.
        try {
            $this->attempts->markSent($key, $claimedAt);
        } catch (\DomainException $error) {
            // The row is somebody else's now, so it is emphatically not this
            // request's to give back.
            $this->log->warning('upsell.attempt_taken_over', [
                'key' => $upsell->key,
                'idempotency_key' => $key,
                'reason' => $error->getMessage(),
            ]);

            return $this->duplicate($state, $key, $upsell);
        } catch (\Throwable $error) {
            $this->log->error('upsell.attempt_unrecordable', [
                'key' => $upsell->key,
                'reason' => CardScrubber::scrub($error->getMessage()),
            ]);
            $this->releaseQuietly($key, $claimedAt);
            $this->answered($state, JourneyState::UPSELL_SKIPPED);

            return new UpsellResult($this->onwardPath($state));
        }

        // 6. Everything below this line is past the point of no return.
        $outcome = $this->adapter->place($envelope, $credential);
        $this->settleAttempt($key, $claimedAt, $upsell, $outcome);

        // A challenge is not a decline, and treating it as one loses an order
        // the provider has already created. `response_code: 101` comes back
        // with an order id and a redirect: the money has not moved yet and may
        // still, so recording it as declined would leave a charge that appears
        // on no receipt, in no completion total and in no treatment sync --
        // and the buyer would never have been given the challenge to complete.
        //
        // The checkout stops the buyer and quotes the reference, because there
        // the challenge is the order. Here it is an optional add-on and
        // `[16.11]` says the queue advances either way, so the buyer moves on
        // -- but the reference is kept as a placed order so the receipt, the
        // totals and the EMR batch all know it exists. That is the difference
        // between an add-on nobody completed and an add-on nobody recorded.
        if ($outcome->state === PlacementOutcome::PENDING_ACTION) {
            return $this->pendingAction($state, $upsell, $envelope, $outcome, $credential);
        }

        return $outcome->isPlaced()
            ? $this->placed($state, $upsell, $envelope, $outcome, $credential)
            : $this->chargeDeclined($state, $upsell, $envelope, $outcome, $credential);
    }

    /**
     * The provider created the order and wants a challenge completed
     * (`[14.22]`).
     *
     * Recorded the way a placement is -- an order row, the reference on the
     * journey, and the queue advancing -- because every one of those exists to
     * make sure an order the provider knows about is an order this application
     * knows about too. What is deliberately *not* done is telling the buyer
     * their add-on succeeded: the outcome carries its own state, the local row
     * records `pending_action`, and completion counts only rows whose status is
     * `placed`, so the figure on the receipt stays the figure that was actually
     * charged.
     *
     * The idempotency key is kept rather than released, exactly as at checkout:
     * the provider has an order against it and no idempotency of its own, so
     * giving the key back would let the next acceptance create a second one.
     * {@see \AsterMD\Storefront\Checkout\CheckoutAttempt::releasesKey()} already
     * makes that decision; this method does not repeat it.
     */
    private function pendingAction(
        JourneyState $state,
        Upsell $upsell,
        OrderEnvelope $envelope,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
    ): UpsellResult {
        $reference = (string) $outcome->reference;

        $this->log->warning('upsell.pending_action', [
            'key' => $upsell->key,
            'reference' => $reference,
            'session_uuid' => $this->journeys->sessionUuid(),
            'amount_cents' => $envelope->totalCents,
        ]);

        $this->guard->run('record_upsell_order', $reference, fn (): mixed => $this->orders->record(
            $envelope,
            $outcome,
            $credential,
            [],
            $this->adapter->capabilities()->providerCategory,
            isUpsell: true,
        ));

        $state->recordPlacedOrder($reference);
        $this->guard->run(
            'report_upsell_pending',
            $reference,
            fn (): mixed => $this->events->upsellDeclined($this->journeys->sessionUuid(), $upsell->slug, $upsell->name),
        );

        $this->answered($state, JourneyState::UPSELL_CHARGE_DECLINED);

        return new UpsellResult($this->onwardPath($state), null, $outcome);
    }

    /**
     * The buyer said no: record it, report it, advance (`[16.9]`).
     *
     * No provider call and no attempt row, because there is nothing to be
     * idempotent about — a second decline of an offer the cursor has already
     * moved past simply finds the next one.
     */
    /**
     * @param ?string $answeringKey the offer the page that posted this was showing, exactly as on
     *                              {@see self::accept()} and for the same reason. Declining costs no
     *                              money, but it costs an *offer*: a tab left open on an earlier one
     *                              would otherwise skip whichever offer the cursor has since reached,
     *                              which the buyer never saw and never refused.
     */
    public function decline(?string $answeringKey = null): UpsellResult
    {
        $state = $this->journeys->state();
        if ($state === null) {
            return new UpsellResult($this->receiptPath());
        }

        $upsell = $this->queue->current($state);
        if ($upsell === null) {
            return new UpsellResult($this->receiptPath());
        }

        if ($answeringKey !== null && $answeringKey !== $upsell->key) {
            $this->log->info('upsell.stale_answer', [
                'answering' => $answeringKey,
                'current' => $upsell->key,
                'action' => 'decline',
            ]);

            return new UpsellResult($this->upsellPath(), self::OFFER_MOVED_NOTICE);
        }

        $this->events->upsellDeclined($this->journeys->sessionUuid(), $upsell->slug, $upsell->name);
        $this->log->info('upsell.declined', ['key' => $upsell->key]);
        $this->answered($state, JourneyState::UPSELL_DECLINED);

        return new UpsellResult($this->onwardPath($state));
    }

    /**
     * The charge stood: record it locally, count it, refresh the handle, report
     * it, and advance.
     *
     * Every one of those is a write after the money has moved, so every one of
     * them runs inside {@see PostChargeGuard} (`[20.1]`). The advance is not:
     * it is a mutation of an in-memory object that cannot fail, and it has to
     * happen even if all four writes did not — otherwise a buyer whose
     * database went away would be offered this add-on again on the next
     * request, and charged for it again.
     */
    private function placed(
        JourneyState $state,
        Upsell $upsell,
        OrderEnvelope $envelope,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
    ): UpsellResult {
        $reference = (string) $outcome->reference;

        $this->guard->run('record_upsell_order', $reference, fn (): mixed => $this->orders->record(
            $envelope,
            $outcome,
            $credential,
            [],
            $this->adapter->capabilities()->providerCategory,
            isUpsell: true,
        ));

        // `[16.10]`'s running totals are computed at completion from the order
        // rows, so this list is the join key rather than a second tally: the
        // final event batches every reference it holds.
        $this->guard->run('record_upsell_placed', $reference, function () use ($state, $reference): void {
            $state->recordPlacedOrder($reference);
        });

        // An upsell placement yields a handle of its own, and a second offer in
        // the queue should charge against the freshest one. **Only when there
        // is one**: a response that did not repeat the pair is not a
        // revocation of it.
        $refreshed = $outcome->reusableCredential;
        if ($refreshed !== null && $refreshed->isReusable()) {
            $this->guard->run('refresh_credential', $reference, function () use ($state, $refreshed): void {
                $state->storeReusableCredential($refreshed);
            });
        }

        $this->guard->run('report_upsell_accepted', $reference, fn (): mixed => $this->events->upsellAccepted(
            $this->journeys->sessionUuid(),
            $upsell->slug,
            $upsell->name,
        ));

        $this->log->info('upsell.accepted', ['key' => $upsell->key, 'reference' => $reference]);
        $this->answered($state, JourneyState::UPSELL_ACCEPTED);

        return new UpsellResult($this->onwardPath($state), null, $outcome);
    }

    /**
     * The charge did not stand, and the buyer moves on anyway (`[16.11]`).
     *
     * Recorded locally with the provider's own status (`[16.12]`) and reported
     * as declined, then advanced. **No notice**: the order they came for is
     * unaffected, and a message saying a payment failed would send them looking
     * for a problem with the order that succeeded.
     *
     * The reference is **not** added to the journey's placed-order list, which
     * is where this deviates from `[16.12]`'s literal wording. That list has no
     * room for a status — it is a flat list of references — and it is what the
     * completion step batches to the EMR as the orders this journey placed. A
     * declined reference in it would report an order that does not exist as one
     * that does, and the local order row already carries the declined status
     * `[16.12]` asks to be kept.
     */
    private function chargeDeclined(
        JourneyState $state,
        Upsell $upsell,
        OrderEnvelope $envelope,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
    ): UpsellResult {
        // `[16.12]` records every upsell order, placed or declined -- but only
        // when there is an order to record. A refused vault handle creates
        // nothing at the provider and carries no reference, and asking the
        // recorder to file one anyway earns a `checkout.order_not_recorded`
        // warning for the single most ordinary card-on-file failure there is.
        // An alert that fires on the expected path is an alert nobody reads.
        if ($outcome->reference !== null) {
            $this->guard->run('record_upsell_order', $outcome->reference, fn (): mixed => $this->orders->record(
                $envelope,
                $outcome,
                $credential,
                [],
                $this->adapter->capabilities()->providerCategory,
                isUpsell: true,
            ));
        }

        $this->guard->run('report_upsell_declined', $outcome->reference, fn (): mixed => $this->events->upsellDeclined(
            $this->journeys->sessionUuid(),
            $upsell->slug,
            $upsell->name,
        ));

        $this->log->info('upsell.charge_declined', [
            'key' => $upsell->key,
            'reference' => $outcome->reference,
            'raw_status' => $outcome->rawStatus,
        ]);

        $this->answered($state, JourneyState::UPSELL_CHARGE_DECLINED);

        return new UpsellResult($this->onwardPath($state), null, $outcome);
    }

    /**
     * Somebody else holds this key.
     *
     * A completed attempt is *replayed*: its stored answer is applied to the
     * queue and the buyer is moved on, without the provider being reached
     * again. That is the case where a first acceptance charged the card and its
     * request then died before journey state was saved — the cursor never
     * moved, so the buyer is looking at the same offer, and the only repair
     * that does not charge twice is to take the recorded answer as this
     * request's answer.
     *
     * An unfinished attempt is answered with the offer still on screen and the
     * confirming notice. Nothing is advanced, because nobody yet knows what
     * happened; and nothing traps the buyer, because declining is one click
     * away and touches no attempt row.
     */
    private function duplicate(JourneyState $state, string $key, Upsell $upsell): UpsellResult
    {
        $row = $this->attempts->outcomeFor($key);
        $attempt = $row === null ? null : CheckoutAttempt::fromRow($row);
        $stored = $attempt?->outcome;

        if ($attempt !== null && $attempt->isComplete() && $stored !== null) {
            $this->log->info('upsell.duplicate_replayed', [
                'key' => $upsell->key,
                'idempotency_key' => $key,
                'replayed_state' => $stored->state,
            ]);

            if ($stored->isPlaced() && $stored->reference !== null) {
                $state->recordPlacedOrder($stored->reference);
            }

            $this->answered(
                $state,
                $stored->isPlaced() ? JourneyState::UPSELL_ACCEPTED : JourneyState::UPSELL_CHARGE_DECLINED,
            );

            return new UpsellResult($this->onwardPath($state), null, $stored);
        }

        // The line a sweep meets when a buyer resubmits after a charge nobody
        // could resolve, so it carries what a sweep needs rather than only what
        // is convenient here: the session uuid, which is the identifier that
        // joins to a table, and the amount at risk, which decides whether a
        // human looks now or tomorrow. Naming neither made this the one
        // follow-up to an unresolved charge that could not be acted on.
        $this->log->warning('upsell.duplicate_in_flight', [
            'key' => $upsell->key,
            'idempotency_key' => $key,
            'session_uuid' => $this->journeys->sessionUuid(),
            'amount_cents' => $upsell->priceCents,
            'attempt_state' => $attempt?->state ?? 'absent',
        ]);

        return new UpsellResult($this->upsellPath(), self::CONFIRMING_NOTICE);
    }

    /**
     * Records what the provider said against the attempt, in the one way that
     * is safe for the outcome in hand.
     *
     * The three-way split is {@see CheckoutAttempt::releasesKey()}'s, reused
     * rather than restated. What is worth stating is that a **refused handle**
     * lands in the first branch and not the third: the provider validated the
     * request and created nothing, so the key goes back and no
     * money-may-be-missing alert fires for a call that provably cost nothing.
     *
     * All of it is past the charge, so none of it may throw.
     */
    private function settleAttempt(string $key, string $claimedAt, Upsell $upsell, PlacementOutcome $outcome): void
    {
        if (CheckoutAttempt::releasesKey($outcome)) {
            $this->releaseQuietly($key, $claimedAt);

            return;
        }

        if (!$outcome->isPlaced() && $outcome->state !== PlacementOutcome::PENDING_ACTION) {
            // The one path where money may have moved with nothing local
            // recording it. Logged with everything a reconciliation sweep needs
            // to act on the row this line names.
            $this->log->error('upsell.attempt_unresolved', [
                'idempotency_key' => $key,
                'session_uuid' => $this->journeys->sessionUuid(),
                'amount_cents' => $upsell->priceCents,
                'reference' => $outcome->reference,
                'raw_status' => $outcome->rawStatus,
                'reason' => 'the provider never answered, so the key is kept and no outcome is stored',
            ]);

            return;
        }

        $this->guard->run(
            'record_upsell_outcome',
            $outcome->reference,
            fn (): mixed => $this->attempts->recordOutcome($key, CheckoutAttempt::encodeOutcome($outcome)),
        );
    }

    /**
     * Gives the key back without letting a failed release become the failure
     * the buyer sees.
     *
     * The ownership token is the claim this request made, which is the only
     * thing that proves the row is still its own to delete. Losing a release
     * fails closed: the row stays and the next acceptance of the same offer is
     * told its payment is being confirmed, which is a worse message than the
     * decline deserved and still the safe direction.
     */
    private function releaseQuietly(string $key, string $claimedAt): void
    {
        try {
            $this->attempts->release($key, $claimedAt);
        } catch (\Throwable $error) {
            $this->log->error('upsell.attempt_not_released', ['reason' => CardScrubber::scrub($error->getMessage())]);
        }
    }

    /**
     * One offer as an order the provider can be told about (`[16.8]`).
     *
     * One line, at the price the page showed, with no promotion and no discount
     * (`[13.17]`). The buyer's whole shipping block goes with it
     * because the provider does not resolve a country from the stored
     * customer — omitting it is refused outright — and the block is read back
     * out of journey state, which is why the wipe waits for completion.
     */
    private function envelope(JourneyState $state, Upsell $upsell, string $key, ?RequestContext $context): OrderEnvelope
    {
        $line = new OrderLine(
            slug: $upsell->slug,
            name: $upsell->name,
            providerOffer: $upsell->providerOffer,
            providerItem: $upsell->providerItem,
            unitPriceCents: $upsell->priceCents,
            quantity: 1,
            kind: $upsell->kind,
        );

        return new OrderEnvelope(
            lines: [$line],
            buyer: self::buyer($state->buyer()),
            subtotalCents: $upsell->priceCents,
            discountCents: 0,
            totalCents: $upsell->priceCents,
            currency: $this->currency(),
            promotionCode: null,
            attribution: $state->attribution?->params ?? [],
            sessionUuid: $this->journeys->sessionUuid(),
            clientIp: $context?->clientIp,
            userAgent: $context?->userAgent,
            idempotencyKey: $key,
            anchorSlug: $upsell->slug,
        );
    }

    /**
     * The stored contact details as the provider contract wants them.
     *
     * Read straight from journey state rather than from a submitted form,
     * because this page posts no contact fields: the buyer entered them at
     * checkout and `[16.8]` reuses them. Blank is passed through rather than
     * substituted — the provider refusing an incomplete address is a truer
     * outcome than an invented one being shipped to.
     *
     * @param array<string, string> $stored keyed as {@see \AsterMD\Storefront\Checkout\BuyerDetails::toArray()} keys it
     */
    private static function buyer(array $stored): Buyer
    {
        return new Buyer(
            firstName: (string) ($stored['first_name'] ?? ''),
            lastName: (string) ($stored['last_name'] ?? ''),
            email: (string) ($stored['email'] ?? ''),
            phone: (string) ($stored['phone'] ?? ''),
            addressLine: (string) ($stored['address_line'] ?? ''),
            city: (string) ($stored['city'] ?? ''),
            territory: strtoupper((string) ($stored['territory'] ?? '')),
            postalCode: (string) ($stored['postal_code'] ?? ''),
        );
    }

    /**
     * Where an answered offer sends the buyer: the next offer, or the receipt
     * (`[16.5]`).
     *
     * Asked of the queue rather than assumed, because the next entry may not
     * resolve — and if none of the remaining entries do, the honest answer is
     * the receipt rather than a page that would immediately redirect there.
     */
    private function onwardPath(JourneyState $state): string
    {
        return $this->queue->isSpent($state) ? $this->receiptPath() : $this->upsellPath();
    }

    /**
     * The offer on screen has been answered: record the outcome and note that
     * this journey got as far as the upsell step (`[21.7]`).
     *
     * **Every one of the seven ways an offer can be answered goes through
     * here**, which is the only reason the funnel-position half can be trusted:
     * a buyer's decline, an accepted charge, a declined charge, a challenge, a
     * replayed duplicate, a missing credential handle and an unrecordable
     * attempt all advance the queue, and a copy of this line at six of them
     * would eventually be six.
     *
     * Deliberately not called from {@see self::view()}. `[21.10]`'s upsell rung
     * is "offered, never acted on", so a render that advanced the step would
     * describe a buyer who walked away from the offer as having answered it.
     *
     * It matters most for the post-purchase half of the funnel. `[13.32]`
     * clears the cart at placement, so a journey stopped here that still
     * reported `checkout` would name a step whose own `cart_not_empty`
     * precondition it can no longer satisfy — and `[21.11]`'s retargeting link
     * would bounce off it.
     */
    private function answered(JourneyState $state, string $outcome): void
    {
        $this->queue->advance($state, $outcome);
        FurthestStep::advance($state, self::UPSELL_STEP);
    }

    /** This page's own path, from the flow definition, because the page is a step. */
    private function upsellPath(): string
    {
        return isset($this->flow->steps()[self::UPSELL_STEP])
            ? $this->flow->pathFor(self::UPSELL_STEP)
            : $this->receiptPath();
    }

    /** The receipt step's own path, or the flow's entry when a deployment declares none. */
    private function receiptPath(): string
    {
        return isset($this->flow->steps()['receipt'])
            ? $this->flow->pathFor('receipt')
            : $this->flow->pathFor(FlowDefinition::ENTRY_STEP);
    }

    /**
     * The identity the flood guard counts against.
     *
     * The client address when there is one, because that is the identity a
     * browser cannot throw away (`[13.8]`). A request with no usable address
     * falls back to the PHP session, which is weaker but is the only identity
     * left.
     */
    private function rateLimitIdentity(?RequestContext $context): string
    {
        return $context?->clientIp ?? ('session:' . $this->sessionKey());
    }

    /**
     * The browser-scoped half of the idempotency key's material.
     *
     * The PHP session id rather than the analytics uuid, because an
     * analytics-off deployment has no uuid at all and must still be able to
     * tell one browser's double-click from another browser's first attempt.
     */
    private function sessionKey(): string
    {
        $id = session_id();

        return is_string($id) ? $id : '';
    }

    private function currency(): string
    {
        return (string) $this->config->get('payment.currency', 'USD');
    }
}

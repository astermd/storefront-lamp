<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Sdk\Enum\CheckoutEvent;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\CartOutcome;
use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\VerificationGateway;
use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\FurthestStep;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
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
use AsterMD\Storefront\Upsell\Upsells;

/**
 * The checkout page's whole decision surface: what to render, and what happens
 * when it is submitted.
 *
 * The **order of operations inside {@see self::submit()} is the
 * specification's, not a convenience**, and getting it wrong is silent. In
 * order: refuse a flood before anything costs money (`[13.8]`); re-price a
 * stored discount code against the cart actually in hand, stopping rather than
 * charging a total the buyer has not seen (`[13.11]`, `[14.6b]`); validate the
 * buyer (`[13.5]`) and record the consents (`[26.5]`), either failure leaving
 * the cart untouched and the provider uncalled; re-run the geo gate against
 * the freshly submitted territory across every line (`[13.6]`, `[13.7]`);
 * store the buyer *before* the provider call so a decline or a crash still
 * re-fills the form (`[13.30]`); claim the idempotency key (`[13.37]`);
 * assemble one envelope for the whole cart (`[13.19]`); record that the
 * provider is about to be contacted; place it; and only
 * then, on success, run `[13.32]`'s sequence — capture the running totals
 * first, because the cart is about to be cleared and clearing it first loses
 * the receipt.
 *
 * Four deliberate exceptions to the storefront's degrade-silently rule
 * (`[20.1]`) live here. A blocking consent that was not granted stops the
 * order. A geo block stops it. A checkout attempt that cannot be recorded
 * *before* the charge — neither claimed nor marked as being sent — refuses
 * rather than charging: a payment nobody can reconcile to a local record is
 * worse for the buyer than a checkout that asks them to try again. And a
 * discount whose figure moved when it was re-priced against the current cart
 * stops the submission, because charging a total the buyer never saw is worse
 * than asking them to look at it first.
 *
 * **Past the charge that reverses completely.** Once
 * {@see PaymentAdapter::place()} has returned, the money may have moved, and
 * from that point no failure of ours may reach the buyer as a failure: an
 * error page in front of someone whose card was just debited is the one
 * outcome that leaves nobody with a record of the order. Every write after the
 * call therefore runs inside {@see PostChargeGuard::run()}, which logs what
 * could not be written as a reconciliation item and lets the order stand. It
 * is a collaborator rather than a private method because the checkout charge
 * is no longer the only charge on a journey, and two copies of that rule would
 * drift.
 *
 * **The card passes through and is never kept.** It arrives as a
 * {@see PaymentCredential} and is handed to the adapter, and that is the end of
 * it: nothing here writes it anywhere, and no field of {@see JourneyState}
 * carries it past this request. What survives to pay for a later charge on the
 * same journey is the opaque handle the provider issued (`[15.12]`,
 * `[15.13]`), which is not a card and is therefore allowed to be durable. A
 * deployment whose adapter declares raw carry-forward instead has nothing to
 * charge an upsell against, and that is the correct trade: holding a card
 * across requests to sell an add-on would put the whole deployment in full PCI
 * scope. Nothing here logs the card, records it, or puts it in a message
 * either — the sinks redact, and a class that never assembles a sentence
 * containing one does not have to rely on them.
 */
final class CheckoutService
{
    /** Shown beside a blocking consent that was not granted (`[26.5]`). */
    public const string CONSENT_REQUIRED = 'Please agree to this to continue.';

    /**
     * The outcome-level reason for a submission the form itself can fix.
     *
     * The page shows the per-field messages, not this; it exists so that
     * nothing reading {@see PlacementOutcome::$reason} ever finds it blank.
     */
    public const string CORRECTIONS_NEEDED = 'Please correct the highlighted fields and try again.';

    /** `[13.8]`: the flood guard refused this submission. */
    public const string RATE_LIMITED_NOTICE = 'Too many checkout attempts from this connection. Please wait a few minutes and try again.';

    /** `[13.37]`: an identical submission has claimed this key and has not reached the provider yet. */
    public const string IN_FLIGHT_NOTICE = 'Your order is being processed. Please wait a moment before submitting again.';

    /**
     * `[13.37]`: an identical submission is with the provider right now, or
     * was and never came back.
     *
     * Deliberately not "your payment failed". Nobody knows that: the card may
     * already have been charged, and the only two honest things to say are
     * that the payment is being confirmed and that submitting again is not the
     * way to find out.
     */
    public const string CONFIRMING_NOTICE = 'Your payment is being confirmed. Please wait a moment and reload this page rather than submitting again — your card may already have been charged.';

    /** The attempt could not be claimed, so the order could not be made recordable before the charge. */
    public const string UNRECORDABLE_NOTICE = 'We could not start your order just now. Please try again in a moment.';

    /**
     * The stored code no longer prices against the cart in hand (`[13.11]`).
     *
     * Said out loud rather than silently dropped, because the buyer typed the
     * code and the summary they were looking at a moment ago included it.
     */
    public const string PROMOTION_DROPPED_NOTICE = 'Your discount code no longer applies to your order, so it has been removed. Please review your total.';

    /** The stored code still applies but is worth a different amount against the changed cart (`[13.11]`). */
    public const string PROMOTION_REPRICED_NOTICE = 'Your order changed, so your discount has been recalculated. Please review your total before paying.';

    /**
     * A priced line the provider has never heard of.
     *
     * Dropping it would undercharge, and charging for it is impossible, so the
     * only honest answer is to stop (`[13.19]`).
     */
    public const string UNAVAILABLE_LINE = 'One of the items in your order is not available for purchase right now. Please remove it and try again.';

    /**
     * The resume round trip for a provider challenge is not built,
     * so a challenge must never be reported as a placed order. The wording
     * says plainly what happened and names the reference, because the provider
     * has already created an order against it and support will be asked about
     * it.
     */
    public const string PENDING_ACTION_NOTICE = 'Your payment could not be completed: your bank asked for an extra verification step this checkout cannot yet handle. Please try a different card, or contact support quoting reference %s.';

    /** `[13.34]` is a declared gap — every event and payload says "card". */
    private const string PAYMENT_METHOD = 'card';

    /**
     * The funnel step this page is, named once rather than spelled at the call
     * site: `config/funnel.php` owns the vocabulary and a literal buried in a
     * method body is where a private spelling starts.
     */
    private const string CHECKOUT_STEP = 'checkout';

    /** How long a claimed-but-unfinished attempt blocks a retry before the first request is assumed dead. */
    private const int DEFAULT_STALE_AFTER_SECONDS = 120;

    /** @var \Closure(): string the current ISO-8601 timestamp */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): string)|null $clock injected so a test does not depend on the wall clock
     */
    public function __construct(
        private readonly CartStore $carts,
        private readonly JourneyStore $journeys,
        private readonly CartRules $rules,
        private readonly ProductCatalog $catalog,
        private readonly PaymentAdapter $adapter,
        private readonly Consents $consents,
        private readonly OrderBumps $bumps,
        private readonly VerificationGateway $verification,
        private readonly CheckoutAttemptRepository $attempts,
        private readonly DatabaseRateLimiter $limiter,
        private readonly OrderRecorder $orders,
        private readonly CheckoutEventReporter $events,
        private readonly TeleformSource $forms,
        private readonly FlowDefinition $flow,
        private readonly Config $config,
        private readonly OperatorLog $log,
        private readonly PostChargeGuard $postCharge,
        private readonly Upsells $upsells,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('c');
    }

    /**
     * Everything the page renders.
     *
     * `$submitted` is what the buyer just posted, and when it is present it
     * **overrides prefill entirely** rather than merging under it. Prefill's
     * own precedence puts stored intake answers ahead of working state
     * (`[13.5c]`), which is right for a first render and wrong for a re-render
     * after a decline: `[13.30]` promises the form comes back carrying what was
     * typed, and an intake answer winning over a correction typed at checkout
     * would silently undo the correction the buyer is retrying with.
     *
     * @param array<string, string> $errors checkout field name → the message to show beside it
     */
    public function view(?BuyerDetails $submitted = null, array $errors = [], ?string $notice = null): CheckoutViewModel
    {
        $cart = $this->carts->cart();
        $state = $this->journeys->state();
        [$promotion, $repriced] = $this->repricePromotion($cart, $state);
        $totals = Totals::of($cart->subtotalCents(), $promotion);

        // A caller's own notice wins: a decline reason or a geo block is about
        // the submission the buyer just made, and this one is about a summary
        // line they can see for themselves.
        $notice ??= $repriced;

        $prefill = $this->prefill($cart, $state);
        if ($submitted !== null) {
            // Array union keeps the left-hand value, so every field the buyer
            // just posted stands — including one they deliberately cleared.
            $prefill = $submitted->toArray() + $prefill;
        }

        // The funnel event is opened on the render rather than on the submit:
        // the EMR keeps one checkout record per session and refuses an update
        // that no create preceded, so an order event with no visit before it
        // is an event that never lands.
        //
        // Fired once per journey, through the same deduplication `[4.13]` uses
        // for the intake events. The EMR's create *overwrites* `event` on that
        // one record, and this page re-renders after a decline and after every
        // sub-action's 303 -- so a create per render erased every decline the
        // funnel ever had. The mark is written whether or not the create
        // reached the EMR, because the alternative is retrying a create that
        // may have succeeded, and a second create is the very thing that
        // erases the decline.
        if ($state === null || !$state->hasEmrEvent(CheckoutEvent::CheckoutVisited->value)) {
            $this->events->checkoutVisited($this->journeys->sessionUuid(), $totals);
            $state?->recordEmrEvent(CheckoutEvent::CheckoutVisited->value);
        }

        return new CheckoutViewModel(
            prefill: $prefill,
            errors: $errors,
            notice: $notice,
            totals: $totals,
            lines: $this->lineViewModels($cart),
            bumps: $this->bumpViewModels($cart, $prefill['territory'] ?? ''),
            plans: $this->planViewModel($cart),
            consents: $this->consents->definitions(),
            capabilities: $this->adapter->capabilities(),
            promotion: $promotion,
            currency: $this->currency(),
            itemCount: $cart->itemCount(),
        );
    }

    /**
     * One checkout submission, all the way to a placed order or a stated
     * reason why not.
     *
     * @param array<string, mixed> $consents the submitted consent field values, keyed by consent key
     */
    public function submit(
        BuyerDetails $buyer,
        PaymentCredential $credential,
        array $consents,
        ?RequestContext $context = null,
    ): CheckoutResult {
        $cart = $this->carts->cart();
        $state = $this->journeys->state();

        // Provisional, and only ever answered with by the two branches above
        // step 1b: a flood refusal and an order that was already placed. Both
        // are refusals that render nothing and charge nothing, and the flood
        // guard has to run before anything else costs money — including the
        // provider call the re-price may make. Step 1b replaces both values
        // with figures nothing has to qualify.
        $totals = Totals::of($cart->subtotalCents(), Promotion::fromArray($state?->promotion));

        // 1. `[13.8]`. Before anything costs money, and before the buyer's
        //    details are even looked at: a flood guard that ran after
        //    validation would still be doing work per attempt.
        if (!$this->limiter->allow('checkout.submit', $this->rateLimitIdentity($context))) {
            $this->log->info('checkout.rate_limited', []);

            return $this->refusal($totals, CheckoutResult::REFUSED_RATE_LIMITED, self::RATE_LIMITED_NOTICE);
        }

        // The submission that arrives after the order it is for was already
        // placed. `[13.32]` clears the cart on success, so the idempotency key
        // -- which is derived from what is being bought (`[13.37]`) -- can no
        // longer match the one that first attempt claimed, and the duplicate
        // guard below cannot see it. Answering with the order this journey
        // already has is both truthful and kinder than letting an empty cart
        // fall through to "payment declined" in front of a buyer who has just
        // successfully paid.
        $alreadyPlaced = $cart->isEmpty() ? ($state?->placedOrders ?? []) : [];
        if ($alreadyPlaced !== []) {
            $this->log->info('checkout.already_placed', ['reference' => end($alreadyPlaced)]);

            return new CheckoutResult(
                outcome: PlacementOutcome::placed((string) end($alreadyPlaced)),
                totals: $totals,
                redirectTo: $this->onwardPath(),
            );
        }

        // 1b. `[13.11]`, `[14.6b]`. The provider recomputes the discount from
        //     the code and the lines it is sent, so a figure quoted against a
        //     cart this is no longer the cart of would be a price the buyer
        //     agreed to and nobody would charge. Re-priced here — before the
        //     validation, so every total below is the fresh one — and a figure
        //     that moved stops the submission rather than charging a total the
        //     buyer has not seen. Ordinarily a no-op: the render that produced
        //     the form re-priced already, so the digest matches and no provider
        //     call is made.
        [$promotion, $repriced] = $this->repricePromotion($cart, $state);
        $totals = Totals::of($cart->subtotalCents(), $promotion);

        if ($repriced !== null) {
            return new CheckoutResult(
                outcome: PlacementOutcome::declined(null, $repriced, 'promotion_repriced'),
                totals: $totals,
                notice: $repriced,
            );
        }

        // 2. `[13.5]` and `[26.5]`. Both are collected before either is
        //    reported, so a buyer with a bad ZIP *and* an unticked consent is
        //    told about both at once rather than made to submit twice.
        $errors = BuyerValidator::validate($buyer, $this->verification);
        $recorded = $this->consents->record($consents);
        foreach ($recorded['missing'] as $key) {
            $errors[$key] = self::CONSENT_REQUIRED;
        }

        if ($errors !== []) {
            return new CheckoutResult(
                outcome: PlacementOutcome::declined(null, self::CORRECTIONS_NEEDED, 'validation_failed'),
                totals: $totals,
                errors: $errors,
            );
        }

        // 3. `[13.6]`: re-run against the freshly submitted territory across
        //    every line, not against whatever was known when each was added.
        //    `[13.7]`: a block names the products and leaves the cart intact —
        //    `applyTerritory()` mutates nothing when it refuses.
        $geo = $this->rules->applyTerritory($cart, $buyer->territory);
        if (!$geo->accepted) {
            return new CheckoutResult(
                outcome: PlacementOutcome::declined(null, (string) $geo->notice, 'geo_blocked'),
                totals: $totals,
                notice: $geo->notice,
            );
        }
        $this->carts->save($cart);

        // 4. `[13.30]`: stored before the provider is called, so a decline —
        //    or a request that dies mid-charge — still re-fills the form.
        $state?->storeBuyer($buyer->toArray());
        if ($state !== null) {
            $state->consents = array_map(
                static fn (ConsentRecord $record): array => $record->toArray(),
                $recorded['records'],
            );
        }

        // 5. `[13.37]`. The claim, and the sent marker below it, are the last
        //    two things that can fail before money moves, which is what makes
        //    them the right place to refuse an order nobody would be able to
        //    record.
        $key = IdempotencyKey::forSubmit($this->journeys->sessionUuid(), $this->sessionKey(), $cart, $buyer->toArray());

        try {
            $claimedAt = $this->claim($key);
        } catch (\Throwable $error) {
            $this->log->error('checkout.attempt_unrecordable', ['reason' => CardScrubber::scrub($error->getMessage())]);

            return $this->refusal($totals, CheckoutResult::REFUSED_UNRECORDABLE, self::UNRECORDABLE_NOTICE);
        }

        if ($claimedAt === null) {
            return $this->duplicate($key, $totals);
        }

        // 6. `[13.19]`–`[13.23]`: one order for the whole cart.
        $lines = $this->orderLines($cart);
        foreach ($lines as $line) {
            if ($line->isChargeable() || $line->isFree()) {
                continue;
            }

            // The provider was never reached, so the key goes back: a buyer
            // who fixes their cart is entitled to a first attempt. A release
            // that cannot be written costs them the retry until the claim goes
            // stale, and must not cost them an error page.
            $this->releaseQuietly($key, $claimedAt);
            $this->log->error('checkout.priced_line_unmapped', ['slug' => $line->slug]);

            return new CheckoutResult(
                outcome: PlacementOutcome::declined(null, self::UNAVAILABLE_LINE, 'priced_line_unmapped'),
                totals: $totals,
                notice: self::UNAVAILABLE_LINE,
            );
        }

        $envelope = new OrderEnvelope(
            lines: $lines,
            buyer: $buyer->toBuyer(),
            subtotalCents: $totals->subtotalCents,
            discountCents: $totals->discountCents,
            totalCents: $totals->totalCents,
            currency: $this->currency(),
            promotionCode: $promotion?->code,
            attribution: $state?->attribution?->params ?? [],
            sessionUuid: $this->journeys->sessionUuid(),
            clientIp: $context?->clientIp,
            userAgent: $context?->userAgent,
            idempotencyKey: $key,
            anchorSlug: self::anchorSlug($cart),
        );

        // 7. Written before the call rather than after it, because the width
        //    of one network round trip is all it takes: a request that dies
        //    mid-charge leaves whatever this row last said, and a row still
        //    saying `claimed` invites the stale-takeover path to re-post an
        //    identical payload. Reconstructed live, that produced two provider
        //    orders and two charges. Like the claim, this is a pre-charge
        //    write and refusing here costs nobody any money.
        //
        //    Conditional on the claim step 5 made, so a request whose row was
        //    taken over while it was merely slow refuses here rather than
        //    charging a card that nothing in `checkout_attempts` records.
        try {
            $this->attempts->markSent($key, $claimedAt);
        } catch (\DomainException $error) {
            // The row is no longer this request's, so it is emphatically not
            // this request's to give back: releasing here would delete the
            // claim of whoever took it over, and that request is on its way to
            // the provider. Answering as a duplicate is the truth.
            $this->log->warning('checkout.attempt_taken_over', [
                'idempotency_key' => $key,
                'session_uuid' => $this->journeys->sessionUuid(),
                'amount_cents' => $totals->totalCents,
                'reason' => $error->getMessage(),
            ]);

            return $this->duplicate($key, $totals);
        } catch (\Throwable $error) {
            $this->log->error('checkout.attempt_unrecordable', ['reason' => CardScrubber::scrub($error->getMessage())]);
            $this->releaseQuietly($key, $claimedAt);

            return $this->refusal($totals, CheckoutResult::REFUSED_UNRECORDABLE, self::UNRECORDABLE_NOTICE);
        }

        // 8. The adapter never throws for a provider-side outcome, so there is
        //    nothing to catch here: a refused card, a malformed response and a
        //    network failure all arrive as declines (`[13.25]`, `[13.31]`).
        //    Everything below this line is past the point of no return.
        $outcome = $this->adapter->place($envelope, $credential);
        $this->settleAttempt($key, $claimedAt, $outcome, $totals);

        if ($outcome->state === PlacementOutcome::PENDING_ACTION) {
            // 9. State-wise a challenge is a decline; the key is
            //    deliberately *not* released, because the provider has already
            //    created an order and has no idempotency of its own — giving
            //    the key back would let the next submit create a second one.
            return $this->stopped($state, $totals, $outcome, sprintf(self::PENDING_ACTION_NOTICE, (string) $outcome->reference));
        }

        if (!$outcome->isPlaced()) {
            // 10. `[13.30]`: cart intact, buyer on checkout, the provider's own
            //     reason shown verbatim (`[13.28]`).
            return $this->stopped($state, $totals, $outcome, $outcome->reason);
        }

        return $this->placed($state, $cart, $envelope, $outcome, $credential, $totals, $recorded['records']);
    }

    /**
     * Accepts or withdraws one order bump (`[27.1]`, `[27.11]`).
     *
     * The mutation goes through {@see CartRules} like any other add, so the
     * bump's own supply attachments come along and the geo gate applies to it —
     * an accepted bump is an ordinary cart line and not a special case
     * (`[27.15]`). Only the reduced price is this class's own business, and it
     * is written onto the line rather than into the catalog (`[27.12]`): a
     * bump that silently repriced the product everywhere would change the
     * treatments page too.
     *
     * The bump is named by its product slug, which is what
     * {@see JourneyState::$acceptedBumps} records: an offer's `key` is a
     * configuration label that may be reused across triggers, while the slug
     * is what actually goes in and out of the cart. A slug that is neither on
     * offer nor already accepted is refused rather than added — bumps
     * re-evaluate on every render (`[27.14]`), so a stale form is not an
     * instruction.
     */
    public function toggleBump(string $slug): CartOutcome
    {
        $cart = $this->carts->cart();
        $state = $this->journeys->state();

        if (in_array($slug, $state?->acceptedBumps ?? [], true) && $cart->has($slug)) {
            $outcome = $this->rules->remove($cart, $slug);
            if ($outcome->accepted && $state !== null) {
                $state->acceptedBumps = array_values(array_filter(
                    $state->acceptedBumps,
                    static fn (string $accepted): bool => $accepted !== $slug,
                ));
                $this->carts->save($cart);
                $this->events->orderBump($this->journeys->sessionUuid(), $slug, false);
            }

            return $outcome;
        }

        $bump = null;
        foreach ($this->bumps->offeredFor($cart, $this->territoryFor($cart, $state)) as $candidate) {
            if ($candidate->slug === $slug) {
                $bump = $candidate;
                break;
            }
        }

        if ($bump === null) {
            return CartOutcome::rejected(CartRules::UNKNOWN_PRODUCT);
        }

        $outcome = $this->rules->add($cart, $bump->slug, $bump->variantId);
        if (!$outcome->accepted) {
            return $outcome;
        }

        // `[27.12]`: the reduced price applies to this line and does not alter
        // the catalog, so it is written here rather than resolved by pricing.
        $line = $cart->line($bump->slug);
        if ($line !== null) {
            $line->unitPriceCents = $bump->priceCents();
        }

        if ($state !== null && !in_array($bump->slug, $state->acceptedBumps, true)) {
            $state->acceptedBumps[] = $bump->slug;
        }
        $this->carts->save($cart);

        // `[27.13]`: recorded on the local trail only. The EMR's checkout-event
        // vocabulary has no case for a bump, and a bump is not a second order
        // in any event -- it is an ordinary line on the main charge, so the
        // funnel record that matters is the one the placement writes.
        $this->events->orderBump($this->journeys->sessionUuid(), $bump->slug, true);

        return $outcome;
    }

    /**
     * Prices a discount code against the current cart and stores what the
     * provider said (`[13.11]`, `[13.13]`).
     *
     * The provider owns the arithmetic (`[14.6b]`); this only records the
     * answer. A rejected code is a notice, never an error page (`[13.14]`), and
     * an adapter that declares no promotion support never gets here because
     * the control is not rendered (`[13.18]`, `[14.4]`).
     *
     * What is stored is the answer **and the cart it is an answer about**, so
     * nothing later has to guess whether it still applies.
     */
    public function applyPromotion(string $code, ?RequestContext $context = null): CartOutcome
    {
        if (!$this->adapter->capabilities()->supportsPromotions) {
            return CartOutcome::rejected('Discount codes are not available.');
        }

        if (!$this->limiter->allow('checkout.promo', $this->rateLimitIdentity($context))) {
            return CartOutcome::rejected(self::RATE_LIMITED_NOTICE);
        }

        $cart = $this->carts->cart();
        $state = $this->journeys->state();
        $promotion = $this->quoteAgainst($cart, $state, $code);

        if ($promotion === null) {
            return CartOutcome::rejected('That discount code could not be applied.');
        }

        $this->storePromotion($state, $cart, $promotion);

        return CartOutcome::accepted(sprintf('Discount code %s applied.', $promotion->code));
    }

    /** `[13.13]`: removing the code removes the discount, and nothing else. */
    public function removePromotion(): void
    {
        $this->storePromotion($this->journeys->state(), null, null);
    }

    /** `[12.4]`, `[12.7]`: choosing a plan is what gives an Rx line a price. */
    public function choosePlan(string $slug, string $variantId): CartOutcome
    {
        $cart = $this->carts->cart();
        $outcome = $this->rules->chooseVariant($cart, $slug, $variantId);

        if ($outcome->accepted) {
            $this->carts->save($cart);
        }

        return $outcome;
    }

    /**
     * Keeps what the buyer has typed, so a sub-action does not cost them the
     * form (`[27.10]`).
     *
     * Every sub-action on this page reposts the whole form and comes back
     * through {@see self::view()}, so the values have to survive the redirect
     * somewhere the next render can read them — which is journey state, the
     * same place a decline leaves them.
     */
    public function rememberBuyer(BuyerDetails $buyer): void
    {
        $this->journeys->state()?->storeBuyer($buyer->toArray());
    }

    /**
     * `[13.32]`, in its own order.
     *
     * Step 1 is already done by the time this is called: the totals were
     * captured while the cart still had lines in it, because the very next
     * thing to happen is that the cart is emptied.
     *
     * Two of the spec's eight steps have no home yet and are recorded here
     * rather than silently skipped. The "completion guard flag" it asks to
     * reset is the legacy storefront's single-use marker, and its replacement
     * is the `checkout_attempts` row this submission has already written — a
     * guard derived from the submission itself rather than a flag that has to
     * be remembered. What *is* reset is `cartMirrored`, because the EMR's copy
     * of the cart now belongs to a placed order and the next mutation must
     * create a new one rather than update that. And the "order placed" signal
     * that drives delayed session teardown (`[4.18]`) is, server-side, exactly
     * `placedOrders` being non-empty; the client half of that teardown belongs
     * with the session work, not here.
     *
     * @param list<ConsentRecord> $consents
     */
    private function placed(
        ?JourneyState $state,
        Cart $cart,
        OrderEnvelope $envelope,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
        Totals $totals,
        array $consents,
    ): CheckoutResult {
        $reference = (string) $outcome->reference;

        // 2. Cleared in both the working copy and the durable one, so nothing
        //    re-persists it. `CartStore::save()` writes both.
        $this->postCharge->run('clear_cart', $reference, fn (): mixed => $this->carts->save(new Cart()));

        if ($state !== null) {
            // 3. `[13.17]`: dropped so it cannot bleed into the upsell flow,
            //    and with it the digest of the cart it was quoted against —
            //    a digest left behind would make the next code entered look
            //    like one that had already been priced.
            $state->promotion = null;
            $state->promotionQuotedAgainst = null;
            $state->acceptedBumps = [];
            $state->cartMirrored = false;

            // 4. The buyer contact is already stored. What survives to pay for
            //    an upsell is the handle the provider issued, never the card
            //    (`[15.12]`, `[15.13]`) -- and it is stored whatever the
            //    adapter declares, because an adapter that does not do
            //    reference-order returns none and this then stores nothing.
            //    Under such an adapter the upsell step has nothing to charge
            //    against and skips, which is the trade `[15.10]` prefers to
            //    holding a card across requests.
            //
            //    Wrapped, and guarded, because this is a post-charge write that
            //    can throw by design: `PaymentCredential::toStorable()` refuses
            //    a card deliberately, so that a future caller cannot persist one
            //    by accident. Unwrapped here it would be a 500 in front of a
            //    buyer whose card has already been debited, and it sits ahead of
            //    the order record — so the throw would lose the local order
            //    outright, which is the one failure `afterCharge` exists to make
            //    impossible.
            $reusable = $outcome->reusableCredential;
            $this->postCharge->run(
                'store_reusable_credential',
                $reference,
                function () use ($state, $reusable): void {
                    if ($reusable !== null && !$reusable->isReusable()) {
                        return;
                    }

                    $state->storeReusableCredential($reusable);
                },
            );

            // 5. `[16.1]`, `[16.2]`: the queue is built from what was just
            //    bought, once, here -- this is the last moment anything knows
            //    what that was.
            //
            //    **From `$cart`, the argument, and never from
            //    `$this->carts->cart()`.** Step 2 above has already replaced
            //    the store's cart with an empty one; the argument is still the
            //    cart the order was assembled from, because the clear swaps the
            //    store's reference rather than emptying the object. Reading the
            //    store here would therefore build every buyer's queue from
            //    nothing, and it would do it silently -- an empty queue is
            //    indistinguishable from a buyer who was simply owed no offer.
            $state->upsellQueue = $this->upsells->queueFor(self::purchasedSlugs($cart));
            $state->upsellCursor = 0;
            $state->upsellOutcomes = [];

            // 6. The server-side half of the "order placed" signal, and the
            //    only surviving proof this journey bought something once the
            //    cart is gone.
            $state->recordPlacedOrder($reference);

            // 7. `[21.7]`: paying for checkout is what completes it, so this
            //    is where the step is recorded and not on the render that
            //    opened the page. A visit is carried by `checkout_visited`
            //    above and by the abandonment signal's own state; advancing
            //    the *completed* step on it would describe everyone who
            //    reached this page and left as having finished it.
            //
            //    In-memory and unable to throw, which is what lets it sit on
            //    the far side of a charge without a guard around it (`[20.1]`).
            FurthestStep::advance($state, self::CHECKOUT_STEP);
        }

        // Both are already swallow-and-log boundaries by contract, and both are
        // wrapped anyway: what makes the order safe past this point has to be
        // structural here rather than a promise held somewhere else.
        $this->postCharge->run('record_order', $reference, fn (): mixed => $this->orders->record(
            $envelope,
            $outcome,
            $credential,
            $consents,
            $this->adapter->capabilities()->providerCategory,
        ));
        $this->postCharge->run('report_order_placed', $reference, fn (): mixed => $this->events->orderPlaced(
            $this->journeys->sessionUuid(),
            $totals,
            self::PAYMENT_METHOD,
            [$reference],
        ));

        $this->log->info('checkout.order_placed', ['reference' => $reference, 'anchor' => $envelope->anchorSlug]);

        if ($this->journeys->sessionUuid() === null) {
            // `[20.1]`: analytics being off must not stop a
            // purchase, and a synthetic identifier is forbidden (`[20.8]`), so
            // the order is placed with no session and the gap is stated.
            $this->log->info('checkout.placed_without_session', ['reference' => $reference]);
        }

        return new CheckoutResult(
            outcome: $outcome,
            totals: $totals,
            redirectTo: $this->onwardPath(),
        );
    }

    /**
     * A decline or a challenge: the buyer stays on checkout with the cart
     * untouched, and the card they typed is forgotten.
     *
     * `[15.8]` wipes the credential on a decline at checkout for a plain
     * reason: a card held after a failed charge is held for nothing.
     */
    private function stopped(
        ?JourneyState $state,
        Totals $totals,
        PlacementOutcome $outcome,
        ?string $notice,
    ): CheckoutResult {
        $state?->wipePaymentCredential();

        // `[21.10]`'s "payment abandoned" is *placement attempted and
        // declined*, and until this line the only durable trace of a decline
        // left this process altogether — so the abandonment classifier had to
        // infer the rung from the presence of recorded consents rather than
        // read it. Recorded here it is a fact.
        //
        // A challenge reaches this method too, and is recorded the same way it
        // is reported: state-wise it is a decline until the resume round trip
        // exists, and a local record that disagreed with what the EMR was told
        // would be a second answer to one question.
        //
        // The mark deduplicates and the report does not, deliberately. The
        // local list is a set of names, so a buyer refused twice adds nothing
        // the second time — which is what `[4.13]` and `[21.13]` need of it.
        // The report below stays unconditional because a second attempt is a
        // second provider answer, carrying its own reference and reason, and
        // the funnel record it updates is overwritten in place rather than
        // created — so re-reporting duplicates nothing at the destination while
        // suppressing it would lose an attempt the EMR never heard about.
        $state?->recordEmrEvent(CheckoutEvent::OrderDeclined->value);

        // The provider has answered by the time this runs, so reporting the
        // decline is a post-charge step like any other: it may fail, and it may
        // not take the buyer's page down with it.
        $this->postCharge->run('report_order_declined', $outcome->reference, fn (): mixed => $this->events->orderDeclined(
            $this->journeys->sessionUuid(),
            $totals,
            self::PAYMENT_METHOD,
            $outcome->reference,
            (string) ($outcome->reason ?? $outcome->rawStatus),
        ));

        return new CheckoutResult(
            outcome: $outcome,
            totals: $totals,
            // Scrubbed on the way to the page for the same reason it is
            // scrubbed on the way to a column: the reason can fall through to
            // gateway free text, and a card number rendered into a response
            // body reaches the browser's cache and any proxy that logs one
            // (`[15.8]`).
            notice: $notice === null ? null : (string) CardScrubber::scrub($notice),
        );
    }

    /**
     * Someone else holds this key.
     *
     * A completed attempt is replayed verbatim — that stored answer is what
     * this submission is owed, and reaching the provider again would charge a
     * second time.
     *
     * The two unfinished states are answered differently because they mean
     * different things. A claimed row belongs to a request that has not
     * contacted the provider, and "your order is being processed" is the whole
     * truth. A sent row belongs to one that has, and may stand for a charge
     * that already happened — so it is told the payment is being confirmed,
     * which is neither a second charge nor a failure nobody has established.
     */
    private function duplicate(string $key, Totals $totals): CheckoutResult
    {
        $row = $this->attempts->outcomeFor($key);
        $attempt = $row === null ? null : CheckoutAttempt::fromRow($row);

        if ($attempt !== null && $attempt->isComplete() && $attempt->outcome !== null) {
            $stored = $attempt->outcome;
            $this->log->info('checkout.duplicate_replayed', $this->reconcilable(
                $key,
                $totals,
                $stored->reference,
                CheckoutAttemptRepository::STATE_COMPLETE,
            ) + ['replayed_state' => $stored->state]);

            $notice = match (true) {
                $stored->isPlaced() => null,
                $stored->state === PlacementOutcome::PENDING_ACTION => sprintf(self::PENDING_ACTION_NOTICE, (string) $stored->reference),
                default => $stored->reason,
            };

            return new CheckoutResult(
                outcome: $stored,
                totals: $totals,
                notice: $notice,
                redirectTo: $stored->isPlaced() ? $this->onwardPath() : null,
            );
        }

        if ($attempt !== null && $attempt->isSent()) {
            // A warning rather than an info line: either two submissions are
            // racing a provider round trip, or an earlier one died mid-charge
            // and left an order nobody has reconciled. Carries the same payload
            // as `checkout.attempt_unresolved` because it names the same row,
            // and a sweep reaching this line and not that one is how a charge
            // whose own request never returned gets noticed at all.
            $this->log->warning('checkout.duplicate_confirming', $this->reconcilable(
                $key,
                $totals,
                $attempt->outcome?->reference,
                CheckoutAttemptRepository::STATE_SENT,
            ));

            return $this->refusal($totals, CheckoutResult::REFUSED_IN_FLIGHT, self::CONFIRMING_NOTICE);
        }

        $this->log->info('checkout.duplicate_in_flight', $this->reconcilable(
            $key,
            $totals,
            null,
            $attempt?->state ?? 'absent',
        ));

        return $this->refusal($totals, CheckoutResult::REFUSED_IN_FLIGHT, self::IN_FLIGHT_NOTICE);
    }

    /**
     * Records what the provider said against the attempt, in the one way that
     * is safe for the outcome in hand.
     *
     * Three answers, because the question "may this key be used again" has
     * three of them. A decline the provider itself answered with took no
     * money, and the key goes back: it is derived from the cart and the buyer
     * and excludes the card by design (`[15.8]`), so a buyer retrying with a
     * different card derives the same key and would otherwise be handed the
     * first card's decline forever. An outcome that cannot rule out a charge
     * is *not* recorded at all — the row stays `sent`, because "the provider
     * declined you" is a claim nobody is entitled to make about a call that
     * never came back, and a duplicate deserves to be told the payment is
     * being confirmed rather than that it failed. Everything else is a real
     * provider answer and is stored to be replayed.
     *
     * All of it is past the charge, so none of it may throw.
     *
     * @param string $claimedAt the `created_at` this request's own claim wrote,
     *                          which the release below is conditional on: a row
     *                          another request has since taken over is not this
     *                          one's to give back, however the provider answered
     */
    private function settleAttempt(string $key, string $claimedAt, PlacementOutcome $outcome, Totals $totals): void
    {
        if (CheckoutAttempt::releasesKey($outcome)) {
            $this->releaseQuietly($key, $claimedAt);

            return;
        }

        if (!$outcome->isPlaced() && $outcome->state !== PlacementOutcome::PENDING_ACTION) {
            // The one path where money may have moved with nothing local
            // recording it, so this line is the whole input to a reconciliation
            // sweep and has to carry what such a sweep needs to work from: the
            // key, which is the primary key of the row left `sent`; the
            // analytics session uuid, which is the identifier that actually
            // joins to a table (the PHP session id in `session_key` is a
            // browser cookie value stored nowhere); the amount at risk; and the
            // provider's own reference when there is one, because a decline
            // still returns one and it is what support will be asked about.
            $this->log->error('checkout.attempt_unresolved', $this->reconcilable(
                $key,
                $totals,
                $outcome->reference,
                CheckoutAttemptRepository::STATE_SENT,
            ) + [
                'raw_status' => $outcome->rawStatus,
                'reason' => 'the provider never answered, so the key is kept and no outcome is stored',
            ]);

            return;
        }

        $this->postCharge->run(
            'record_outcome',
            $outcome->reference,
            fn (): mixed => $this->attempts->recordOutcome($key, CheckoutAttempt::encodeOutcome($outcome)),
        );
    }

    /**
     * The context every attempt-level log line carries, so a reconciliation
     * sweep can act on one without going back to ask what it was about.
     *
     * Four fields, each earning its place. `idempotency_key` is the primary key
     * of the `checkout_attempts` row the line is about, and without it a sweep
     * that finds the line cannot find the row. `session_uuid` is the analytics
     * session, deliberately *not* the `session_key` column: that column holds
     * `session_id()`, a browser cookie value written to no table, so it joins to
     * nothing — while the uuid is the `sessions` row and every event recorded
     * against it. `amount_cents` is the money at risk, which is what decides
     * whether a human looks now or tomorrow. `reference` is the provider's own
     * order id when there is one — a decline returns one too — and it is the
     * only identifier that can be quoted at the provider.
     *
     * None of the four is a redacted key, and none may become one: the digest
     * excludes the card by design (`[15.8]`) and the rest are identifiers and
     * an integer. Nothing here is buyer-identifying, which is why this can be
     * logged at all.
     *
     * @return array{idempotency_key: string, session_uuid: ?string, amount_cents: int, reference: ?string, attempt_state: string}
     */
    private function reconcilable(string $key, Totals $totals, ?string $reference, string $attemptState): array
    {
        return [
            'idempotency_key' => $key,
            'session_uuid' => $this->journeys->sessionUuid(),
            'amount_cents' => $totals->totalCents,
            'reference' => $reference,
            'attempt_state' => $attemptState,
        ];
    }

    /**
     * Gives the key back without letting a failed attempt to do so become
     * the failure the buyer sees.
     *
     * Every caller is either past the charge or on a path that has already
     * decided to refuse, and on both a raised release turns a handled outcome
     * into an error page. Losing one fails closed rather than open: a release
     * that never happened before the provider was contacted leaves a `claimed`
     * row the staleness window frees anyway, and one that never happened after
     * a decline leaves a `sent` row, which tells the next submit its payment is
     * being confirmed. That is a worse message than the decline deserved, and
     * it is still the safe direction — the alternative is a second charge.
     *
     * **The ownership token is required and is never defaulted.** A release
     * that cannot say which request is asking is a release that deletes
     * whatever row it finds, and an unconditional delete here was the root
     * cause of a double charge: a row that moved `claimed` → `sent` between
     * one request's read and its delete was removed while its owner was
     * mid-charge, and the taker then charged the same card again. A defaulted
     * token would restore that shape silently, because the swallow below turns
     * a missing argument into a key that is simply never given back.
     *
     * @param string $claimedAt the `created_at` this request's own claim wrote
     */
    private function releaseQuietly(string $key, string $claimedAt): void
    {
        try {
            $this->attempts->release($key, $claimedAt);
        } catch (\Throwable $error) {
            $this->log->error('checkout.attempt_not_released', ['reason' => CardScrubber::scrub($error->getMessage())]);
        }
    }

    /**
     * Claims the key, taking over a claim old enough to have been abandoned.
     *
     * **Only a `claimed` row is ever eligible**, however old it is. A `sent`
     * row belongs to a request that had already reached the provider, and the
     * provider has no idempotency of its own: re-posting an identical payload
     * creates and charges a second order, which is exactly what reconstructing
     * a died-mid-charge row and resubmitting past the window produced. Age
     * proves that a request is not coming back; it proves nothing whatever
     * about whether it charged the card.
     *
     * The takeover is a single conditional update, not a release followed by a
     * fresh claim. Deleting the row first destroys the very thing the two
     * racers were serialising against, so both of them re-insert and both
     * charge; and the delete could land on a row that had meanwhile become
     * `sent`, taking the record away from a request that was mid-charge.
     * {@see CheckoutAttemptRepository::renewClaim()} makes the row itself the
     * serialisation point instead, and cannot see a `sent` row at all.
     *
     * @return string|null the `created_at` this request now owns the row with,
     *                     which every later write against it is conditional on;
     *                     null when somebody else holds the key
     */
    private function claim(string $key): ?string
    {
        $now = ($this->clock)();

        if ($this->attempts->claim($key, $this->sessionKey(), $now)) {
            return $now;
        }

        $row = $this->attempts->outcomeFor($key);
        if ($row === null || $row['state'] !== CheckoutAttemptRepository::STATE_CLAIMED) {
            return null;
        }

        $age = (int) strtotime($now) - (int) strtotime($row['created_at']);
        if ($age < $this->staleAfterSeconds()) {
            return null;
        }

        if (!$this->attempts->renewClaim($key, $this->sessionKey(), $now, $row['created_at'])) {
            // Another request reached the same conclusion first, or the row
            // moved on under us. Either way this request is the duplicate.
            return null;
        }

        $this->log->warning('checkout.stale_attempt_reclaimed', [
            'idempotency_key' => $key,
            'session_uuid' => $this->journeys->sessionUuid(),
            'age_seconds' => $age,
        ]);

        return $now;
    }

    /** A refusal the buyer cannot fix by editing the form. */
    private function refusal(Totals $totals, string $because, string $notice): CheckoutResult
    {
        return new CheckoutResult(
            outcome: PlacementOutcome::declined(null, $notice, $because),
            totals: $totals,
            notice: $notice,
            refusedBecause: $because,
        );
    }

    /**
     * Where a placed order sends the buyer.
     *
     * The upsell step when the flow declares one, the receipt otherwise. The
     * queue that would make that choice conditional on there being something
     * to offer is not built here; this builds the queue's inputs and nothing
     * else, so a flow with an upsell step always routes through it.
     */
    private function onwardPath(): string
    {
        foreach (['upsell', 'receipt'] as $step) {
            if (isset($this->flow->steps()[$step])) {
                return $this->flow->pathFor($step);
            }
        }

        return $this->flow->pathFor(FlowDefinition::ENTRY_STEP);
    }

    /**
     * The cart as provider-neutral order lines.
     *
     * The provider identifiers come from the chosen variant's own mapping, or
     * from the first variant when no plan applies — which is what the catalog
     * builder synthesises for a single-SKU product. A line with no mapping
     * keeps null identifiers rather than being dropped here: whether that is
     * survivable is {@see self::submit()}'s decision, and it turns on whether
     * the line is free.
     *
     * The kind travels too, taken from the cart line rather than looked up
     * again. {@see OrderLine::$kind} defaults to the unrestricted case, so
     * omitting it recorded every prescription as an ordinary product — and the
     * local record is what a support call and a reconciliation sweep read, so
     * one that cannot tell an Rx line from a mask is a record of some other
     * order. {@see CartLine::$kind} is the catalog's own answer, copied in when
     * the line was added and the same value {@see CartRules} makes its own
     * prescription decisions on; re-reading the catalog here would let a deploy
     * mid-journey change what an order in flight says it contained.
     *
     * @return list<OrderLine>
     */
    private function orderLines(Cart $cart): array
    {
        $lines = [];

        foreach ($cart->lines() as $line) {
            $provider = $this->providerIdentifiers($line);

            $lines[] = new OrderLine(
                slug: $line->slug,
                name: $line->name,
                providerOffer: $provider['offer_id'] ?? null,
                providerItem: $provider['product_id'] ?? null,
                unitPriceCents: $line->unitPriceCents,
                quantity: $line->quantity,
                kind: $line->kind,
            );
        }

        return $lines;
    }

    /**
     * @return array{offer_id?: string, product_id?: string}
     */
    private function providerIdentifiers(CartLine $line): array
    {
        $variant = $this->variantFor($line->slug, $line->variantId);
        $provider = $variant['provider'] ?? null;

        return is_array($provider) ? $provider : [];
    }

    /**
     * The chosen variant, or the first one when nothing was chosen.
     *
     * @return array<string, mixed>|null
     */
    private function variantFor(string $slug, ?string $variantId): ?array
    {
        $product = $this->catalog->product($slug);
        $variants = is_array($product['variants'] ?? null) ? array_values(array_filter($product['variants'], 'is_array')) : [];

        if ($variants === []) {
            return null;
        }

        if ($variantId === null || $variantId === '') {
            return $variants[0];
        }

        foreach ($variants as $variant) {
            if ((string) ($variant['id'] ?? '') === $variantId) {
                return $variant;
            }
        }

        return $variants[0];
    }

    /**
     * The promotion that may be shown and charged against the cart in hand.
     *
     * The provider recomputes the discount from the code and the lines it is
     * sent (`[14.6b]`), and the payload it is sent carries no order total — so
     * a stored figure is only ever true of the cart it was quoted against, and
     * showing it beside a different cart shows a price nobody will charge.
     * `NEW10` applied to a $270 plan and then switched to the $30 one rendered
     * "Pay $3.00" while the provider was asked to discount $30 and took $27.
     *
     * A stored quote whose cart digest no longer matches is therefore never
     * used: it is re-priced against the current cart, and if the code no longer
     * prices at all it is dropped. Detection is by digest rather than by
     * clearing the promotion at each mutation site, because the cart changes in
     * places this class does not own — the cart page, the drawer, another tab —
     * and a mutation site nobody remembered is exactly how this defect arose.
     * The cost is one read-only provider call, made only when the cart really
     * has moved under the quote.
     *
     * **That call deliberately has no flood bucket of its own.** The digest
     * gate above is the throttle: a provider call happens only when a code is
     * applied *and* the cart has genuinely moved under it, which no attacker
     * can drive faster than a buyer editing their own cart. Every behaviour a
     * bucket could add on exhaustion is worse than the exposure it closes —
     * showing the stale figure reintroduces the overcharge this method exists
     * to remove, and refusing the buyer is a self-inflicted denial of service
     * on the page that takes the money. {@see DatabaseRateLimiter} fails open
     * by design in any case, so a bucket here would buy a name for the
     * behaviour and not the behaviour.
     *
     * @return array{0: ?Promotion, 1: ?string} the promotion to use, and the
     *                                          notice to show when re-pricing
     *                                          changed what the buyer was told
     */
    private function repricePromotion(Cart $cart, ?JourneyState $state): array
    {
        $stored = Promotion::fromArray($state?->promotion);
        if ($stored === null || $state === null) {
            return [$stored, null];
        }

        $scope = self::promotionScope($this->orderLines($cart));
        if ($state->promotionQuotedAgainst === $scope) {
            return [$stored, null];
        }

        $fresh = $this->quoteAgainst($cart, $state, $stored->code);

        if ($fresh === null) {
            $this->storePromotion($state, $cart, null);
            $this->log->info('checkout.promotion_dropped', ['code' => $stored->code]);

            return [null, self::PROMOTION_DROPPED_NOTICE];
        }

        $this->storePromotion($state, $cart, $fresh);

        if ($fresh->discountCents === $stored->discountCents) {
            return [$fresh, null];
        }

        $this->log->info('checkout.promotion_repriced', [
            'code' => $fresh->code,
            'was_discount_cents' => $stored->discountCents,
            'now_discount_cents' => $fresh->discountCents,
        ]);

        return [$fresh, self::PROMOTION_REPRICED_NOTICE];
    }

    /**
     * One read-only quote of a code against one cart, or null when the code
     * does not price against it.
     *
     * The single place a quote is turned into a stored promotion, so the two
     * entry points — the buyer applying a code, and a re-price after the cart
     * moved — cannot come to different conclusions about the same answer.
     *
     * **A discount larger than the cart is refused rather than clamped.** The
     * clamp in {@see Totals} guards the *display* and cannot reach the charge,
     * because the provider payload carries no order total: a quote of $40
     * against a $30 cart renders "Pay $0.00" while $30 of real line prices go
     * out with the code attached, and what is actually taken is then entirely
     * the provider's arithmetic. That divergence is also the signature of the
     * two sides pricing different carts — the recorded quantity-key difference
     * between the two endpoints fails exactly that way, and silently. Refusing
     * leaves the buyer looking at the full price they will really be charged,
     * which is the only reading of the two that is certainly true.
     */
    private function quoteAgainst(Cart $cart, ?JourneyState $state, string $code): ?Promotion
    {
        if (!$this->adapter->capabilities()->supportsPromotions) {
            return null;
        }

        $quote = $this->adapter->quotePromotion($this->quoteEnvelope($cart, $state), $code);

        if (!$quote->valid) {
            $this->log->info('checkout.promotion_rejected', ['reason' => $quote->reason]);

            return null;
        }

        if ($quote->discountCents > $cart->subtotalCents()) {
            $this->log->warning('checkout.promotion_exceeds_cart', [
                'code' => $quote->code,
                'discount_cents' => $quote->discountCents,
                'subtotal_cents' => $cart->subtotalCents(),
            ]);

            return null;
        }

        return new Promotion($quote->code, $quote->discountCents);
    }

    /**
     * Stores a promotion together with the cart it is an answer about, or
     * clears both.
     *
     * The two always move together. A promotion with a digest of some other
     * cart is the defect this pair exists to prevent, and a digest left behind
     * by a cleared promotion would make the *next* code look already-quoted.
     */
    private function storePromotion(?JourneyState $state, ?Cart $cart, ?Promotion $promotion): void
    {
        if ($state === null) {
            return;
        }

        $state->promotion = $promotion?->toArray();
        $state->promotionQuotedAgainst = $promotion === null || $cart === null
            ? null
            : self::promotionScope($this->orderLines($cart));
    }

    /**
     * A digest of exactly what a quote is computed from.
     *
     * The provider is asked to discount a set of lines, each carrying its offer
     * and item identifiers, its price and its quantity ({@see \AsterMD\Storefront\Payment\Vrio\VrioOffers}),
     * and nothing else about the cart reaches it. So those four fields per line
     * are what the digest covers: anything that changes them changes the answer
     * and must invalidate the stored one, and anything that does not — a
     * shipping territory, a name on the form — must not cost the buyer a
     * needless re-quote.
     *
     * @param list<OrderLine> $lines
     */
    private static function promotionScope(array $lines): string
    {
        $material = [];

        foreach ($lines as $line) {
            if (!$line->isChargeable()) {
                continue;
            }

            $material[] = [$line->providerOffer, $line->providerItem, $line->unitPriceCents, $line->quantity];
        }

        return hash('sha256', (string) json_encode($material, JSON_UNESCAPED_SLASHES));
    }

    /**
     * A cheap envelope for pricing a discount code.
     *
     * The adapter needs the lines and nothing else to quote, but the contract
     * takes a whole envelope, so the buyer is filled from whatever the journey
     * already holds rather than being demanded from the caller — a code is
     * applied long before the address is necessarily complete.
     */
    private function quoteEnvelope(Cart $cart, ?JourneyState $state): OrderEnvelope
    {
        $buyer = new BuyerDetails(...self::buyerArguments($state?->buyer() ?? []));
        $totals = Totals::of($cart->subtotalCents(), null);

        return new OrderEnvelope(
            lines: $this->orderLines($cart),
            buyer: $buyer->toBuyer(),
            subtotalCents: $totals->subtotalCents,
            discountCents: 0,
            totalCents: $totals->totalCents,
            currency: $this->currency(),
            promotionCode: null,
            attribution: [],
            sessionUuid: $this->journeys->sessionUuid(),
            clientIp: null,
            userAgent: null,
            idempotencyKey: '',
            anchorSlug: self::anchorSlug($cart),
        );
    }

    /**
     * @param  array<string, string> $stored
     * @return array<string, string>
     */
    private static function buyerArguments(array $stored): array
    {
        return [
            'firstName' => $stored['first_name'] ?? '',
            'lastName' => $stored['last_name'] ?? '',
            'email' => $stored['email'] ?? '',
            'phone' => $stored['phone'] ?? '',
            'addressLine' => $stored['address_line'] ?? '',
            'city' => $stored['city'] ?? '',
            'territory' => $stored['territory'] ?? '',
            'postalCode' => $stored['postal_code'] ?? '',
        ];
    }

    /**
     * The order's anchor: the prescription if there is one, otherwise the
     * first thing the buyer chose for themselves.
     *
     * It names the order for the operator and for the upsell queue's inputs,
     * so a bundled child is never it — a receipt anchored on "Syringe" tells
     * nobody what was bought.
     */
    private static function anchorSlug(Cart $cart): string
    {
        $rx = $cart->rxLine();
        if ($rx !== null) {
            return $rx->slug;
        }

        foreach ($cart->lines() as $line) {
            if (!$line->isChild()) {
                return $line->slug;
            }
        }

        return '';
    }

    /**
     * Every slug this order contained, which is what the upsell queue is built
     * from (`[16.1]`).
     *
     * Mapped from the cart here rather than asked of {@see Cart}, because "what
     * was purchased" is a question about an order and the cart is a bag of
     * lines — the same reason {@see self::anchorSlug()} lives here.
     *
     * Child lines are included. A free attachment really was in the order, and
     * whether an offer should follow one is the configuration's decision to
     * make; silently withholding a slug the buyer received would make a
     * configured `offer_after` quietly never fire, which is the harder failure
     * to notice of the two.
     *
     * @return list<string>
     */
    private static function purchasedSlugs(Cart $cart): array
    {
        $slugs = [];

        foreach ($cart->lines() as $line) {
            $slugs[] = $line->slug;
        }

        return $slugs;
    }

    /**
     * The checkout fields this visitor should not have to type again
     * (`[13.5b]`).
     *
     * The intake lookup is wrapped because it can reach the EMR: a
     * questionnaire definition that cannot be resolved must cost the buyer a
     * prefilled field, never the checkout page (`[20.1]`).
     *
     * @return array<string, string>
     */
    private function prefill(Cart $cart, ?JourneyState $state): array
    {
        $working = $state?->buyer() ?? [];
        $teleformId = $this->teleformIdFor($cart);

        if ($teleformId === null || $state === null) {
            return Prefill::from(null, null, $working);
        }

        $metadata = null;
        try {
            $metadata = $this->forms->metadataFor($teleformId);
        } catch (\Throwable $error) {
            $this->log->warning('checkout.prefill_unavailable', ['reason' => $error->getMessage()]);
        }

        return Prefill::from(
            $metadata,
            $metadata === null ? null : AnswerSet::fromArray($state->answersFor($teleformId)),
            $working,
        );
    }

    /** `[8.3]`: the first line that declares a questionnaire, which with one prescription per order is the only one. */
    private function teleformIdFor(Cart $cart): ?string
    {
        foreach ($cart->lines() as $line) {
            $id = $this->catalog->product($line->slug)['teleform_id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * The cart in presentation shape, free attachments included at zero
     * (`[7.14a]`) rather than hidden — a supply the buyer is receiving belongs
     * on the summary whether or not it is charged for.
     *
     * @return list<array<string, mixed>>
     */
    private function lineViewModels(Cart $cart): array
    {
        $models = [];

        foreach ($cart->lines() as $line) {
            $product = $this->catalog->product($line->slug);
            $variant = $this->variantFor($line->slug, $line->variantId);

            $models[] = [
                'slug' => $line->slug,
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unit_price_cents' => $line->unitPriceCents,
                'line_total_cents' => $line->lineTotalCents(),
                'variant_name' => $variant === null ? null : (string) ($variant['name'] ?? ''),
                'image' => isset($product['image']) && is_string($product['image']) ? $product['image'] : null,
                'is_free' => $line->unitPriceCents === 0,
                'is_child' => $line->isChild(),
            ];
        }

        return $models;
    }

    /**
     * The offers this cart earns, in position order (§27).
     *
     * @return list<array<string, mixed>>
     */
    private function bumpViewModels(Cart $cart, string $territory): array
    {
        $models = [];

        foreach ($this->bumps->offeredFor($cart, $territory) as $bump) {
            $product = $this->catalog->product($bump->slug);

            $models[] = [
                'key' => $bump->key,
                'slug' => $bump->slug,
                'headline' => $bump->headline,
                'body' => $bump->body,
                'name' => $bump->name,
                'price_cents' => $bump->priceCents(),
                'catalog_price_cents' => $bump->catalogPriceCents,
                'discounted' => $bump->priceCentsOverride !== null && $bump->priceCentsOverride < $bump->catalogPriceCents,
                'image' => isset($product['image']) && is_string($product['image']) ? $product['image'] : null,
            ];
        }

        return $models;
    }

    /**
     * The Rx line's plan selector, or null when there is no prescription to
     * choose a plan for.
     *
     * @return array<string, mixed>|null
     */
    private function planViewModel(Cart $cart): ?array
    {
        $line = $cart->rxLine();
        if ($line === null) {
            return null;
        }

        $product = $this->catalog->product($line->slug);
        $variants = is_array($product['variants'] ?? null) ? array_values(array_filter($product['variants'], 'is_array')) : [];
        if ($variants === []) {
            return null;
        }

        return [
            'slug' => $line->slug,
            'name' => $line->name,
            'chosen' => $line->variantId,
            'options' => array_map(static fn (array $variant): array => [
                'id' => (string) ($variant['id'] ?? ''),
                'name' => (string) ($variant['name'] ?? ''),
                'price_cents' => (int) ($variant['price_cents'] ?? 0),
            ], $variants),
        ];
    }

    /** The territory the geo gate has already been applied with, or the one the buyer last told us. */
    private function territoryFor(Cart $cart, ?JourneyState $state): string
    {
        return $cart->shippingTerritory ?? ($state?->buyer()['territory'] ?? '');
    }

    /**
     * The identity the flood guard counts against.
     *
     * The client address when there is one, because that is the identity a
     * browser cannot throw away (`[13.8]`, `[29.23]`). A request with no usable
     * address falls back to the PHP session, which is weaker — a dropped cookie
     * buys a fresh allowance — but it is the only identity left, and a guard
     * that counted every addressless request as one caller would refuse them
     * all together.
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

    private function staleAfterSeconds(): int
    {
        $configured = $this->config->get('payment.attempt_stale_after_seconds', self::DEFAULT_STALE_AFTER_SECONDS);

        return is_numeric($configured) ? max(1, (int) $configured) : self::DEFAULT_STALE_AFTER_SECONDS;
    }
}

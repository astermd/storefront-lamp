<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Journey;

use AsterMD\Storefront\Attribution\Attribution;
use AsterMD\Storefront\Payment\PaymentCredential;

/**
 * The durable, server-side state of one journey.
 *
 * Deliberately a mutable bag rather than an immutable value object: request
 * handlers and middleware mutate the state they were handed and the
 * save-back middleware persists whatever it finds at the end of the request,
 * which is what keeps controllers out of the persistence layer entirely.
 *
 * `opportunityId` and `attribution` live in their own database columns (they
 * are looked up and reasoned about on their own), so {@see self::toArray()}
 * covers only the fields that belong in the `journey_state` JSON blob;
 * {@see JourneyStore} recombines the three. `emrEvents` is the set of
 * lifecycle events the EMR has already recorded for this session, read once
 * per journey, and it is what stops a returning visitor re-firing an
 * "initiated" event and producing a duplicate lead (`[4.13]`).
 *
 * Note for anyone extending this class: `formAnswers` holds clinical answers,
 * which are PHI. They are here because a form has to be resumable across
 * devices and sessions, which browser-scoped storage cannot do — but that
 * means the local `journey_state` JSON column now stores health information
 * alongside funnel bookkeeping. Nothing here expires or scrubs it: retention
 * and deletion of stored answers is §30's concern rather than this class's,
 * and until it is answered there, an answer must never be logged, never
 * appear in an exception message, and never be echoed into an analytics
 * payload.
 */
final class JourneyState
{
    /** A teleform that has been answered but not submitted. */
    public const string FORM_IN_PROGRESS = 'in_progress';

    /** A teleform the visitor has submitted, and the only status that opens the next funnel step. */
    public const string FORM_COMPLETED = 'completed';

    /**
     * The per-offer upsell vocabulary (`[16.1]`, `[16.11]`).
     *
     * Constants rather than literals because three collaborators write these
     * values and a fourth reads them back out of a JSON column: an offer
     * recorded under a spelling one of them does not recognise reads as an
     * offer that was never answered, which re-offers it and charges the buyer
     * twice for the same add-on.
     *
     * `DECLINED` is the buyer's answer; `CHARGE_DECLINED` is the provider's.
     * They are kept apart because only the first is a preference — the second
     * is a failure that `[16.11]` still requires the queue to advance past, and
     * an operator reading the trail has to be able to tell them apart.
     * `SKIPPED` is an offer that was never put: no reusable credential, or a
     * configuration that no longer resolves the key (`[16.4]`).
     */
    public const string UPSELL_OFFERED = 'offered';

    public const string UPSELL_ACCEPTED = 'accepted';

    public const string UPSELL_DECLINED = 'declined';

    public const string UPSELL_CHARGE_DECLINED = 'charge_declined';

    public const string UPSELL_SKIPPED = 'skipped';

    /**
     * The identity-verification vocabulary (`[22.20]`), and the reason it has
     * three values rather than a boolean.
     *
     * Recorded live on 2026-08-25: the provider answers HTTP 200 with the
     * verdict in the body, so a refusal and a malformed request are not
     * distinguishable by status code and must be told apart by what the code
     * asked for. `PASSED` and `FAILED` are statements about the buyer.
     * `INCONCLUSIVE` is not -- it covers the provider answering without
     * deciding, our own request being rejected as incomplete, and the check
     * being unreachable, none of which is the buyer's doing.
     *
     * `[20.1]`'s failure policy is what makes the distinction load-bearing:
     * only `FAILED` may ever be shown to a buyer as a failed check, and only
     * `FAILED` may block one under a blocking placement.
     */
    public const string VERIFICATION_PASSED = 'passed';

    public const string VERIFICATION_FAILED = 'failed';

    public const string VERIFICATION_INCONCLUSIVE = 'inconclusive';

    /** @var list<string> */
    public const array VERIFICATION_STATUSES = [
        self::VERIFICATION_PASSED,
        self::VERIFICATION_FAILED,
        self::VERIFICATION_INCONCLUSIVE,
    ];

    public ?string $opportunityId = null;

    public ?Attribution $attribution = null;

    /** The furthest funnel step this journey has completed (`[21.7]`). */
    public ?string $furthestStep = null;

    /** @var list<string> lifecycle event names already recorded server-side */
    public array $emrEvents = [];

    /** Whether the single return-visit reconciliation read has happened (`[4.14]`). */
    public bool $reconciled = false;

    /**
     * Which generation of the EMR read-model extractors produced the
     * reconciled facts above, or null when none did — a session minted here
     * (nothing to read yet) or one whose read has not succeeded.
     *
     * It exists because `reconciled` is durable: without a generation marker,
     * a session reconciled by an extractor that looked in the wrong place
     * keeps that wrong answer forever, and correcting the extractors later
     * would not repair the journeys already reconciled under the old ones.
     * {@see SessionResolver} owns the current value and the comparison;
     * bumping it there makes every affected journey re-read exactly once.
     */
    public ?int $readModelShape = null;

    /**
     * The durable copy of the cart (`[19.8]`), in {@see \AsterMD\Storefront\Domain\Cart}'s
     * serialised shape. The live copy lives in the PHP session; this one is
     * what a resume link restores from on a device that never held it
     * (`[21.5]`), which browser-scoped storage cannot do.
     *
     * @var array<string, mixed>
     */
    public array $cart = [];

    /**
     * Whether the EMR already holds a cart for this session, so the next
     * mutation is an update rather than a create (`[7.10]`, `[18.2]`).
     * Deliberately its own flag rather than an inference from
     * {@see self::$emrEvents}: the event vocabulary the read-model returns is
     * the EMR's to change, and a wrong inference here costs every subsequent
     * mirror call.
     */
    public bool $cartMirrored = false;

    /**
     * Answers given so far, keyed by teleform id then by field name
     * (`[10.27]`).
     *
     * Keyed by teleform rather than flat because a journey may pass through
     * more than one form — a pre-qualification form and an intake form ask
     * overlapping questions under names that are theirs to choose, and a flat
     * bag would let one form's answer silently become the other's.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $formAnswers = [];

    /**
     * Where each teleform stands: `'in_progress'` while answers are still
     * arriving, `'completed'` once the visitor has submitted it.
     *
     * Its own map rather than a flag inferred from the answers, because "has
     * answered something" and "has finished" are different questions and only
     * the second one may open the next funnel step.
     *
     * @var array<string, string>
     */
    public array $formStatus = [];

    /**
     * The id of the termination rule that disqualified this journey, or null
     * while it is still eligible (`[10.44a]`).
     *
     * Durable on purpose: the disqualification is the *server's* verdict, so
     * re-entering the funnel from a bookmark or a resume link must find it
     * still standing rather than start a fresh, unjudged attempt.
     */
    public ?string $disqualifiedRule = null;

    /** Which teleform's rule produced {@see self::$disqualifiedRule}, so the terminal page can name the right form. */
    public ?string $disqualifiedTeleform = null;

    /**
     * The buyer's checkout details, as submitted.
     *
     * Kept so a decline re-fills the form (`[13.30]`) and so a resumed journey
     * does not ask twice. Prefill precedence puts stored intake answers first
     * and this second (`[13.5c]`) — this is the working state, not the record.
     *
     * @var array<string, string>
     */
    public array $buyer = [];

    /**
     * The applied promotion: `{code, discount_cents}` (`[13.11]`).
     *
     * Dropped after a successful checkout so it cannot bleed into the upsell
     * flow (`[13.17]`).
     *
     * @var array{code: string, discount_cents: int}|null
     */
    public ?array $promotion = null;

    /**
     * A digest of the cart {@see self::$promotion} was quoted against.
     *
     * The provider owns the discount arithmetic (`[14.6b]`), which means the
     * figure in `$promotion` is an answer about one particular set of priced
     * lines. The cart can change after it — switching a plan and taking an
     * order bump are both sub-actions on the checkout page itself, and the cart
     * page is reachable throughout — and a discount quoted against a $270 plan
     * shown against a $30 one is not a rounding error, it is the buyer being
     * shown a price nobody will charge them.
     *
     * Stored as a digest of the cart rather than as a flag cleared by each
     * mutation site, because a flag only covers the mutations somebody
     * remembered: this notices a cart that changed anywhere, including in
     * another tab or on another page. A value that does not match the cart in
     * hand means the stored quote is not about this cart, and
     * {@see \AsterMD\Storefront\Checkout\CheckoutService} re-prices it or drops
     * it rather than showing it.
     */
    public ?string $promotionQuotedAgainst = null;

    /**
     * Slugs of order bumps the buyer accepted (`[27.1]`).
     *
     * Only the acceptance is recorded; the line itself lives in the cart,
     * because an accepted bump is an ordinary cart line and not a special case
     * (`[27.15]`).
     *
     * @var list<string>
     */
    public array $acceptedBumps = [];

    /**
     * What was consented to, in the wording that was shown (`[26.6]`).
     *
     * @var list<array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string}>
     */
    public array $consents = [];

    /**
     * The raw payment credential, for the duration of **one request only**.
     *
     * A plain property on a request-scoped object. It is the one field
     * deliberately excluded from {@see self::toArray()} and from
     * {@see self::fingerprint()}, because journey state is persisted to the
     * `sessions` table and a card in that row is a card written durably, which
     * `[15.8]` forbids outright.
     *
     * **It does not survive the request, and no part of this application
     * should be written as though it does.** An earlier revision of this
     * docblock claimed the field lived in the PHP session from checkout
     * success to journey end; it never did, and the temptation the claim
     * created was to "repair" it by persisting a card. That repair is
     * definitively wrong: the configured provider charges a later order
     * against an instrument it already holds (`[15.3]`, `[15.9]`), so there is
     * no card to hold and {@see self::$paymentHandle} carries the reference
     * instead (`[15.12]`).
     *
     * The consequence is stated rather than hidden: under an adapter that
     * declares raw-card carry-forward (`[15.5]`), a post-purchase upsell on
     * this application has nothing to charge and is skipped. That is the
     * correct trade -- holding a card across requests to sell an optional
     * add-on would put the whole deployment in full PCI scope.
     *
     * @var array<string, string>|null
     */
    public ?array $paymentCredential = null;

    /**
     * Provider references for orders placed on this journey.
     *
     * Durable because it is the only surviving proof that this journey bought
     * something: the cart is cleared the moment an order is placed (`[13.32]`),
     * so nothing else can tell the receipt and upsell steps apart from a
     * visitor who simply typed their URL.
     *
     * @var list<string>
     */
    public array $placedOrders = [];

    /**
     * The handle a later charge on this journey submits, in
     * {@see PaymentCredential::toStorable()}'s shape, or null when this journey
     * has none (`[15.12]`).
     *
     * The counterpart to {@see self::$paymentCredential} and the reason that
     * field does not need to be durable: this one is, legally, because a
     * reference-order handle is not a card. Assign through
     * {@see self::storeReusableCredential()} rather than directly -- the
     * setter is where the card refusal lives, and {@see self::toArray()}
     * repeats the refusal only because a public property can be written past
     * the setter.
     *
     * @var array{kind: string, handle: array<string, string>}|null
     */
    public ?array $paymentHandle = null;

    /**
     * The offer keys this journey will be shown, in configuration order
     * (`[16.2]`).
     *
     * Resolved once, at checkout, and then durable: re-reading the
     * configuration on each step would let a deploy mid-journey change what is
     * left in the queue, so a buyer part-way through would be offered
     * something the earlier steps had already answered for.
     *
     * @var list<string>
     */
    public array $upsellQueue = [];

    /**
     * How far through {@see self::$upsellQueue} this journey has got.
     *
     * A cursor rather than a shrinking list, so the queue stays the record of
     * what was planned and the position stays the record of what is left.
     * A cursor at or past the end of the queue means the upsell step is done
     * and the receipt is next.
     */
    public int $upsellCursor = 0;

    /**
     * What happened to each offer, keyed by offer key
     * (`[16.11]`, `[18.1]`).
     *
     * The only surviving trail of the post-purchase flow: the queue and the
     * cursor are working state and are cleared at completion, and the charged
     * upsells become order rows, but a *declined* offer leaves no row anywhere
     * else. Values are the `UPSELL_*` constants above.
     *
     * @var array<string, string>
     */
    public array $upsellOutcomes = [];

    /**
     * The price, in cents, each offer was **shown** at, keyed by offer key.
     *
     * The figure the buyer agreed to, frozen at the moment the page rendered
     * it, and the figure the charge is checked against. The idempotency key is
     * deliberately **not** built from it — a price that can move must not be
     * able to split the key of one purchase into two. Without it the price is re-read from live configuration when the
     * answer arrives, so a deploy landing between the render and the click
     * charges a number nobody was shown — and charges it *silently*, because
     * the expected total moves along with it and the discrepancy check has
     * nothing left to compare.
     *
     * The same reasoning as {@see self::$promotionQuotedAgainst}, applied one
     * step later in the funnel: a figure quoted against one state of the world
     * is not a figure that may be charged against another.
     *
     * @var array<string, int>
     */
    public array $upsellQuotes = [];

    /**
     * The retained receipt (`[4.17]`), in
     * {@see \AsterMD\Storefront\Completion\Receipt::toArray()}'s shape, or
     * null before completion has run.
     *
     * Held as the stored array rather than as the object because this is the
     * durable blob: a refresh of the receipt page reads it back and renders it
     * without touching the order tables or the provider, which is what makes
     * the page still work once the cart, the buyer and the credential are gone.
     *
     * @var array<string, mixed>|null
     */
    public ?array $receipt = null;

    /**
     * The identity-verification verdict this journey reached, or null while it
     * has not been asked (`[22.20]`).
     *
     * Keyed `status`, `at`, `basis` and `check`. `status` is one of the three
     * states the provider can actually produce, and collapsing them to a
     * boolean is the mistake this shape exists to prevent: a refused identity,
     * a request we built wrongly, and a provider that answered without
     * deciding are three different things, and only the first is a verdict
     * about the buyer.
     *
     * Durable because a blocking placement has to survive the visitor
     * reloading the page, and because `[22.20]` records the outcome regardless
     * of placement -- including the pre-payment ones, where there is no order
     * row to write it to yet. This is the only place the verdict is kept: the
     * `orders.verification_status` and `orders.verification_at` columns have
     * been in the schema since the first migration, but nothing writes them,
     * and a placement that runs before payment could not fill them in any
     * case. Read the verdict from here rather than from an order.
     *
     * @var array{status: string, at: string, basis: ?string, check: ?string}|null
     */
    public ?array $verification = null;

    /**
     * When the completion actions fired, or null while they have not
     * (`[17.1]`).
     *
     * A timestamp rather than a boolean because it is also the answer to "when
     * did this journey end", which a support call asks and nothing else here
     * records. {@see self::isComplete()} is the guard reading.
     */
    public ?string $completedAt = null;

    /**
     * Whether this journey's analytics session has been marked for teardown
     * (`[4.18]`).
     *
     * Durable and set at completion, because the teardown is deliberately *not*
     * immediate: the receipt page has to keep the session alive, so the cookie
     * is only cleared on the first later request that is not the receipt. That
     * request needs to find the decision already made, which is what this flag
     * is. It survives {@see self::wipeForCompletion()} for the same reason the
     * completion guard does -- a flag cleared by the wipe would never be read
     * by the request that is supposed to act on it.
     */
    public bool $sessionRetired = false;

    /**
     * @param array<string, mixed>      $journeyState the decoded `journey_state` column
     * @param array<string, mixed>|null $attribution  the decoded `attribution` column
     */
    public static function fromArray(array $journeyState, ?array $attribution, ?string $opportunityId): self
    {
        $state = new self();
        $state->opportunityId = $opportunityId;
        $state->attribution = $attribution === null ? null : Attribution::fromArray($attribution);
        $step = $journeyState['furthest_step'] ?? null;
        $state->furthestStep = is_string($step) && $step !== '' ? $step : null;
        $events = $journeyState['emr_events'] ?? [];
        $state->emrEvents = is_array($events)
            ? array_values(array_map(static fn (mixed $name): string => (string) $name, array_filter($events, 'is_scalar')))
            : [];
        $state->reconciled = ($journeyState['reconciled'] ?? false) === true;
        $shape = $journeyState['read_model_shape'] ?? null;
        $state->readModelShape = is_numeric($shape) ? (int) $shape : null;
        $cart = $journeyState['cart'] ?? [];
        $state->cart = is_array($cart) ? $cart : [];
        $state->cartMirrored = ($journeyState['cart_mirrored'] ?? false) === true;
        $state->formAnswers = self::nestedMap($journeyState['form_answers'] ?? null);
        $status = $journeyState['form_status'] ?? null;
        $state->formStatus = is_array($status)
            ? array_map(static fn (mixed $value): string => (string) $value, array_filter($status, 'is_scalar'))
            : [];
        $rule = $journeyState['disqualified_rule'] ?? null;
        $state->disqualifiedRule = is_string($rule) && $rule !== '' ? $rule : null;
        $teleform = $journeyState['disqualified_teleform'] ?? null;
        $state->disqualifiedTeleform = is_string($teleform) && $teleform !== '' ? $teleform : null;
        $state->buyer = self::stringMap($journeyState['buyer'] ?? null);
        $state->promotion = self::promotion($journeyState['promotion'] ?? null);
        $quotedAgainst = $journeyState['promotion_quoted_against'] ?? null;
        // A stored promotion whose cart digest did not survive is treated as
        // quoted against nothing, which forces a re-quote rather than trusting
        // a figure with no cart attached to it.
        $state->promotionQuotedAgainst = is_string($quotedAgainst) && $quotedAgainst !== '' ? $quotedAgainst : null;
        $state->acceptedBumps = self::stringList($journeyState['accepted_bumps'] ?? null);
        $state->consents = self::consentList($journeyState['consents'] ?? null);
        $state->placedOrders = self::stringList($journeyState['placed_orders'] ?? null);
        $state->paymentHandle = self::storableHandle($journeyState['payment_handle'] ?? null);
        $state->upsellQueue = self::stringList($journeyState['upsell_queue'] ?? null);
        $cursor = $journeyState['upsell_cursor'] ?? null;
        // A cursor that is not a number, or is negative, is read as "at the
        // start": the alternative is an index into the queue that resolves to
        // nothing, which would look like an exhausted queue and silently skip
        // every offer.
        //
        // {@see \AsterMD\Storefront\Upsell\UpsellQueue::current()} argues the
        // opposite for a negative value and answers null. Both are only
        // reachable from a corrupted column, and the repeat charge that would
        // follow either is caught by the idempotency key — but they disagree,
        // and the money argument there is the stronger one.
        $state->upsellCursor = is_numeric($cursor) ? max(0, (int) $cursor) : 0;
        $state->upsellOutcomes = self::stringMap($journeyState['upsell_outcomes'] ?? null);
        $state->upsellQuotes = self::centsMap($journeyState['upsell_quotes'] ?? null);
        $state->verification = self::verdict($journeyState['verification'] ?? null);
        $state->receipt = self::nullableMap($journeyState['receipt'] ?? null);
        $completedAt = $journeyState['completed_at'] ?? null;
        $state->completedAt = is_string($completedAt) && $completedAt !== '' ? $completedAt : null;
        $state->sessionRetired = ($journeyState['session_retired'] ?? false) === true;

        // `payment_credential` is deliberately absent: it is never written, so
        // there is never one to read back. {@see self::$paymentCredential}.

        return $state;
    }

    /**
     * The fields that belong in the durable `journey_state` JSON blob.
     *
     * `paymentCredential` is not among them and must never be added: this
     * array is what {@see \AsterMD\Storefront\Repository\SessionRepository}
     * writes to the `sessions` table, so a card here is a card at rest in the
     * database (`[15.8]`).
     *
     * @return array{furthest_step: ?string, emr_events: list<string>, reconciled: bool, read_model_shape: ?int, cart: array<string, mixed>, cart_mirrored: bool, form_answers: array<string, array<string, mixed>>, form_status: array<string, string>, disqualified_rule: ?string, disqualified_teleform: ?string, buyer: array<string, string>, promotion: array{code: string, discount_cents: int}|null, promotion_quoted_against: ?string, accepted_bumps: list<string>, consents: list<array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string}>, placed_orders: list<string>, payment_handle: array{kind: string, handle: array<string, string>}|null, upsell_queue: list<string>, upsell_cursor: int, upsell_outcomes: array<string, string>, upsell_quotes: array<string, int>, verification: array{status: string, at: string, basis: ?string, check: ?string}|null, receipt: array<string, mixed>|null, completed_at: ?string, session_retired: bool}
     */
    public function toArray(): array
    {
        return [
            'furthest_step' => $this->furthestStep,
            'emr_events' => $this->emrEvents,
            'reconciled' => $this->reconciled,
            'read_model_shape' => $this->readModelShape,
            'cart' => $this->cart,
            'cart_mirrored' => $this->cartMirrored,
            'form_answers' => $this->formAnswers,
            'form_status' => $this->formStatus,
            'disqualified_rule' => $this->disqualifiedRule,
            'disqualified_teleform' => $this->disqualifiedTeleform,
            'buyer' => $this->buyer,
            'promotion' => $this->promotion,
            'promotion_quoted_against' => $this->promotionQuotedAgainst,
            'accepted_bumps' => $this->acceptedBumps,
            'consents' => $this->consents,
            'placed_orders' => $this->placedOrders,
            // Coerced on the way out as well as on the way in. `$paymentHandle`
            // is a public property, so an assignment can bypass
            // {@see self::storeReusableCredential()}'s refusal -- and this
            // array is what the `sessions` table is handed, so this is the last
            // place a card can be stopped before it is at rest (`[15.8]`).
            'payment_handle' => self::storableHandle($this->paymentHandle),
            'upsell_queue' => $this->upsellQueue,
            'upsell_cursor' => $this->upsellCursor,
            'upsell_outcomes' => $this->upsellOutcomes,
            // Coerced on the way out as well as in, for the reason the handle
            // above is: this is a public mutable array, so what reaches the
            // column is whatever was last assigned to it rather than whatever
            // the setter allowed.
            'upsell_quotes' => self::centsMap($this->upsellQuotes),
            'verification' => $this->verification,
            'receipt' => $this->receipt,
            'completed_at' => $this->completedAt,
            'session_retired' => $this->sessionRetired,
        ];
    }

    /**
     * A stable digest of everything that gets persisted, so the save-back can
     * skip a write when nothing changed without each mutation site having to
     * remember to flag itself dirty.
     *
     * Built from {@see self::toArray()} rather than from the properties, which
     * is what keeps `paymentCredential` out of it for free: a card fed into a
     * hash is still a card handed to something that had no need of it, and a
     * digest that changed when the card did would make the credential decide
     * whether a database write happens at all.
     */
    public function fingerprint(): string
    {
        return (string) json_encode([
            $this->toArray(),
            $this->opportunityId,
            $this->attribution?->toArray(),
        ], JSON_UNESCAPED_SLASHES);
    }

    public function hasEmrEvent(string $name): bool
    {
        return in_array($name, $this->emrEvents, true);
    }

    /**
     * Records that this journey has now fired a lifecycle event.
     *
     * The deduplication in `[4.13]` only works if a locally-fired event is
     * written here as well as at the EMR: the next request asks
     * {@see self::hasEmrEvent()} before firing, so a name missing from this
     * list is fired a second time — a duplicate lead or a duplicate form
     * submission. Appending rather than assigning is what lets a locally-fired
     * event and a later reconciliation snapshot coexist.
     */
    public function recordEmrEvent(string $name): void
    {
        if (!$this->hasEmrEvent($name)) {
            $this->emrEvents[] = $name;
        }
    }

    /**
     * The answers held for one teleform, or an empty set when it has never
     * been answered — never null, so a caller can prefill a form without
     * first asking whether the visitor has been here before.
     *
     * @return array<string, mixed>
     */
    public function answersFor(string $teleformId): array
    {
        return $this->formAnswers[$teleformId] ?? [];
    }

    /**
     * Replaces the answers held for one teleform.
     *
     * Wholesale replacement is correct *here* because accumulation is
     * {@see \AsterMD\Storefront\Forms\AnswerSet}'s job (`[10.27]`): callers
     * merge into the set they read back from {@see self::answersFor()} and
     * store the result, so the merge rule lives in one place instead of being
     * half-implemented at the storage boundary too.
     *
     * @param array<string, mixed> $answers
     */
    public function storeAnswers(string $teleformId, array $answers): void
    {
        $this->formAnswers[$teleformId] = $answers;
        $this->formStatus[$teleformId] ??= self::FORM_IN_PROGRESS;
    }

    /** Records that a teleform has been submitted, which is what lets the next funnel step open. */
    public function markFormCompleted(string $teleformId): void
    {
        $this->formStatus[$teleformId] = self::FORM_COMPLETED;
    }

    public function formCompleted(string $teleformId): bool
    {
        return ($this->formStatus[$teleformId] ?? null) === self::FORM_COMPLETED;
    }

    public function isDisqualified(): bool
    {
        return $this->disqualifiedRule !== null;
    }

    /**
     * Records the server's verdict that this journey is not eligible,
     * naming the rule so the terminal page can state a reason and support can
     * tell which question ended it (`[10.42]`).
     */
    public function recordDisqualification(string $teleformId, string $ruleId): void
    {
        $this->disqualifiedRule = $ruleId;
        $this->disqualifiedTeleform = $teleformId;
    }

    /**
     * Lifts a disqualification.
     *
     * Needed because a disqualification is durable: without a way to clear it,
     * the "start over" the terminal page offers would hand the visitor a
     * funnel that is still blocked on an answer they have since changed.
     */
    public function clearDisqualification(): void
    {
        $this->disqualifiedRule = null;
        $this->disqualifiedTeleform = null;
    }

    /**
     * Replaces the buyer's checkout details with what was just submitted.
     *
     * Wholesale replacement, like {@see self::storeAnswers()}: the checkout
     * form posts every field it owns on every sub-action (`[27.10]`), so a
     * merge here would resurrect a value the buyer had just cleared.
     *
     * @param array<string, string> $buyer
     */
    public function storeBuyer(array $buyer): void
    {
        $this->buyer = $buyer;
    }

    /**
     * The buyer's checkout details, or an empty set when none were submitted —
     * never null, so a caller can prefill without first asking whether the
     * visitor has been here before.
     *
     * @return array<string, string>
     */
    public function buyer(): array
    {
        return $this->buyer;
    }

    /**
     * Drops the raw payment credential.
     *
     * **One caller, and the count is a fact rather than an aspiration.** An
     * earlier revision of this docblock named three -- teardown, a decline at
     * checkout, and session expiry -- and only the decline ever existed
     * ({@see \AsterMD\Storefront\Checkout\CheckoutService}'s stopped path,
     * where `[15.8]` wipes a card that a failed charge has left held for
     * nothing). The other two never needed a caller: teardown is
     * {@see self::wipeForCompletion()}, which clears the field along with
     * everything else, and session expiry drops the whole request-scoped
     * object with the field on it.
     *
     * Still its own method rather than an assignment at the call site, because
     * "forget the card" is a rule with a spec reference behind it and a bare
     * `= null` in a payment path says nothing about why.
     */
    public function wipePaymentCredential(): void
    {
        $this->paymentCredential = null;
    }

    /**
     * Records the handle a later charge on this journey will submit
     * (`[15.12]`, `[15.13]`).
     *
     * Durable, and legal to be durable, because a reference-order handle is
     * **not a card**: it is an identifier the provider issued, useless without
     * the provider, and refused by it unless both halves agree. `[15.8]`'s
     * prohibition is on the card, and the whole point of `[15.10]` preferring
     * this strategy is that there is no card to prohibit.
     *
     * A card is refused, and the refusal is
     * {@see PaymentCredential::toStorable()}'s rather than a second copy of the
     * same rule here.
     */
    public function storeReusableCredential(?PaymentCredential $credential): void
    {
        $this->paymentHandle = $credential === null ? null : $credential->toStorable();
    }

    /**
     * The handle to charge against, or null when this journey has none.
     *
     * Null is an ordinary answer, not an error: a journey whose provider does
     * not support reuse has none, and so does one resumed after the provider
     * dropped the vault entry. The caller's obligation is to skip the offer,
     * never to trap the buyer (`[16.11]`'s reasoning applied one step earlier).
     */
    public function reusableCredential(): ?PaymentCredential
    {
        return $this->paymentHandle === null ? null : PaymentCredential::fromStorable($this->paymentHandle);
    }

    /**
     * Whether the completion actions have already fired for this journey
     * (`[17.1]`).
     *
     * The guard flag, and it **deliberately survives the wipe** -- that is
     * `[4.15]`'s explicit exception, and without it a refresh of the receipt
     * would re-fire the final funnel event, re-sync the treatments and wipe a
     * journey that has nothing left to wipe.
     */
    public function isComplete(): bool
    {
        return $this->completedAt !== null;
    }

    /**
     * Ends the journey, keeping only what `[4.15]`-`[4.17]` say to keep.
     *
     * **Order matters at the call site, not here.** Most of what the completion
     * actions need -- the first-touch source the treatment sync forwards
     * (`[17.4]`), the buyer's shipping block -- is cleared by this method, so it
     * runs *after* those actions and never before. This method cannot enforce
     * that; the completion step's own docblock states it and its test pins it.
     *
     * The one exception is worth naming, because `[17.6]` reads as though it
     * were the reason for the ordering and is not: **the opportunity reference
     * survives.** It is the identifier of a record in the EMR rather than
     * anything the visitor gave us, it lives in its own column, and a
     * reconciliation sweep reaching this journey later has nothing else to join
     * on (`[21.8]`). `[4.16]`'s "nothing may leak into a subsequent journey"
     * still holds: a subsequent journey is a different `sessions` row, so
     * keeping this one on this row leaks nothing anywhere.
     *
     * What survives: the receipt, so a refresh still shows the order
     * (`[4.17]`); the completion guard, so the actions do not re-fire; the
     * retirement flag, so the request that clears the session cookie still
     * finds the decision (`[4.18]`); the placed-order list, which is what
     * keeps the receipt step reachable at all once the cart is gone; and the
     * upsell outcomes, which are the record of what was offered and answered
     * and are no use to a subsequent journey but are the only trail of this
     * one; and the opportunity reference, for the reason above.
     *
     * What goes: the cart, the answers, the buyer contact, the consents, the
     * promotion, the attribution, and both credential fields (`[4.16]`).
     * Nothing may leak into a subsequent journey.
     */
    /**
     * Records an identity-verification outcome on the journey (`[22.20]`).
     *
     * Refuses a status outside the vocabulary rather than storing it, because
     * the guard reads this by comparing against {@see self::VERIFICATION_PASSED}
     * and an unrecognised value would read as "not passed" — which is safe for
     * a blocking placement and silently wrong for the compliance record the
     * rule actually asks for.
     */
    public function recordVerification(string $status, ?string $basis = null, ?string $check = null): void
    {
        if (!in_array($status, self::VERIFICATION_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown verification status "%s".', $status));
        }

        $this->verification = [
            'status' => $status,
            'at' => gmdate('c'),
            'basis' => $basis,
            'check' => $check,
        ];
    }

    /**
     * Whether this journey holds a passing identity verdict.
     *
     * Deliberately not "did not fail": an inconclusive answer and a journey
     * that was never asked both answer false here, so a blocking placement
     * cannot be satisfied by the check failing to run. Whether that matters is
     * the caller's decision — a non-blocking placement never asks this.
     */
    public function verificationPassed(): bool
    {
        return ($this->verification['status'] ?? null) === self::VERIFICATION_PASSED;
    }

    public function wipeForCompletion(): void
    {
        $this->cart = [];
        $this->cartMirrored = false;
        $this->formAnswers = [];
        $this->formStatus = [];
        $this->buyer = [];
        $this->promotion = null;
        $this->promotionQuotedAgainst = null;
        $this->acceptedBumps = [];
        $this->consents = [];
        $this->paymentCredential = null;
        $this->paymentHandle = null;
        $this->attribution = null;
        $this->upsellQueue = [];
        $this->upsellCursor = 0;
        $this->upsellQuotes = [];
    }

    /**
     * Records that an order was placed on this journey, naming it by the
     * provider's own reference.
     *
     * Deduplicated because a retried report of the same placement must not
     * look like a second order: the receipt and upsell steps are gated on this
     * list being non-empty and count its entries when naming what was bought.
     */
    public function recordPlacedOrder(string $reference): void
    {
        if (!in_array($reference, $this->placedOrders, true)) {
            $this->placedOrders[] = $reference;
        }
    }

    /**
     * Reads back a persisted credential handle, or null when what is there is
     * not one -- and, crucially, null when what is there is a **card**.
     *
     * Used on both sides of the round trip, which is the point. On the way in
     * it is the ordinary defensive coercion the rest of
     * {@see self::fromArray()} applies to a JSON column written by some
     * previous release. On the way out it is the guard of last resort: a card
     * assigned straight to {@see self::$paymentHandle}, past
     * {@see self::storeReusableCredential()}, would otherwise be written to
     * the `sessions` table by {@see self::toArray()} and be at rest in the
     * database, which `[15.8]` forbids outright.
     *
     * Refused rather than repaired: a handle with no `kind`, or with an empty
     * map behind it, cannot be submitted to any provider, so keeping it would
     * only defer the failure to the charge.
     *
     * @return array{kind: string, handle: array<string, string>}|null
     */
    private static function storableHandle(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $kind = $raw['kind'] ?? null;
        if (!is_string($kind) || $kind === '' || $kind === PaymentCredential::KIND_CARD) {
            return null;
        }

        $handle = self::stringMap($raw['handle'] ?? null);
        if ($handle === []) {
            return null;
        }

        return ['kind' => $kind, 'handle' => $handle];
    }

    /**
     * Keeps a decoded value only if it is a non-empty map, in the same
     * defensive spirit as the rest of {@see self::fromArray()}.
     *
     * An empty array reads back as null rather than as an empty receipt,
     * because the receipt step asks "is there one" and an empty one would
     * answer yes and then render a page with no order on it.
     *
     * @return array<string, mixed>|null
     */
    private static function nullableMap(mixed $raw): ?array
    {
        return is_array($raw) && $raw !== [] ? $raw : null;
    }

    /**
     * Coerces a decoded identity verdict, or answers null.
     *
     * A verdict that does not parse becomes "never asked" rather than any of
     * the three real statuses. That is the only safe default: reading a
     * corrupted column as `passed` opens a blocking placement to anyone who
     * can damage the row, and reading it as `failed` refuses a buyer on the
     * strength of a parse error. "Never asked" simply asks again.
     *
     * @return array{status: string, at: string, basis: ?string, check: ?string}|null
     */
    private static function verdict(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $status = $raw['status'] ?? null;
        $at = $raw['at'] ?? null;

        if (!is_string($status) || !in_array($status, self::VERIFICATION_STATUSES, true)) {
            return null;
        }

        if (!is_string($at) || $at === '') {
            return null;
        }

        $basis = $raw['basis'] ?? null;
        $check = $raw['check'] ?? null;

        return [
            'status' => $status,
            'at' => $at,
            'basis' => is_string($basis) && $basis !== '' ? $basis : null,
            'check' => is_string($check) && $check !== '' ? $check : null,
        ];
    }

    /**
     * Coerces a decoded map to `string => int`, dropping anything that cannot
     * be one.
     *
     * A quote that no longer parses is dropped rather than defaulted, because
     * every default is wrong here: zero would charge nothing, and the live
     * price is exactly the figure that must not be assumed. A missing quote
     * makes the offer re-render, which asks the buyer again — the only safe
     * answer.
     *
     * @return array<string, int>
     */
    private static function centsMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (is_numeric($value)) {
                $map[(string) $key] = (int) $value;
            }
        }

        return $map;
    }

    /**
     * Coerces a decoded map to `string => string`, in the same defensive
     * spirit as the rest of {@see self::fromArray()}.
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (is_scalar($value)) {
                $map[(string) $key] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * Coerces a decoded value to a list of strings, dropping anything that
     * cannot be one and re-indexing so the result is a genuine list.
     *
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            array_filter($raw, 'is_scalar'),
        ));
    }

    /**
     * Reads back a stored promotion, or null when what was stored is no longer
     * a promotion.
     *
     * A half-valid promotion is discarded rather than repaired: a code with no
     * discount would show the buyer a badge for money they are not saving, and
     * a discount with no code could never be explained on the receipt.
     *
     * @return array{code: string, discount_cents: int}|null
     */
    private static function promotion(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $code = $raw['code'] ?? null;
        $discount = $raw['discount_cents'] ?? null;

        if (!is_string($code) || $code === '' || !is_numeric($discount)) {
            return null;
        }

        return ['code' => $code, 'discount_cents' => (int) $discount];
    }

    /**
     * Reads back the recorded consents, keeping only entries that still carry
     * the whole record.
     *
     * An entry missing its wording is dropped rather than kept with a blank,
     * because the point of `[26.6]` is that the stored consent proves what the
     * visitor was shown; a record that cannot do that is worse than none.
     *
     * @return list<array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string}>
     */
    private static function consentList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $records = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_string($entry['key'] ?? null) || ($entry['key'] ?? '') === '') {
                continue;
            }

            $records[] = [
                'key' => (string) $entry['key'],
                'granted' => ($entry['granted'] ?? false) === true,
                'copy_version' => (string) ($entry['copy_version'] ?? ''),
                'copy_shown' => (string) ($entry['copy_shown'] ?? ''),
                'at' => (string) ($entry['at'] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * Keeps only the per-teleform entries that are themselves maps, in the
     * same defensive spirit as the rest of {@see self::fromArray()}: the JSON
     * column is decoded from whatever a previous release wrote, so a shape
     * that no longer parses must degrade to "no answers yet" rather than
     * propagate a wrong type into a form renderer.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function nestedMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $map[(string) $key] = $value;
            }
        }

        return $map;
    }
}

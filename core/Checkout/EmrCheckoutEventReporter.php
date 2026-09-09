<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Enum\CheckoutEvent;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see CheckoutEventReporter} over the EMR's checkout-event funnel and the
 * local `events` table.
 *
 * **This is a swallow-and-log boundary**, the pattern
 * {@see \AsterMD\Storefront\Forms\LeadWriter} established: tracking, analytics
 * and reporting must never break the storefront (`[20.1]`, `[10.26]`), while
 * payment and eligibility must stop the buyer. An order that was charged is
 * not un-charged because the EMR did not hear about it, so every method here
 * catches everything, returns nothing, and leaves the checkout path unable to
 * branch on the result.
 *
 * **Two destinations, and they are not redundant.** The EMR keeps *one*
 * checkout-event record per session and updates it in place: `create()` opens
 * the funnel and every later `update()` overwrites `event` on the same record,
 * so a decline followed by a success leaves only the success. That is fine for
 * the EMR's own funnel reporting and useless as an audit trail, which is why
 * `[18.1]`'s trail is the local `events` table — append-only, and the only
 * place a decline that was later retried successfully can still be read.
 *
 * **The local row is written whatever the analytics flag says, and whether or
 * not there is a session to file it under.** `[18.1]`'s trail is the
 * storefront's own record rather than analytics, and an analytics-off
 * deployment has no session uuid at all — so a guard that skipped the local
 * write along with the EMR call meant no `checkout.*` row was ever written on
 * exactly the deployment this class is bound un-flagged to serve. What such a
 * row is filed under is {@see self::NO_SESSION}, which is the absence recorded
 * literally rather than an identifier invented to fill a column (`[20.8]`).
 *
 * **The visit event has to come first.** `update()` throws `NotFoundException`
 * when no `create()` preceded it, so the funnel is opened on the checkout
 * render rather than on submit. A journey that somehow reaches an order event
 * without one loses that event; it is logged and nothing else happens.
 *
 * **Dollars are converted here and nowhere else.** The EMR documents floats
 * and accepts an integer cents value verbatim — a recorded `order_value: 12000`
 * was stored as `12000` — so a skipped conversion silently multiplies every
 * reported order by a hundred and nothing downstream complains.
 * {@see Totals::dollars()} is the one-way exit, applied at this boundary only
 * and pinned by its own test.
 *
 * **A failure never logs the provider's message.** The SDK builds it from the
 * EMR's own `message` field, and this payload carries an order's totals and
 * references; the exception class and status code answer the questions an
 * outage actually raises and cannot carry provider-supplied free text
 * (`[20.6]`).
 */
final class EmrCheckoutEventReporter implements CheckoutEventReporter
{
    /**
     * What the local trail files a checkout under when there is no analytics
     * session to file it under.
     *
     * Empty rather than a placeholder word, because `events.session_uuid` is
     * NOT NULL and an invented value would be indistinguishable from a real
     * identifier to anyone reading the table later. Nothing is ever reported
     * to the EMR under it -- the EMR half is skipped entirely when there is no
     * session -- so it cannot become the synthetic identifier `[20.8]` forbids.
     */
    private const string NO_SESSION = '';

    private ?AsterMDClient $client = null;

    /** @var \Closure(): ?string the visitor's own user agent, for the treatment sync's attribution */
    private readonly \Closure $userAgent;

    /**
     * @param \Closure(): ?string $utmSource the journey's first-touch source, resolved lazily so this class never holds journey state
     * @param string $currency sent on the create; the EMR merges rather than replaces, so it survives every later update
     * @param bool $reportToEmr whether the EMR half runs at all; the local trail is written either way
     * @param (\Closure(): ?string)|null $userAgent injected so a test does not read the ambient request
     */
    public function __construct(
        private readonly ClientFactory $clients,
        private readonly EventRepository $events,
        private readonly OrderRepository $orders,
        private readonly OperatorLog $log,
        private readonly \Closure $utmSource,
        private readonly string $currency = 'USD',
        private readonly bool $reportToEmr = true,
        private readonly ?ClientInterface $httpClient = null,
        ?\Closure $userAgent = null,
    ) {
        $this->userAgent = $userAgent ?? static function (): ?string {
            // The SDK forwards this verbatim so the import is attributed to the
            // buyer's device rather than to this server, and asks the consuming
            // application for it because it has no request of its own. This
            // runs inside the request that placed the order, so the ambient
            // value is that buyer's.
            $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

            return $agent === '' ? null : $agent;
        };
    }

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
        $this->record($sessionUuid, 'checkout.visited', [
            'subtotal_cents' => $totals->subtotalCents,
            'total_cents' => $totals->totalCents,
        ]);

        if ($sessionUuid === null) {
            return;
        }

        $this->send($sessionUuid, 'checkout_visited', function (AsterMDClient $client) use ($sessionUuid, $totals): void {
            $client->checkoutEvents()->create($sessionUuid, self::money($totals) + ['currency' => $this->currency]);
        });
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
        $this->record($sessionUuid, 'checkout.order_placed', [
            'references' => implode(',', $orderReferences),
            'total_cents' => $totals->totalCents,
            'payment_method' => $paymentMethod,
        ]);

        if ($sessionUuid === null) {
            return;
        }

        $this->send($sessionUuid, 'order_placed', function (AsterMDClient $client) use ($sessionUuid, $totals, $paymentMethod, $orderReferences): void {
            $client->checkoutEvents()->update($sessionUuid, CheckoutEvent::OrderPlaced, self::money($totals) + [
                'payment_method' => $paymentMethod,
                'currency' => $this->currency,
                'provider_order_id' => $orderReferences,
            ]);
        });

        $this->treatmentsSynced($sessionUuid, $orderReferences);
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
        // The reason is the provider's own buyer-safe text and is recorded
        // because a decline the buyer retried past is otherwise unanswerable
        // from either system: the EMR's single record has been overwritten by
        // the success, and no order row exists for a charge that never landed.
        //
        // It is scrubbed on the way in. `events.payload` is durable and
        // `reason` is not a key {@see OperatorLog::redact()} names, so a
        // gateway that put a card number inside its own sentence would
        // otherwise write one to a table (`[15.8]`).
        $this->record($sessionUuid, 'checkout.order_declined', [
            'reference' => $reference,
            'total_cents' => $totals->totalCents,
            'payment_method' => $paymentMethod,
            'reason' => CardScrubber::scrub($reason),
        ]);

        if ($sessionUuid === null) {
            return;
        }

        $this->send($sessionUuid, 'order_declined', function (AsterMDClient $client) use ($sessionUuid, $totals, $paymentMethod, $reference): void {
            $client->checkoutEvents()->update($sessionUuid, CheckoutEvent::OrderDeclined, self::money($totals) + [
                'payment_method' => $paymentMethod,
                'currency' => $this->currency,
                'provider_order_id' => $reference === null ? [] : [$reference],
            ]);
        });
    }

    /**
     * One line on `[18.1]`'s local trail, written whether or not this journey
     * has an analytics session and whether or not the EMR half runs at all.
     *
     * {@see EventRepository::append()} swallows its own failures, so an
     * unwritable audit line cannot reach the buyer from here either.
     *
     * @param array<string, mixed> $payload
     */
    private function record(?string $sessionUuid, string $name, array $payload): void
    {
        $this->events->append($sessionUuid ?? self::NO_SESSION, $name, $payload);
    }

    /**
     * Tells the EMR about orders the aggregator has already charged, and stamps
     * what it returns onto the local rows.
     *
     * This is the order path the architecture settled: the storefront charges
     * at the aggregator, then the EMR learns of the order. It sits inside the
     * same swallow as everything else here, which is exactly why the stamp
     * matters — an order the provider charged but the EMR never learned of is
     * the set of rows whose `treatment_reference` is still null, and a later
     * reconciliation sweep has nothing else to find them by (`[18.1]`).
     *
     * **The answer is read, not discarded.** It carries the EMR's own
     * treatment id, which `[19.2]` and `[19.9]` say the order record holds;
     * see {@see self::treatmentIdentifierIn()} for the shape and
     * {@see self::stampTreatmentReference()} for what happens when it cannot
     * be read.
     *
     * **Called from two places, deliberately.** `[17.2]` batches the whole
     * journey's orders at the receipt, and that call is the one `[18.1]`'s
     * taxonomy names. The checkout also calls it with the single order it just
     * placed, which `[17.3]` warns against on the grounds that doing both
     * duplicates records — and that grounds does not hold against this EMR.
     * Measured: four calls from an empty channel, including a repeat of one
     * order id and then a two-id batch, left **one** treatment record, with the
     * references accumulating in place. The session is the key.
     *
     * So doing both is free, and it buys something real: `[17.8]`'s
     * abandonment gap is that a buyer who closes the browser mid-upsell never
     * reaches the receipt and their treatment is never reported. Syncing at
     * checkout means the *prescription* order — the one a clinician has to see
     * — is reported the moment it is charged, and only the optional add-ons
     * wait for the receipt.
     *
     * An empty batch is not sent: the endpoint answers 400 for it ("No
     * suborders found in any of the provided orders"), which would be a warning
     * line about nothing.
     *
     * **The local trail row is the one row here that is conditional on the
     * call having happened.** Every other event on the trail records something
     * the *storefront* did and is written whatever the analytics flag says;
     * this one records something the *EMR* was told, and a row asserting that
     * on a deployment whose EMR half is switched off, or after a sync that
     * failed, would be false. The failure has its own line
     * (`checkout.treatment_sync_failed`) instead.
     *
     * @param list<string> $orderReferences
     */
    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
        if ($sessionUuid === null || !$this->reportToEmr || $orderReferences === []) {
            return;
        }

        try {
            $response = $this->client()->treatments()->sync(
                $sessionUuid,
                $orderReferences,
                ($this->utmSource)(),
                ($this->userAgent)(),
            );
        } catch (\Throwable $e) {
            $this->log->warning('checkout.treatment_sync_failed', [
                'session' => $sessionUuid,
                'orders' => count($orderReferences),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return;
        }

        $this->record($sessionUuid, 'checkout.treatments_synced', [
            'references' => implode(',', $orderReferences),
            'orders' => count($orderReferences),
        ]);

        $treatment = self::treatmentIdentifierIn($response);

        foreach ($orderReferences as $reference) {
            $this->stampTreatmentReference($reference, $treatment ?? $reference);
        }
    }

    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
    {
        $this->upsellEvent($sessionUuid, 'offered', CheckoutEvent::UpsellOffered, $slug, $name);
    }

    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
    {
        $this->upsellEvent($sessionUuid, 'accepted', CheckoutEvent::UpsellAccepted, $slug, $name);
    }

    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
    {
        $this->upsellEvent($sessionUuid, 'declined', CheckoutEvent::UpsellDeclined, $slug, $name);
    }

    /**
     * `[27.13]`, on the local trail alone.
     *
     * Two event names rather than one with a boolean in the payload, because
     * `[18.1]`'s trail is read by name: "which bumps did buyers withdraw" is a
     * question about a `checkout.*` name, and a flag inside a JSON column is not
     * something the trail can be grouped by.
     *
     * The slug reaches the durable row as the opaque reference
     * {@see EventRepository::productReferences()} substitutes for it — a real
     * one is the drug and its strength, and a bump is a product like any other.
     * Grouping by product survives that; the drug name does not appear.
     */
    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
    {
        $this->record($sessionUuid, $accepted ? 'checkout.bump_accepted' : 'checkout.bump_withdrawn', [
            'slug' => $slug,
        ]);
    }

    /**
     * `[17.5]`'s final funnel event.
     *
     * The EMR keeps one checkout-event record per session and every `update()`
     * overwrites it, so this is the state that record is left in — which is why
     * the figures here, not the ones the placement reported, are the ones the
     * EMR ends up holding. The placement reports the total it quoted, because
     * that is all it knows at the moment it reports; a discrepancy is only
     * measurable afterwards, and by the time this fires the order rows carry
     * the debit.
     *
     * `payment_method` and `opportunity_id` are omitted rather than sent empty
     * when there is nothing to send. The EMR accepts a fixed set of payment
     * methods and an absent key is a fact it can read; `''` is a value it
     * cannot, and `[20.8]`'s prohibition on inventing an identifier applies to
     * the CRM link for the same reason it applies to the session.
     *
     * @param list<string> $orderReferences
     */
    public function journeyCompleted(
        ?string $sessionUuid,
        bool $anyOrderPlaced,
        int $orderValueCents,
        int $paidTotalCents,
        ?string $paymentMethod,
        array $orderReferences,
        ?string $opportunityId,
    ): void {
        $event = $anyOrderPlaced ? CheckoutEvent::OrderPlaced : CheckoutEvent::OrderDeclined;

        $this->record($sessionUuid, 'checkout.completed', [
            'event' => $event->value,
            'references' => implode(',', $orderReferences),
            'order_value_cents' => $orderValueCents,
            'paid_total_cents' => $paidTotalCents,
            'payment_method' => $paymentMethod,
            'opportunity_id' => $opportunityId,
        ]);

        if ($sessionUuid === null) {
            return;
        }

        $payload = [
            'order_value' => Totals::dollars($orderValueCents),
            'order_total' => Totals::dollars($paidTotalCents),
            'currency' => $this->currency,
            'provider_order_id' => $orderReferences,
        ];

        if ($paymentMethod !== null) {
            $payload['payment_method'] = $paymentMethod;
        }

        if ($opportunityId !== null) {
            $payload['opportunity_id'] = $opportunityId;
        }

        $this->send($sessionUuid, $event->value, static function (AsterMDClient $client) use ($sessionUuid, $event, $payload): void {
            $client->checkoutEvents()->update($sessionUuid, $event, $payload);
        });
    }

    /**
     * One offer's fate, on both destinations (`[16.7]`).
     *
     * The local row is unconditional for the reason {@see self::record()}
     * gives — `[18.1]`'s trail is the storefront's own record, and the
     * deployment this class is bound un-flagged to serve has no session uuid at
     * all — while the EMR half needs the session it keys the funnel on.
     *
     * **The two destinations are told different amounts, deliberately.** The
     * EMR is the clinical system and `[16.7]` gives it the identifier and the
     * name. The durable local row gets neither verbatim: an offer names the
     * product it was made for, a real slug is the drug and its strength, and
     * `[20.14]` keeps that off a table keyed by `session_uuid` that outlives
     * the request. The name is not passed at all, and the slug arrives as the
     * opaque reference {@see EventRepository::productReferences()} substitutes
     * for it, which is still enough to ask which offers were declined.
     */
    private function upsellEvent(?string $sessionUuid, string $outcome, CheckoutEvent $event, string $slug, string $name): void
    {
        $this->record($sessionUuid, 'checkout.upsell_' . $outcome, [
            'slug' => $slug,
        ]);

        if ($sessionUuid === null) {
            return;
        }

        $this->send($sessionUuid, $event->value, static function (AsterMDClient $client) use ($sessionUuid, $event, $slug, $name): void {
            $client->checkoutEvents()->update($sessionUuid, $event, [
                'product_id' => $slug,
                'product_name' => $name,
            ]);
        });
    }

    /**
     * Records the EMR's own treatment identifier against one local order,
     * falling back to the provider's reference when it cannot be read.
     *
     * `[19.2]` says the order record carries the EMR treatment reference and
     * `[19.9]` says it is written back when the sync succeeds, and the id is
     * in the answer the EMR has already given: `data()[0]['_id']`, on a
     * response this class used to discard. Reading it is what makes the column
     * hold what its name says.
     *
     * **The fallback is the part that must not be lost.** Whatever is written,
     * the invariant the whole reconciliation predicate rests on is that null
     * means "the EMR was never told" — never "told, and the answer was a shape
     * we did not expect". A sync the EMR accepted is a sync that happened, so
     * an unreadable body still clears the signal, using the identifier both
     * systems already share. `[21.8]`'s sweep is therefore unchanged by this.
     *
     * **The value is not unique per order, by design.** Recorded: one session's
     * orders accumulate into a single treatment record, with every provider
     * reference listed under `external_refs.vrio`, so a batch of two orders
     * stamps the same treatment id on both rows. That is the truth about the
     * EMR's model rather than a collision, and nothing queries this column by
     * value.
     */
    private function stampTreatmentReference(string $orderReference, string $treatmentReference): void
    {
        try {
            $row = $this->orders->findByReference($orderReference);
            if ($row !== null) {
                $this->orders->markTreatmentSynced($row['id'], $treatmentReference);
            }
        } catch (\Throwable $e) {
            $this->log->warning('checkout.treatment_stamp_failed', [
                'reference' => $orderReference,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);
        }
    }

    /**
     * The EMR treatment id out of a sync response, or null when the body does
     * not carry a usable one.
     *
     * **`data()` is a list, not an object**, and that is recorded rather than
     * inferred: the sync envelope's `data` holds one element per treatment
     * record and the id lives at `data()[0]['_id']`. An earlier written summary
     * of the same recording flattened the record's fields to top-level paths;
     * reading them that way returns null on every order and does it silently,
     * with no error anywhere. Hence the index, and hence the test that pins the
     * flattened shape as one this must refuse.
     *
     * Anything that is not a non-empty string is refused rather than cast. An
     * `_id` arriving as a number or an object is not an identifier, and writing
     * a cast of one would record a value no later lookup could use while
     * reporting success.
     */
    private static function treatmentIdentifierIn(\AsterMD\Sdk\Response $response): ?string
    {
        $first = $response->data()[0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        $id = $first['_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * One EMR call, with the boundary's whole contract applied to it.
     *
     * @param \Closure(AsterMDClient): void $call
     */
    private function send(string $sessionUuid, string $event, \Closure $call): void
    {
        if (!$this->reportToEmr) {
            return;
        }

        try {
            $call($this->client());
        } catch (\Throwable $e) {
            $this->log->warning('checkout.event_report_failed', [
                'session' => $sessionUuid,
                'event' => $event,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);
        }
    }

    /**
     * The two money fields, in the decimal dollars the EMR documents.
     *
     * `order_value` is the order before the discount and `order_total` is what
     * the buyer paid, which is the EMR's own before/after-adjustment split.
     * This theme prices all-inclusive (`[13.35]`), so there is no tax or
     * shipping line between them — the only difference is the promotion.
     *
     * @return array{order_value: float, order_total: float}
     */
    private static function money(Totals $totals): array
    {
        return [
            'order_value' => Totals::dollars($totals->subtotalCents),
            'order_total' => Totals::dollars($totals->totalCents),
        ];
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}

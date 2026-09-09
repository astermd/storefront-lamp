<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * What one order search returned, or why it returned nothing.
 *
 * **An empty search and a failed search are different objects on purpose.**
 * Both hand back zero orders, and `[21.9a]`'s sweep reads zero orders as
 * "nothing was lost". If a provider outage produced the same value, the sweep
 * would report all-clear on exactly the runs it could not see -- the silent
 * failure the whole reconciliation exists to end. So {@see self::$ok} is the
 * only thing a caller may draw a conclusion from, and it is false for every
 * shape of not-answered: an unsupported adapter, a transport error, a rejected
 * request, a body that would not decode.
 *
 * A search never throws for a provider-side outcome, which is the same
 * contract {@see PaymentAdapter::place()} holds and for a related reason: this
 * one runs offline, and `[20.1]` says a provider outage is a logged
 * non-event, not a crashed job.
 */
final readonly class OrderSearchResult
{
    /** The adapter does not implement an order search at all -- not a failure, just nothing to sweep. */
    public const string UNSUPPORTED = 'unsupported';

    /**
     * @param list<ProviderOrder> $orders
     * @param int                 $reportedTotal how many orders the provider says match the window, which may exceed
     *                                           what the limit let it return
     */
    private function __construct(
        public bool $ok,
        public array $orders,
        public int $reportedTotal,
        public ?string $failureReason,
    ) {
    }

    /**
     * A search the provider answered, including one it answered with nothing.
     *
     * @param list<ProviderOrder> $orders
     * @param ?int                $reportedTotal null when the provider sent no total, in which case what came back
     *                                           is all there is known to be
     */
    public static function of(array $orders, ?int $reportedTotal = null): self
    {
        return new self(true, $orders, $reportedTotal ?? count($orders), null);
    }

    /** A search that was not answered. The reason is an operator-facing code, never provider free text. */
    public static function failed(string $reason): self
    {
        return new self(false, [], 0, $reason);
    }

    /** An adapter with no order search. Its own reason so a caller can tell it from an outage. */
    public static function unsupported(): self
    {
        return self::failed(self::UNSUPPORTED);
    }

    /**
     * Whether the window held more orders than the limit returned.
     *
     * Worth its own question because a truncated sweep is a sweep that
     * examined a prefix and reported a clean bill of health for the rest.
     *
     * **A prefix of the provider's own ordering, which nothing here chooses.**
     * No sort is asked for — see {@see \AsterMD\Storefront\Payment\Vrio\VrioAdapter::searchOrders()},
     * which sends a campaign, two dates and a limit — so which orders fall
     * outside the prefix is the provider's decision, and this object cannot
     * name them. On every recorded window that decision is **newest first**
     * (pinned by
     * {@see \AsterMD\Storefront\Tests\Reconciliation\ReverseReconciliationTest}),
     * which means the orders dropped are the **oldest** in the window: the
     * ones that have had the longest to fail to record, and the only ones that
     * can age out of a lookback before another run at the same settings
     * reaches them. That asymmetry is why
     * {@see \AsterMD\Storefront\Console\ReconcileOrdersCommand} fails the
     * run on a truncated sweep rather than reporting its count as a total.
     */
    public function truncated(): bool
    {
        return $this->reportedTotal > count($this->orders);
    }
}

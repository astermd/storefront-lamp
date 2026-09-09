<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Mirrors the cart to the EMR on every mutation (`[7.10]`): the first call of
 * a journey creates the remote cart, every later one replaces its contents.
 *
 * This class is the single place that swallow-and-log lives for the cart
 * (`[7.12]`, `[20.2]`) — a backend outage must never stop someone adding to
 * their cart, and putting the swallow here rather than at each call site
 * keeps it auditable and keeps the gateway honest about what happened. That
 * includes a gateway that returns `Failed` without throwing: the outcome is
 * logged here explicitly, since a null implementation, a fake, or a future
 * gateway is under no obligation to log its own non-`Ok` results, and the one
 * place that must never stay silent about a failed mirror is this one.
 *
 * The create/update decision is driven by a durable flag rather than by the
 * event vocabulary the session read-model returns, with one repair built in:
 * an update against a cart the EMR no longer has falls back to a create
 * exactly once. That covers the case the flag cannot know about — a remote
 * cart pruned underneath a journey — without ever looping.
 *
 * That same flag decides what an *empty* item list means, and the two answers
 * are genuinely different. Before anything has been mirrored there is no
 * remote cart and nothing to say, so an empty list is skipped. Once a cart has
 * been mirrored, emptying it is a mutation like any other (`[7.10]`) and is
 * sent as an update carrying an empty list — the resource replaces the stored
 * items in full rather than merging, so that is how "the buyer removed the
 * last line" is expressed. Returning early instead would leave the EMR holding
 * the previous contents for the life of the journey, with no later mutation
 * able to bring the two back into agreement.
 *
 * **It also writes §18's two cart events onto the local trail**, which nothing
 * did before. This is the one call every accepted cart mutation makes, so it
 * is where "the cart changed" is knowable exactly once — but the row is not a
 * report of the mirror and does not wait for it. `[18.1]`'s trail is the
 * storefront's own record of what happened here, and an EMR outage that leaves
 * it blank would take the audit down with the integration.
 */
final class CartMirror
{
    /** §18: first cart mutation of a journey, once. */
    private const string CART_CREATED = 'cart.created';

    /** §18: every later cart mutation, repeating. */
    private const string CART_UPDATED = 'cart.updated';

    /**
     * @param ?EventRepository $events null leaves the mirror without a local
     *                                 audit trail — the shape a collaborator
     *                                 assembled before this table existed
     *                                 still has, and the mirror's own job is
     *                                 unaffected by it
     */
    public function __construct(
        private readonly CartGateway $gateway,
        private readonly OperatorLog $log,
        private readonly ?EventRepository $events = null,
    ) {
    }

    /**
     * A null $state is the degraded shape: a session identifier with no
     * durable journey behind it. The flag lives on that state, so there is
     * nowhere to record that a create happened and every mutation issues
     * another one. That is deliberate rather than overlooked — the alternative
     * is a second home for a durable flag, which would then have to be kept in
     * step with the first — and it is tolerable because a create is keyed by
     * the session rather than minting an identifier of its own, and because
     * a create the EMR refuses is swallowed and logged like any other failed
     * mirror. It is also close to unreachable: every path that resolves a
     * session identifier loads or adopts the journey behind it in the same
     * breath, so a caller reaching here with an identifier and no state is
     * one that has gone out of its way to supply the pair separately.
     */
    public function mirror(?string $sessionUuid, Cart $cart, ?JourneyState $state): void
    {
        if ($sessionUuid === null) {
            return;
        }

        // Before the mirror rather than after it, and outside its try: the
        // trail records that the buyer changed their cart, which is true
        // whatever the EMR then does with it.
        $this->audit($sessionUuid, $cart);

        $mirrored = $state?->cartMirrored === true;

        $items = self::items($cart);
        if ($items === [] && !$mirrored) {
            return;
        }

        try {
            $result = $mirrored
                ? $this->gateway->update($sessionUuid, $items)
                : $this->gateway->create($sessionUuid, $items);

            if ($result === CartMirrorResult::NotFound) {
                $result = $this->gateway->create($sessionUuid, $items);
            }

            if ($result !== CartMirrorResult::Ok) {
                $this->log->warning('cart.mirror_failed', [
                    'session' => $sessionUuid,
                    'items' => count($items),
                    'outcome' => $result->name,
                ]);

                return;
            }

            if ($state !== null) {
                $state->cartMirrored = true;
            }
        } catch (\Throwable $e) {
            // The class and code rather than the message, for the reason
            // {@see EmrCartGateway} spells out: the mirrored payload carries a
            // product name per line, an exception message can echo it back,
            // and key-based redaction cannot see inside a string (`[20.6]`).
            // A gateway that throws instead of returning `Failed` is not
            // obliged to be careful about its own message, so this end of the
            // contract is where the rule is enforced.
            $this->log->warning('cart.mirror_failed', [
                'session' => $sessionUuid,
                'items' => count($items),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);
        }
    }

    /**
     * One row on §18's trail for this mutation: `cart.created` the first time
     * a journey touches its cart, `cart.updated` every time after.
     *
     * **The fire-once question is asked of the trail itself**, not of a
     * journey flag. `cartMirrored` is the nearest existing flag and is the
     * wrong one twice over: it records that the *EMR* accepted a create, so an
     * outage would make every mutation look like a first one, and it is
     * deliberately left behind when a journey is transplanted onto a fresh
     * session. The table is keyed by the analytics session and is append-only,
     * which makes it the only source whose answer matches the row being
     * written.
     *
     * The payload is §18's — identifiers and quantities — taken from the cart
     * rather than from {@see self::items()}, so a line the EMR cannot be told
     * about is still on the storefront's own record of its own cart.
     *
     * **The identifier is the EMR's own opaque product id, not the slug**, and
     * the product name is not written at all. `[20.14]` keeps a durable event
     * row to the fact that something happened, and a prescription product name
     * is clinically revealing — which the slug is, spelled slightly
     * differently: a real one is the drug and its strength. The name would be
     * blanked by {@see EventRepository::append()}'s containment pass and the
     * slug is turned into an opaque reference by it, so handing either to a
     * function that outlives the request buys nothing the row does not already
     * have. What is passed is the id, plus the slug the sink references — which
     * is what keeps a line carrying no EMR id identifiable, since the id that
     * would otherwise carry it is the missing one.
     */
    private function audit(string $sessionUuid, Cart $cart): void
    {
        if ($this->events === null) {
            return;
        }

        $lines = [];
        foreach ($cart->lines() as $line) {
            $lines[] = ['product_id' => $line->emrProductId, 'slug' => $line->slug, 'qty' => $line->quantity];
        }

        $this->events->append(
            $sessionUuid,
            $this->events->hasName($sessionUuid, self::CART_CREATED) ? self::CART_UPDATED : self::CART_CREATED,
            ['lines' => $lines],
        );
    }

    /**
     * `[7.11]`: the EMR product identifier, the product name and the quantity,
     * with lines carrying no EMR identifier skipped rather than sent with a
     * blank id.
     *
     * @return list<array{product_id: string, name: string, qty: int}>
     */
    private static function items(Cart $cart): array
    {
        $items = [];
        foreach ($cart->lines() as $line) {
            if ($line->emrProductId !== null && $line->emrProductId !== '') {
                $items[] = ['product_id' => $line->emrProductId, 'name' => $line->name, 'qty' => $line->quantity];
            }
        }

        return $items;
    }
}

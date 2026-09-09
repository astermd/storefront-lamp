<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Journey;

use AsterMD\Storefront\Domain\Cart;

/**
 * Where the cart actually lives: the native PHP session holds the working
 * copy, and every save mirrors it into durable journey state.
 *
 * Two stores rather than one, because each covers the other's hole. Durable
 * journey state is keyed by the analytics session uuid, and there are
 * ordinary deployments with no uuid at all — EMR analytics switched off, or
 * an EMR that did not answer when the visitor landed — where a cart kept only
 * there could never be written, and a storefront that cannot take an order
 * because tracking is down is exactly what `[20.1]` forbids. The PHP session,
 * conversely, is browser-scoped: it cannot serve the cross-device resume
 * `[21.5]` requires, which is what the durable copy is for.
 *
 * Precedence is decided by the session stamp stored beside the lines rather
 * than by a flag someone has to remember to set. When the stamp does not
 * match the session this request resolved to, the browser is carrying a cart
 * from a different session — a resume link, or a re-minted one — and the
 * durable copy for the *current* session wins, unless there is nothing there,
 * in which case the anonymous cart is adopted into the new session instead of
 * being thrown away.
 */
final class CartStore
{
    private const string SESSION_KEY = 'cart';

    private const string NOTICE_KEY = 'cart_notice';

    private ?Cart $cart = null;

    public function __construct(private readonly JourneyStore $journey)
    {
    }

    public function cart(): Cart
    {
        if ($this->cart !== null) {
            return $this->cart;
        }

        $uuid = $this->journey->sessionUuid();
        $working = is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : null;
        $durable = $this->journey->state()?->cart ?? [];

        if ($working !== null && ($working['session'] ?? null) === $uuid) {
            return $this->cart = Cart::fromArray($working);
        }

        if (($durable['lines'] ?? []) !== []) {
            return $this->cart = Cart::fromArray($durable);
        }

        return $this->cart = $working === null ? new Cart() : Cart::fromArray($working);
    }

    /**
     * The durable write goes through the journey state object rather than a
     * repository, so it rides the existing save-back
     * ({@see \AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware}) and
     * a handler still cannot reach the database.
     */
    public function save(Cart $cart): void
    {
        $this->cart = $cart;

        $serialised = $cart->toArray();
        $_SESSION[self::SESSION_KEY] = ['session' => $this->journey->sessionUuid()] + $serialised;

        $state = $this->journey->state();
        if ($state !== null) {
            $state->cart = $serialised;
        }
    }

    public function flash(string $notice): void
    {
        $_SESSION[self::NOTICE_KEY] = $notice;
    }

    /** Single-read: the notice is shown on the next page and never again. */
    public function takeNotice(): ?string
    {
        $notice = $_SESSION[self::NOTICE_KEY] ?? null;
        unset($_SESSION[self::NOTICE_KEY]);

        return is_string($notice) && $notice !== '' ? $notice : null;
    }
}

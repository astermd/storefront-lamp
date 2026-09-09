<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\IdempotencyKey;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase
{
    private function cart(int $cents = 12000, int $quantity = 1): Cart
    {
        $cart = new Cart();
        $cart->put(new CartLine('tirzepatide', 'Tirzepatide', 'rx', null, null, $quantity, $cents, 'v1'));

        return $cart;
    }

    /** @return array<string, string> */
    private function buyer(string $email = 'ada@example.com'): array
    {
        return ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $email, 'postal_code' => '10118'];
    }

    public function testTheSameSubmissionProducesTheSameKey(): void
    {
        // A double-click posts the identical form twice. It must claim the
        // same key both times so the second is recognised as a duplicate.
        self::assertSame(
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer()),
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer()),
        );
    }

    public function testChangingTheCartProducesANewKey(): void
    {
        // [13.38]: a cart mutated in another tab between render and submit is
        // a different order, and must not be answered with the first one's
        // stored outcome.
        self::assertNotSame(
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer()),
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(quantity: 2), $this->buyer()),
        );
    }

    public function testChangingThePriceProducesANewKey(): void
    {
        self::assertNotSame(
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer()),
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(cents: 9900), $this->buyer()),
        );
    }

    public function testCorrectingTheBuyerDetailsProducesANewKey(): void
    {
        // A retry after fixing a typo'd email is a new attempt, not a
        // duplicate, and must be allowed to reach the provider.
        self::assertNotSame(
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer()),
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer('ada@example.org')),
        );
    }

    public function testTwoBrowsersNeverShareAKey(): void
    {
        self::assertNotSame(
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer()),
            IdempotencyKey::forSubmit('sess-2', 'php-2', $this->cart(), $this->buyer()),
        );
    }

    public function testAJourneyWithNoAnalyticsSessionStillGetsAStableKey(): void
    {
        // An analytics-off deployment must be able to take an order, and the
        // duplicate guard must still work for it.
        $key = IdempotencyKey::forSubmit(null, 'php-1', $this->cart(), $this->buyer());

        self::assertNotSame('', $key);
        self::assertSame($key, IdempotencyKey::forSubmit(null, 'php-1', $this->cart(), $this->buyer()));
    }

    public function testTheKeyCarriesNoPersonalData(): void
    {
        // The key is stored, logged and indexed. It must be a hash, not a
        // concatenation of an email address and an address line.
        $key = IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer());

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $key);
        self::assertStringNotContainsString('ada', $key);
    }

    public function testTheSameAcceptedUpsellProducesTheSameKey(): void
    {
        // The double-click the checkout key guards against happens on the
        // upsell page too, and there is no cart left to derive a key from --
        // `[13.32]` cleared it when the first order was placed.
        self::assertSame(
            IdempotencyKey::forUpsell('sess-1', 'php-1', 'wellness-pack', 'wellness-pack', 'wp-1'),
            IdempotencyKey::forUpsell('sess-1', 'php-1', 'wellness-pack', 'wellness-pack', 'wp-1'),
        );
    }

    public function testTwoDifferentOffersInOneQueueNeverShareAKey(): void
    {
        // Two offers in one queue reach the same session with the same buyer,
        // so a key that did not name the offer would make the second one look
        // like a duplicate of the first and never charge.
        $first = IdempotencyKey::forUpsell('sess-1', 'php-1', 'wellness-pack', 'wellness-pack', null);
        $second = IdempotencyKey::forUpsell('sess-1', 'php-1', 'sleep-kit', 'sleep-kit', null);

        self::assertNotSame($first, $second);
    }

    public function testAnUpsellKeyIsNeverTheCheckoutKeyForTheSameSession(): void
    {
        // The two live in one column, so a collision would answer an upsell
        // with the prescription order's stored outcome.
        self::assertNotSame(
            IdempotencyKey::forSubmit('sess-1', 'php-1', $this->cart(), $this->buyer()),
            IdempotencyKey::forUpsell('sess-1', 'php-1', 'tirzepatide', 'tirzepatide', 'v1'),
        );
    }

    public function testAnUpsellKeyMovesWithTheVariantBecauseThatIsADifferentThingToBuy(): void
    {
        $base = IdempotencyKey::forUpsell('sess-1', 'php-1', 'wellness-pack', 'wellness-pack', 'wp-1');

        self::assertNotSame($base, IdempotencyKey::forUpsell('sess-1', 'php-1', 'wellness-pack', 'wellness-pack', 'wp-2'));
        self::assertNotSame($base, IdempotencyKey::forUpsell('sess-1', 'php-1', 'sleep-kit', 'wellness-pack', 'wp-1'));
        self::assertNotSame($base, IdempotencyKey::forUpsell('sess-2', 'php-1', 'wellness-pack', 'wellness-pack', 'wp-1'));
    }

    public function testAnUpsellKeyDoesNotMoveWhenTheCatalogPriceDoes(): void
    {
        // Two clicks on one offer are the same purchase whatever the catalog
        // says in between. While the price was key material, a configuration
        // deploy landing between them derived two keys — and the provider has
        // no idempotency of its own, so both reached it and both charged.
        //
        // There is no price argument left to vary, which is the point: the
        // signature is the guarantee. What this pins is that the surviving
        // material is enough to make a repeat look like a repeat.
        $first = IdempotencyKey::forUpsell('sess-1', 'php-1', 'wellness-pack', 'wellness-pack', 'wp-1');
        $second = IdempotencyKey::forUpsell('sess-1', 'php-1', 'wellness-pack', 'wellness-pack', 'wp-1');

        self::assertSame($first, $second);
        self::assertSame(
            5,
            (new \ReflectionMethod(IdempotencyKey::class, 'forUpsell'))->getNumberOfParameters(),
            'a sixth parameter is how the price got back in last time',
        );
    }
    public function testAnUpsellKeyIsAHashCarryingNoPersonalData(): void
    {
        $key = IdempotencyKey::forUpsell(null, 'php-1', 'wellness-pack', 'wellness-pack', null);

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $key);
        self::assertSame($key, IdempotencyKey::forUpsell(null, 'php-1', 'wellness-pack', 'wellness-pack', null));
    }

    public function testLineOrderDoesNotChangeTheKey(): void
    {
        // Adding then removing then re-adding a bump can reorder the cart
        // without changing what is being bought.
        $a = new Cart();
        $a->put(new CartLine('x', 'X', 'rx', null, null, 1, 100, 'v1'));
        $a->put(new CartLine('y', 'Y', 'otc', null, null, 1, 200, null));

        $b = new Cart();
        $b->put(new CartLine('y', 'Y', 'otc', null, null, 1, 200, null));
        $b->put(new CartLine('x', 'X', 'rx', null, null, 1, 100, 'v1'));

        self::assertSame(
            IdempotencyKey::forSubmit('sess-1', 'php-1', $a, $this->buyer()),
            IdempotencyKey::forSubmit('sess-1', 'php-1', $b, $this->buyer()),
        );
    }
}

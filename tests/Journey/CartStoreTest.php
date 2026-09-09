<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Journey;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

final class CartStoreTest extends TestCase
{
    use TempDatabase;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function journeyStore(): JourneyStore
    {
        return new JourneyStore(new SessionRepository(fn (): \PDO => $this->tempPdo()));
    }

    private static function line(string $slug): CartLine
    {
        return new CartLine(
            slug: $slug,
            name: ucfirst($slug),
            kind: 'otc',
            emrProductId: null,
            parentSlug: null,
        );
    }

    public function testAFreshVisitorGetsAnEmptyCart(): void
    {
        $store = new CartStore($this->journeyStore());

        self::assertTrue($store->cart()->isEmpty());
    }

    public function testSaveWritesBothThePhpSessionAndTheJourneyState(): void
    {
        $journey = $this->journeyStore();
        $journey->load('sess-a1234567890abcd');
        $store = new CartStore($journey);

        $cart = new Cart();
        $cart->put(self::line('product-a'));
        $store->save($cart);

        self::assertSame('sess-a1234567890abcd', $_SESSION['cart']['session']);
        self::assertCount(1, $_SESSION['cart']['lines']);
        self::assertSame('product-a', $_SESSION['cart']['lines'][0]['slug']);
        self::assertCount(1, $journey->state()?->cart['lines'] ?? []);
        self::assertSame('product-a', $journey->state()?->cart['lines'][0]['slug']);
    }

    public function testSaveWritesThePhpSessionWhenThereIsNoSessionAtAll(): void
    {
        $store = new CartStore($this->journeyStore());

        $cart = new Cart();
        $cart->put(self::line('product-a'));
        $store->save($cart);

        self::assertNull($_SESSION['cart']['session']);
        self::assertCount(1, $_SESSION['cart']['lines']);
    }

    public function testTheCartSurvivesAcrossRequestsThroughThePhpSession(): void
    {
        $journey = $this->journeyStore();
        $journey->load('sess-b1234567890abcd');
        $first = new CartStore($journey);

        $cart = new Cart();
        $cart->put(self::line('product-a'));
        $first->save($cart);

        $second = new CartStore($journey);
        self::assertTrue($second->cart()->has('product-a'));
    }

    public function testAResumedSessionAdoptsTheDurableCartOverThePhpSessionCopy(): void
    {
        $_SESSION['cart'] = ['session' => 'sess-old12345678901', 'lines' => [self::line('product-a')->toArray()], 'territory' => null];

        $journey = $this->journeyStore();
        $state = $journey->load('sess-new12345678901');
        $durable = new Cart();
        $durable->put(self::line('product-b'));
        $state->cart = $durable->toArray();

        $store = new CartStore($journey);
        $cart = $store->cart();

        self::assertTrue($cart->has('product-b'));
        self::assertFalse($cart->has('product-a'));

        $store->save($cart);
        self::assertSame('sess-new12345678901', $_SESSION['cart']['session']);
        self::assertSame('product-b', $_SESSION['cart']['lines'][0]['slug']);
    }

    public function testAnAnonymousCartIsCarriedIntoAFreshlyMintedSession(): void
    {
        $_SESSION['cart'] = ['session' => null, 'lines' => [self::line('product-a')->toArray()], 'territory' => null];

        $journey = $this->journeyStore();
        $journey->load('sess-new98765432101');

        $store = new CartStore($journey);
        $cart = $store->cart();

        self::assertTrue($cart->has('product-a'));

        $store->save($cart);
        self::assertSame('sess-new98765432101', $_SESSION['cart']['session']);
        self::assertSame('product-a', $journey->state()?->cart['lines'][0]['slug']);
    }

    public function testTheMatchingPhpSessionStampWinsOverADifferingDurableCart(): void
    {
        $journey = $this->journeyStore();
        $state = $journey->load('sess-c1234567890abcd');
        $durable = new Cart();
        $durable->put(self::line('product-b'));
        $state->cart = $durable->toArray();

        $_SESSION['cart'] = ['session' => 'sess-c1234567890abcd', 'lines' => [self::line('product-a')->toArray()], 'territory' => null];

        $store = new CartStore($journey);
        $cart = $store->cart();

        self::assertTrue($cart->has('product-a'));
        self::assertFalse($cart->has('product-b'));
    }

    public function testTheCartIsMemoizedWithinARequest(): void
    {
        $store = new CartStore($this->journeyStore());

        self::assertSame($store->cart(), $store->cart());
    }

    public function testANoticeIsReadOnceAndThenGone(): void
    {
        $store = new CartStore($this->journeyStore());

        $store->flash('x');
        self::assertSame('x', $store->takeNotice());
        self::assertNull($store->takeNotice());
    }

    public function testFlashingAnEmptyNoticeLeavesNothingToTake(): void
    {
        $store = new CartStore($this->journeyStore());

        $store->flash('');

        self::assertNull($store->takeNotice());
        self::assertArrayNotHasKey('cart_notice', $_SESSION);
    }
}

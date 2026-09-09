<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Repository;

use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

final class OrderRepositoryTest extends TestCase
{
    use TempDatabase;

    private const SESSION = 'sess-1234567890abcdef';

    public function testAnOrderWithItsLinesAndConsentsReadsBackWhole(): void
    {
        $pdo = $this->tempPdo();
        $repository = $this->repository($pdo, withSession: true);

        $id = $repository->insert(
            $this->order(),
            [
                ['slug' => 'tirz-5mg', 'name' => 'Tirzepatide 5mg', 'kind' => 'rx', 'provider_offer' => '92', 'provider_item' => '441', 'unit_price_cents' => 24900, 'quantity' => 1],
                ['slug' => 'anti-nausea-kit', 'name' => 'Anti-nausea kit', 'kind' => 'otc', 'provider_offer' => '92', 'provider_item' => '512', 'unit_price_cents' => 1900, 'quantity' => 2],
            ],
            [['key' => 'terms', 'granted' => true, 'copy_version' => 'abc', 'copy_shown' => 'I agree.', 'at' => '2026-08-24T00:00:00+00:00']],
        );

        $order = $repository->findByReference('34660');

        self::assertSame($id, $order['id']);
        self::assertSame(self::SESSION, $order['session_uuid']);
        self::assertSame('34660', $order['provider_reference']);
        self::assertSame('tirz-5mg', $order['anchor_slug']);

        // Integer cents, never a float, on the way out as well as in.
        self::assertSame(26800, $order['amount_cents']);
        self::assertSame(1200, $order['discount_cents']);
        self::assertSame('USD', $order['currency']);
        self::assertSame('placed', $order['status']);
        self::assertSame('ada@example.com', $order['buyer_email']);
        self::assertSame('Ada Lovelace', $order['buyer_name']);
        self::assertSame('CA', $order['buyer_territory']);
        self::assertSame('SAVE10', $order['promotion_code']);
        self::assertSame('card', $order['payment_method']);
        self::assertSame('vrio', $order['provider_category']);
        self::assertSame('idem-1', $order['idempotency_key']);
        self::assertSame('2026-08-24T00:00:00+00:00', $order['placed_at']);

        self::assertCount(2, $order['lines']);
        self::assertSame('tirz-5mg', $order['lines'][0]['slug']);
        self::assertSame('rx', $order['lines'][0]['kind']);
        self::assertSame('441', $order['lines'][0]['provider_item']);
        self::assertSame(24900, $order['lines'][0]['unit_price_cents']);
        self::assertSame(2, $order['lines'][1]['quantity']);

        self::assertSame(
            [['key' => 'terms', 'granted' => true, 'copy_version' => 'abc', 'copy_shown' => 'I agree.', 'at' => '2026-08-24T00:00:00+00:00']],
            $order['consents'],
        );
    }

    public function testTheCardItselfIsNotAmongTheColumnsThisRepositoryCanWrite(): void
    {
        // [15.8]: `card_last_four` is the only card-derived value in the
        // schema, and four digits are what a receipt and a support call need.
        $pdo = $this->tempPdo();
        $columns = array_map(
            static fn (array $column): string => (string) $column['name'],
            $pdo->query('PRAGMA table_info(orders)')->fetchAll(),
        );

        self::assertContains('card_last_four', $columns);
        self::assertNotContains('card_number', $columns);
        self::assertNotContains('cvv', $columns);
    }

    public function testAnOrderMayHaveNoAnalyticsSessionAtAll(): void
    {
        // [20.8]: an analytics-off deployment, or one whose EMR session could
        // not be minted, still takes orders — and inventing an identifier to
        // satisfy a NOT NULL constraint is the synthetic identifier the spec
        // forbids.
        $repository = $this->repository($this->tempPdo(), withSession: false);
        $repository->insert(['session_uuid' => null] + $this->order(), [], []);

        self::assertNull($repository->findByReference('34660')['session_uuid']);
    }

    public function testAnOrderWithNoSessionKeyAtAllIsAcceptedToo(): void
    {
        // The caller may simply omit the key rather than pass an explicit null.
        $order = $this->order();
        unset($order['session_uuid']);

        $repository = $this->repository($this->tempPdo(), withSession: false);
        $repository->insert($order, [], []);

        self::assertNull($repository->findByReference('34660')['session_uuid']);
    }

    public function testAnUnmappedLineIsKeptButMarkedAsNeverSentToTheProvider(): void
    {
        // [13.19] cannot be honoured for something the provider has never
        // heard of, but the local record must still say what the buyer saw.
        $repository = $this->repository($this->tempPdo(), withSession: true);
        $repository->insert($this->order(), [
            ['slug' => 'free-shaker', 'name' => 'Free shaker', 'kind' => 'free-addon', 'provider_offer' => null, 'provider_item' => null, 'unit_price_cents' => 0, 'quantity' => 1, 'sent_to_provider' => false],
            ['slug' => 'tirz-5mg', 'name' => 'Tirzepatide 5mg', 'kind' => 'rx', 'provider_offer' => '92', 'provider_item' => '441', 'unit_price_cents' => 24900, 'quantity' => 1],
        ], []);

        $lines = $repository->findByReference('34660')['lines'];

        self::assertFalse($lines[0]['sent_to_provider']);
        self::assertNull($lines[0]['provider_offer']);
        self::assertTrue($lines[1]['sent_to_provider']);
    }

    public function testMarkTreatmentSyncedUpdatesOnlyTheNamedRow(): void
    {
        $repository = $this->repository($this->tempPdo(), withSession: true);
        $first = $repository->insert($this->order(), [], []);
        $second = $repository->insert(['provider_reference' => '34661'] + $this->order(), [], []);

        $repository->markTreatmentSynced($first, 'tr-900');

        self::assertSame('tr-900', $repository->findByReference('34660')['treatment_reference']);
        self::assertNull($repository->findByReference('34661')['treatment_reference']);
        self::assertNotSame($first, $second);
    }

    public function testAnOrderThatNeverSyncedIsTheOneWithNoTreatmentReference(): void
    {
        // The null is the reconciliation signal: it is exactly the set of
        // orders the provider charged but the EMR never learned of ([18.1]).
        $repository = $this->repository($this->tempPdo(), withSession: true);
        $repository->insert($this->order(), [], []);

        self::assertNull($repository->findByReference('34660')['treatment_reference']);
    }

    public function testFindByReferenceReturnsNullForAnUnknownOrder(): void
    {
        self::assertNull($this->repository($this->tempPdo(), withSession: false)->findByReference('34999'));
    }

    public function testAFailedLineWriteLeavesNoHalfWrittenOrderBehind(): void
    {
        // The money has already moved by the time this is called; an order row
        // whose lines are missing would read as a charge for nothing.
        $pdo = $this->tempPdo();
        $repository = $this->repository($pdo, withSession: true);
        // The bluntest way to make the second write of the three fail after
        // the first has already succeeded.
        $pdo->exec('DROP TABLE order_lines');

        try {
            $repository->insert($this->order(), [
                ['slug' => 'tirz-5mg', 'name' => 'Tirzepatide 5mg', 'kind' => 'rx', 'provider_offer' => '92', 'provider_item' => '441', 'unit_price_cents' => 24900, 'quantity' => 1],
            ], []);
            self::fail('Expected the line write to fail.');
        } catch (\PDOException) {
            // The rollback is the assertion below.
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testAnUpsellOrderSaysSoAndAnOrdinaryOneDoesNot(): void
    {
        // The one order the buyer consented to on a form has to be tellable
        // from the ones they accepted with a single click: a reconciliation
        // sweep, an operator reading the row and the receipt's own arithmetic
        // all turn on the difference.
        $pdo = $this->tempPdo();
        $repository = $this->repository($pdo, withSession: true);

        $repository->insert(
            $this->order(),
            [['slug' => 'tirz-5mg', 'name' => 'Tirzepatide 5mg', 'kind' => 'rx', 'unit_price_cents' => 24900, 'quantity' => 1]],
            [],
        );
        $repository->insert(
            [...$this->order(), 'provider_reference' => '34661', 'amount_cents' => 899, 'is_upsell' => true],
            [['slug' => 'wellness-pack', 'name' => 'Wellness Pack', 'kind' => 'otc', 'unit_price_cents' => 899, 'quantity' => 1]],
            [],
        );

        self::assertFalse($repository->findByReference('34660')['is_upsell'], 'the checkout order is not an upsell');
        self::assertTrue($repository->findByReference('34661')['is_upsell']);
    }

    public function testAnOrderInsertedWithoutTheUpsellFlagIsNotAnUpsell(): void
    {
        // The existing checkout path names no such key, and must keep writing
        // rows that read as what they are without being changed.
        $pdo = $this->tempPdo();

        // `order()` names no `is_upsell` at all, which is exactly the shape the
        // shipped checkout path hands this repository.
        $this->repository($pdo, withSession: true)->insert($this->order(), [], []);

        self::assertSame(0, (int) $pdo->query("SELECT is_upsell FROM orders WHERE provider_reference = '34660'")->fetchColumn());
    }

    public function testTheConnectionIsNotOpenedUntilTheFirstQuery(): void
    {
        $opened = 0;
        $pdo = $this->tempPdo();
        $repository = new OrderRepository(function () use ($pdo, &$opened): \PDO {
            ++$opened;

            return $pdo;
        });

        self::assertSame(0, $opened);

        $repository->findByReference('34660');

        self::assertSame(1, $opened);
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'session_uuid' => self::SESSION,
            'provider_reference' => '34660',
            'anchor_slug' => 'tirz-5mg',
            'amount_cents' => 26800,
            'currency' => 'USD',
            'status' => 'placed',
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Lovelace',
            'buyer_territory' => 'CA',
            'discount_cents' => 1200,
            'promotion_code' => 'SAVE10',
            'payment_method' => 'card',
            'card_last_four' => '1111',
            'idempotency_key' => 'idem-1',
            'provider_category' => 'vrio',
            'placed_at' => '2026-08-24T00:00:00+00:00',
        ];
    }

    private function repository(\PDO $pdo, bool $withSession): OrderRepository
    {
        if ($withSession) {
            // The foreign key is real on every supported engine, so the parent
            // row has to exist before an order may name it.
            (new SessionRepository(static fn (): \PDO => $pdo))->insert(self::SESSION, [], null);
        }

        return new OrderRepository(static fn (): \PDO => $pdo);
    }
}

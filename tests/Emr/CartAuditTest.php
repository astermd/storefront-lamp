<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Emr\CartMirror;
use AsterMD\Storefront\Emr\CartMirrorResult;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeCartGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The two rows §18's table asks for and nothing was writing: cart created on
 * the first mutation of a journey, cart updated on every later one.
 *
 * They are recorded next to the EMR mirror because that is the one call every
 * accepted cart mutation makes, but they are not a report *of* the mirror: the
 * local trail is the storefront's own record of what happened here, and an EMR
 * outage must leave it complete rather than blank.
 */
final class CartAuditTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = 'sess-1234567890abcdef';

    private \PDO $pdo;

    private string $logFile;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);
        $this->logFile = sys_get_temp_dir() . '/cart-audit-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testTheFirstMutationOfAJourneyIsRecordedAsCartCreated(): void
    {
        $this->mirror()->mirror(self::SESSION, $this->cart(), new JourneyState());

        self::assertSame(['cart.created'], $this->names());
    }

    public function testEveryLaterMutationIsRecordedAsCartUpdated(): void
    {
        $mirror = $this->mirror();
        $state = new JourneyState();

        $mirror->mirror(self::SESSION, $this->cart(), $state);
        $mirror->mirror(self::SESSION, $this->cart(), $state);
        $mirror->mirror(self::SESSION, $this->cart(), $state);

        self::assertSame(['cart.created', 'cart.updated', 'cart.updated'], $this->names());
    }

    /**
     * §18 gives the payload as product identifiers, names and quantities, and
     * the identifier it settles on is the EMR's own opaque product id rather
     * than the catalog slug.
     *
     * The slug is not a neutral key. Real catalog slugs are the drug and its
     * strength — `6a8a85-tirzepatide-10mg-ml` — so a row keyed by
     * `session_uuid` and carrying one ties an identifiable journey to a
     * prescription, durably, which is exactly what `[20.14]` keeps off this
     * table. Neither the drug name nor a slugified spelling of it survives:
     * the case a reader would have to guess wrong for the row to be safe is
     * not a difference this test is willing to draw.
     */
    public function testThePayloadIdentifiesEachLineWithoutNamingTheDrug(): void
    {
        $this->mirror()->mirror(self::SESSION, $this->cart(), new JourneyState());

        $payload = $this->payload();

        self::assertStringNotContainsStringIgnoringCase('semaglutide', $payload);
        self::assertStringNotContainsStringIgnoringCase('syringes', $payload);

        // Still a usable record: the EMR's own product id, the quantity, and
        // one distinguishable reference per line.
        self::assertStringContainsString('emr-1', $payload);
        self::assertStringContainsString('"qty":2', $payload);

        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertNotSame($lines[0]['product_ref'], $lines[1]['product_ref']);
    }

    /**
     * The reference is derived from the slug alone, so the same product reads
     * the same on every row and across every journey — which is what lets the
     * trail be grouped by product without the trail naming one.
     */
    public function testTheSameProductCarriesTheSameReferenceOnEveryRow(): void
    {
        $mirror = $this->mirror();
        $state = new JourneyState();

        $mirror->mirror(self::SESSION, $this->cart(), $state);
        $mirror->mirror(self::SESSION, $this->cart(), $state);

        $rows = $this->pdo->query("SELECT payload FROM events WHERE name LIKE 'cart.%' ORDER BY id ASC")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(2, $rows);
        self::assertSame($rows[0], $rows[1]);
    }

    /**
     * A line with no EMR identifier is skipped by the mirror, because there is
     * no id to send. The local trail has no such excuse: it is a record of
     * this storefront's own cart, and a line missing from it would make the
     * audit disagree with what the buyer was actually holding.
     *
     * It is the reference that keeps such a line identifiable, since the id
     * that would otherwise carry it is the missing one.
     */
    public function testALineTheEmrCannotBeToldAboutIsStillOnTheLocalTrail(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine(slug: 'mystery-kit', name: 'Mystery Kit', kind: 'otc', emrProductId: null, parentSlug: null));

        $this->mirror()->mirror(self::SESSION, $cart, new JourneyState());

        self::assertSame(['cart.created'], $this->names());
        self::assertStringNotContainsStringIgnoringCase('mystery-kit', $this->payload());

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertNotSame('', $lines[0]['product_ref']);
    }

    /**
     * Emptying a mirrored cart is a mutation like any other (`[7.10]`), and it
     * is the one an abandonment investigation is most likely to ask about.
     */
    public function testRemovingTheLastLineIsRecordedAsAnUpdateWithNoLines(): void
    {
        $mirror = $this->mirror();
        $state = new JourneyState();

        $mirror->mirror(self::SESSION, $this->cart(), $state);
        $mirror->mirror(self::SESSION, new Cart(), $state);

        self::assertSame(['cart.created', 'cart.updated'], $this->names());
    }

    public function testAFailedMirrorStillLeavesTheLocalTrailRow(): void
    {
        $gateway = new FakeCartGateway(createResult: CartMirrorResult::Failed);

        (new CartMirror($gateway, new OperatorLog($this->logFile), $this->events()))
            ->mirror(self::SESSION, $this->cart(), new JourneyState());

        self::assertSame(['cart.created'], $this->names());
    }

    /**
     * `events.session_uuid` is the trail's only key. A journey with no
     * analytics session has nothing to key a row on, and `[20.8]` forbids
     * inventing one.
     */
    public function testAJourneyWithNoAnalyticsSessionRecordsNothing(): void
    {
        $this->mirror()->mirror(null, $this->cart(), new JourneyState());

        self::assertSame([], $this->names());
    }

    private function mirror(): CartMirror
    {
        return new CartMirror(new FakeCartGateway(), new OperatorLog($this->logFile), $this->events());
    }

    private function events(): EventRepository
    {
        return new EventRepository(fn (): \PDO => $this->pdo);
    }

    private function cart(): Cart
    {
        $cart = new Cart();
        $cart->put(new CartLine(slug: 'semaglutide', name: 'Semaglutide', kind: 'rx', emrProductId: 'emr-1', parentSlug: null, quantity: 1));
        $cart->put(new CartLine(slug: 'syringes', name: 'Syringes', kind: 'otc', emrProductId: 'emr-2', parentSlug: 'semaglutide', quantity: 2));

        return $cart;
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(
            static fn (mixed $name): string => (string) $name,
            $this->pdo->query("SELECT name FROM events WHERE name LIKE 'cart.%' ORDER BY id ASC")->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    private function payload(): string
    {
        return (string) $this->pdo->query("SELECT payload FROM events WHERE name LIKE 'cart.%' ORDER BY id ASC")->fetchColumn();
    }

    /**
     * The first cart row's lines, decoded, so a test can ask what identifies a
     * line rather than whether a substring is present.
     *
     * @return list<array{product_ref: string}>
     */
    private function lines(): array
    {
        /** @var array{lines?: list<array<string, mixed>>} $decoded */
        $decoded = (array) json_decode($this->payload(), true);

        return array_map(
            static fn (array $line): array => ['product_ref' => (string) ($line['product_ref'] ?? '')],
            $decoded['lines'] ?? [],
        );
    }
}

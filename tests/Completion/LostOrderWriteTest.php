<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Completion;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * What the whole application reports when the post-charge order write was lost.
 *
 * The unit case in {@see CompletionTest} pins the decision; this one pins the
 * wiring, because the harm was never in the decision alone — it was in what
 * came out of the real reporter and onto the EMR's single per-session checkout
 * record. A double asserting `placed === true` would still have passed while
 * the shipped reporter turned that into `order_declined`.
 *
 * The journey here is the one the degradation suite already blesses as safe:
 * the provider took $120.00, `INSERT INTO orders` failed, and everything else
 * in the database worked the whole time. The only difference from the fixture
 * that test writes is the row that is not there.
 */
final class LostOrderWriteTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '7c2e5d41-9a3b-4f18-8e6d-1b4a7c9e2f55';

    /** The reference the provider accepted, and the one no local row holds. */
    private const string REFERENCE = '34660';

    private \PDO $pdo;

    public function testAJourneyWhoseOrderRowWasLostIsReportedAsPlacedRatherThanDeclined(): void
    {
        $app = $this->appWithAChargeAndNoRow();

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/thank-you/')
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        self::assertSame(200, $response->getStatusCode(), 'the charged buyer still gets their page');

        $completed = $this->completionEvents();
        self::assertCount(1, $completed, 'the journey closed exactly once');
        self::assertSame('order_placed', $completed[0]['event'] ?? null);
        self::assertSame(self::REFERENCE, $completed[0]['references'] ?? null, 'named, so it can be reconciled');
    }

    public function testTheLostWriteIsStatedRatherThanImpliedByAZeroTotal(): void
    {
        // The reported money is the readable rows' and there are none, so the
        // figures are short by the whole order. That is the one thing a
        // reconciliation reading this record has to be told outright, because
        // an `order_placed` with a zero total is otherwise indistinguishable
        // from a bug in the arithmetic.
        $app = $this->appWithAChargeAndNoRow();

        $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/thank-you/')
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        $completed = $this->completionEvents();
        self::assertSame(0, $completed[0]['paid_total_cents'] ?? null, 'no row, no provable figure');
        self::assertSame(0, $completed[0]['order_value_cents'] ?? null);
        self::assertSame(
            self::REFERENCE,
            $completed[0]['references'] ?? null,
            'so the reference is the whole of what this record can be reconciled by',
        );
    }

    public function testNoCompletionEventEverSaysTheJourneyDeclined(): void
    {
        // The absence the whole fix exists for. The EMR keeps one checkout
        // event per session and updates it in place, so a decline written here
        // overwrites the truthful `order_placed` the checkout wrote — and the
        // guard flag set a moment later means no later load can put it back.
        $app = $this->appWithAChargeAndNoRow();

        $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/thank-you/')
                ->withCookieParams(['amd_session' => self::SESSION]),
        );

        foreach ($this->completionEvents() as $event) {
            self::assertNotSame('order_declined', $event['event'] ?? null);
        }
    }

    /**
     * The application, and a journey that placed one order whose local row
     * never landed.
     *
     * No `orders` row is written at all, which is exactly the state a refused
     * `INSERT INTO orders` leaves behind: the provider has the money, the
     * journey has the reference, and the storefront has nothing else.
     */
    private function appWithAChargeAndNoRow(): App
    {
        $this->pdo = $this->tempPdo();

        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(
            self::SESSION,
            [
                'placed_orders' => [self::REFERENCE],
                'buyer' => [
                    'first_name' => 'Dana',
                    'last_name' => 'Reyes',
                    'email' => 'dana.reyes@example.test',
                ],
            ],
            null,
        );

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo,
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
    }

    /**
     * The decoded payloads of the local trail's completion rows.
     *
     * The trail is read rather than the EMR transport because it is written
     * unconditionally — the EMR half is behind the analytics flag, and the
     * event name the reporter derives is the same value on both sides.
     *
     * @return list<array<string, mixed>>
     */
    private function completionEvents(): array
    {
        $payloads = [];
        foreach ($this->rows("SELECT * FROM events WHERE name = 'checkout.completed' ORDER BY id") as $row) {
            $decoded = json_decode((string) ($row['payload'] ?? ''), true);
            $payloads[] = is_array($decoded) ? $decoded : [];
        }

        return $payloads;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }
}

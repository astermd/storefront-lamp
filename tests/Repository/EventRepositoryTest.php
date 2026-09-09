<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Repository;

use AsterMD\Storefront\Database\ConnectionFactory;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

class EventRepositoryTest extends TestCase
{
    use TempDatabase;

    public function testAppendsEventsInOrderForASession(): void
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(static fn (): \PDO => $pdo))->insert('sess-1234567890abcdef', [], null);
        $events = new EventRepository(static fn (): \PDO => $pdo);

        $events->append('sess-1234567890abcdef', 'session_created', ['source_category' => 'affiliate']);
        $events->append('sess-1234567890abcdef', 'attribution_captured');

        self::assertSame(['session_created', 'attribution_captured'], $events->namesFor('sess-1234567890abcdef'));
    }

    public function testPayloadsAreRedactedBeforeTheyReachTheAuditTable(): void
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(static fn (): \PDO => $pdo))->insert('sess-1234567890abcdef', [], null);
        (new EventRepository(static fn (): \PDO => $pdo))->append('sess-1234567890abcdef', 'lead_captured', ['email' => 'buyer@example.com', 'step' => 'intake']);

        $stored = $pdo->query('SELECT payload FROM events')->fetchColumn();

        self::assertStringNotContainsString('buyer@example.com', (string) $stored);
        self::assertStringContainsString('intake', (string) $stored);
    }

    public function testACardNumberInsideAPayloadValueNeverReachesTheAuditTable(): void
    {
        // `events.payload` outlives the request by design, so it is the one
        // sink where a leaked PAN becomes durable storage (`[15.8]`). The key
        // here is one no redaction list would name, and the number is a
        // substring of a longer provider string -- so containment has to come
        // from the sink itself, not from the caller remembering to ask.
        $pdo = $this->tempPdo();
        (new SessionRepository(static fn (): \PDO => $pdo))->insert('sess-1234567890abcdef', [], null);
        (new EventRepository(static fn (): \PDO => $pdo))->append('sess-1234567890abcdef', 'checkout_declined', [
            'gateway_request_text' => 'REQ 4111111100084444 737 DECLINED',
            'status' => 'declined',
        ]);

        $stored = (string) $pdo->query('SELECT payload FROM events')->fetchColumn();

        self::assertStringNotContainsString('4111111100084444', $stored);
        self::assertStringContainsString('declined', $stored);
    }

    public function testAPayloadCarryingAnInvalidByteIsStillRecorded(): void
    {
        // `json_encode` returns false on malformed UTF-8, and a driver message
        // quotes the offending value straight back at us. Losing the audit
        // line for that is losing exactly the line worth having.
        $pdo = $this->tempPdo();
        (new SessionRepository(static fn (): \PDO => $pdo))->insert('sess-1234567890abcdef', [], null);
        (new EventRepository(static fn (): \PDO => $pdo))->append('sess-1234567890abcdef', 'order_recorded', [
            'reason' => "driver said \xE9 no",
            'step' => 'checkout',
        ]);

        $stored = (string) $pdo->query('SELECT payload FROM events')->fetchColumn();

        self::assertNotSame('', $stored, 'the payload was dropped whole');
        self::assertStringContainsString('checkout', $stored);
    }

    public function testAWriteFailureNeverPropagates(): void
    {
        // An unmigrated connection has no events table; recording an event must
        // not be able to break the request that triggered it.
        $dir = sys_get_temp_dir() . '/storefront-events-' . bin2hex(random_bytes(6));
        mkdir($dir . '/storage/database', 0775, true);
        $this->registerTempDirForCleanup($dir);

        $pdo = (new ConnectionFactory(['driver' => 'sqlite', 'database' => 'storage/database/test.sqlite'], $dir))->create();

        (new EventRepository(static fn (): \PDO => $pdo))->append('sess-1234567890abcdef', 'session_created');

        self::assertSame([], (new EventRepository(static fn (): \PDO => $pdo))->namesFor('sess-1234567890abcdef'));
    }

    public function testItAnswersWhetherASessionHasAlreadyRecordedAName(): void
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(static fn (): \PDO => $pdo))->insert('sess-1234567890abcdef', [], null);
        $events = new EventRepository(static fn (): \PDO => $pdo);
        $events->append('sess-1234567890abcdef', 'cart.created');

        self::assertTrue($events->hasName('sess-1234567890abcdef', 'cart.created'));
        self::assertFalse($events->hasName('sess-1234567890abcdef', 'cart.updated'));
    }

    public function testANameRecordedForAnotherSessionIsNotThisSessionsHistory(): void
    {
        // The dedup source for §18's fire-once events, so a leak across
        // sessions here would suppress the opening event of every later
        // journey.
        $pdo = $this->tempPdo();
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $sessions->insert('sess-1234567890abcdef', [], null);
        $sessions->insert('sess-fedcba0987654321', [], null);
        $events = new EventRepository(static fn (): \PDO => $pdo);
        $events->append('sess-1234567890abcdef', 'cart.created');

        self::assertFalse($events->hasName('sess-fedcba0987654321', 'cart.created'));
    }

    public function testAFailedLookupReportsNothingRecordedRatherThanPropagating(): void
    {
        $repository = new EventRepository(static fn (): \PDO => throw new \PDOException('no database'));

        self::assertFalse($repository->hasName('sess-1234567890abcdef', 'cart.created'));
    }
}

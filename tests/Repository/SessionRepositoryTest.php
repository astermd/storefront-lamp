<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Repository;

use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

class SessionRepositoryTest extends TestCase
{
    use TempDatabase;

    public function testInsertThenFindRoundTripsJsonColumns(): void
    {
        $repository = new SessionRepository(fn (): \PDO => $this->tempPdo());
        $repository->insert('sess-1234567890abcdef', ['furthest_step' => 'intake'], ['params' => ['affiliate_id' => '4412']]);

        $row = $repository->find('sess-1234567890abcdef');

        self::assertNotNull($row);
        self::assertSame('sess-1234567890abcdef', $row['session_uuid']);
        self::assertSame(['furthest_step' => 'intake'], $row['journey_state']);
        self::assertSame(['params' => ['affiliate_id' => '4412']], $row['attribution']);
        self::assertNull($row['opportunity_id']);
        self::assertNotSame('', $row['created_at']);
    }

    public function testFindReturnsNullForAnUnknownSession(): void
    {
        self::assertNull((new SessionRepository(fn (): \PDO => $this->tempPdo()))->find('sess-nope'));
    }

    public function testSaveUpdatesStateAttributionAndOpportunityAndBumpsUpdatedAt(): void
    {
        $repository = new SessionRepository(fn (): \PDO => $this->tempPdo());
        $repository->insert('sess-1234567890abcdef', [], null);
        $before = $repository->find('sess-1234567890abcdef');

        $repository->save('sess-1234567890abcdef', ['furthest_step' => 'checkout'], ['params' => []], 'opp-99');
        $after = $repository->find('sess-1234567890abcdef');

        self::assertSame(['furthest_step' => 'checkout'], $after['journey_state']);
        self::assertSame(['params' => []], $after['attribution']);
        self::assertSame('opp-99', $after['opportunity_id']);
        self::assertSame($before['created_at'], $after['created_at']);
    }

    public function testANullAttributionIsStoredAsNullNotAsAnEmptyObject(): void
    {
        $repository = new SessionRepository(fn (): \PDO => $this->tempPdo());
        $repository->insert('sess-1234567890abcdef', [], null);

        self::assertNull($repository->find('sess-1234567890abcdef')['attribution']);
    }

    public function testSaveReportsWhetherTheRowItMeantToUpdateWasStillThere(): void
    {
        $repository = new SessionRepository(fn (): \PDO => $this->tempPdo());
        $repository->insert('sess-1234567890abcdef', [], null);

        self::assertTrue($repository->save('sess-1234567890abcdef', ['furthest_step' => 'checkout'], null, null));
        self::assertFalse($repository->save('sess-fedcba0987654321', ['furthest_step' => 'checkout'], null, null));
    }

    public function testInsertingATwiceKnownSessionIsANoOp(): void
    {
        $pdo = $this->tempPdo();
        $repository = new SessionRepository(static fn (): \PDO => $pdo);
        $repository->insert('sess-1234567890abcdef', ['a' => 1], null);
        $repository->insert('sess-1234567890abcdef', ['b' => 2], null);

        // Do-nothing rather than upsert: the existing row already holds this
        // journey's history, and this method only guarantees a row exists.
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertSame(['a' => 1], $repository->find('sess-1234567890abcdef')['journey_state']);
    }

    public function testInsertWritesTheOpportunityWhenOneIsSupplied(): void
    {
        $repository = new SessionRepository(fn (): \PDO => $this->tempPdo());
        $repository->insert('sess-1234567890abcdef', [], null, 'opp-1');

        self::assertSame('opp-1', $repository->find('sess-1234567890abcdef')['opportunity_id']);
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Journey;

use AsterMD\Storefront\Attribution\Attribution;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

class JourneyStoreTest extends TestCase
{
    use TempDatabase;

    public function testLoadCreatesTheRowWhenTheSessionIsNewToThisStorefront(): void
    {
        $repository = new SessionRepository(fn (): \PDO => $this->tempPdo());
        $store = new JourneyStore($repository);

        $state = $store->load('sess-1234567890abcdef');

        self::assertFalse($state->reconciled);
        self::assertNotNull($repository->find('sess-1234567890abcdef'));
    }

    public function testLoadReturnsTheSameInstanceWithinOneRequest(): void
    {
        $store = new JourneyStore(new SessionRepository(fn (): \PDO => $this->tempPdo()));

        self::assertSame($store->load('sess-1234567890abcdef'), $store->load('sess-1234567890abcdef'));
    }

    public function testFlushPersistsMutationsAndIsANoOpWhenNothingChanged(): void
    {
        $repository = new SessionRepository(fn (): \PDO => $this->tempPdo());
        $store = new JourneyStore($repository);
        $state = $store->load('sess-1234567890abcdef');
        $state->furthestStep = 'checkout';
        $state->opportunityId = 'opp-7';
        $state->attribution = Attribution::capture(['aff_id' => '4412'], [], 'https://blog.example/', null);

        $store->flush();
        $row = $repository->find('sess-1234567890abcdef');
        self::assertSame('checkout', $row['journey_state']['furthest_step']);
        self::assertSame('opp-7', $row['opportunity_id']);
        self::assertSame('4412', $row['attribution']['params']['affiliate_id']);

        $updatedAt = $row['updated_at'];
        $store->flush();
        self::assertSame($updatedAt, $repository->find('sess-1234567890abcdef')['updated_at']);
    }

    public function testAFlushWhoseRowHasVanishedIsSurfacedRatherThanLostSilently(): void
    {
        $pdo = $this->tempPdo();
        $store = new JourneyStore(new SessionRepository(static fn (): \PDO => $pdo));
        $state = $store->load('sess-1234567890abcdef');
        $state->furthestStep = 'checkout';
        $pdo->exec("DELETE FROM sessions WHERE session_uuid = 'sess-1234567890abcdef'");

        $this->expectException(\RuntimeException::class);
        $store->flush();
    }

    public function testFlushWithoutALoadedSessionDoesNothing(): void
    {
        (new JourneyStore(new SessionRepository(fn (): \PDO => $this->tempPdo())))->flush();
        self::assertTrue(true);
    }
}

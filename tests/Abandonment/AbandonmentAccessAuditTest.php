<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Abandonment;

use AsterMD\Storefront\Abandonment\AbandonmentSweep;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * `[30.7]`: the sweep reads a journey and serialises it into a signal that
 * leaves this storefront, so the journeys it read leave a row saying so.
 *
 * The signal carries a permanent resume link, which is why this is the second
 * read worth auditing and not merely the second read: what leaves is a
 * credential for somebody's intake answers.
 */
final class AbandonmentAccessAuditTest extends TestCase
{
    use TempDatabase;

    private const string BASE_URL = 'https://shop.example.test';

    private const int NOW = 1_787_011_200;

    private const string ANSWER = 'semaglutide-2.4mg-weekly';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    public function testEveryJourneySerialisedIntoASignalLeavesAnAccessRow(): void
    {
        $this->seed('sess-a', $this->abandonedIntake(), 7_200);
        $this->seed('sess-b', $this->abandonedIntake(), 10_800);

        self::assertCount(2, $this->sweep()->signals());
        self::assertSame(['access.abandonment_signal'], $this->namesFor('sess-a'));
        self::assertSame(['access.abandonment_signal'], $this->namesFor('sess-b'));
    }

    /**
     * The window is wider than the set of journeys that classify as anything.
     * A row that produces no signal has nothing leave the storefront, so
     * claiming an access would overstate what happened — and every claim on
     * this table has to be one an investigator can rely on.
     */
    public function testAJourneyThatProducesNoSignalIsNotRecordedAsAnAccess(): void
    {
        $this->seed('sess-empty', new JourneyState(), 7_200);

        self::assertSame([], $this->sweep()->signals());
        self::assertSame([], $this->namesFor('sess-empty'));
    }

    public function testTheRowNamesTheActorAndTheReasonAndCarriesNoAnswers(): void
    {
        $this->seed('sess-a', $this->abandonedIntake(), 7_200);

        $this->sweep()->signals();
        $payload = $this->payloadFor('sess-a');

        self::assertStringContainsString('abandonment_sweep', $payload);
        self::assertStringContainsString('intake_abandoned', $payload);
        self::assertStringNotContainsString(self::ANSWER, $payload);
        self::assertStringNotContainsString('Ada', $payload);
    }

    /**
     * The resume link is the reason this read matters, so the row says a link
     * left — without repeating the link itself, which is the credential.
     */
    public function testTheRowRecordsThatAResumeLinkLeftWithoutRepeatingIt(): void
    {
        $this->seed('sess-a', $this->abandonedIntake(), 7_200);

        $signals = $this->sweep()->signals();
        $payload = $this->payloadFor('sess-a');

        self::assertStringContainsString('amd_session=sess-a', $signals[0]->resumeUrl);
        self::assertStringContainsString('"resume_link":true', $payload);
        self::assertStringNotContainsString('amd_session', $payload);
    }

    /**
     * `[20.1]`/`[21.11a]`: the feed must never be able to disturb the
     * storefront, and it must never lose a signal because a write failed.
     */
    public function testAnUnwritableTrailNeitherStopsTheSweepNorChangesWhatItReturns(): void
    {
        $this->seed('sess-a', $this->abandonedIntake(), 7_200);

        $sweep = new AbandonmentSweep(
            sessions: new SessionRepository(fn (): \PDO => $this->pdo),
            baseUrl: self::BASE_URL,
            events: new EventRepository(static fn (): \PDO => throw new \PDOException('no database')),
            clock: static fn (): int => self::NOW,
        );

        self::assertCount(1, $sweep->signals());
    }

    public function testASweepWithNoTrailAtAllStillProducesItsSignals(): void
    {
        $this->seed('sess-a', $this->abandonedIntake(), 7_200);

        $sweep = new AbandonmentSweep(
            sessions: new SessionRepository(fn (): \PDO => $this->pdo),
            baseUrl: self::BASE_URL,
            clock: static fn (): int => self::NOW,
        );

        self::assertCount(1, $sweep->signals());
    }

    private function sweep(): AbandonmentSweep
    {
        return new AbandonmentSweep(
            sessions: new SessionRepository(fn (): \PDO => $this->pdo),
            baseUrl: self::BASE_URL,
            events: new EventRepository(fn (): \PDO => $this->pdo),
            clock: static fn (): int => self::NOW,
        );
    }

    private function abandonedIntake(): JourneyState
    {
        $state = new JourneyState();
        $state->cart = ['lines' => [['slug' => 'semaglutide', 'quantity' => 1]]];
        $state->storeAnswers('tf-weight-loss', ['first_name' => 'Ada', 'treatment' => self::ANSWER]);

        return $state;
    }

    private function seed(string $uuid, JourneyState $state, int $idleSeconds): void
    {
        $movedAt = gmdate('c', self::NOW - $idleSeconds);
        $this->pdo->prepare(
            'INSERT INTO sessions (session_uuid, opportunity_id, journey_state, attribution, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, ?)',
        )->execute([
            $uuid,
            $state->opportunityId,
            (string) json_encode($state->toArray(), JSON_UNESCAPED_SLASHES),
            $movedAt,
            $movedAt,
        ]);
    }

    /** @return list<string> */
    private function namesFor(string $uuid): array
    {
        return (new EventRepository(fn (): \PDO => $this->pdo))->namesFor($uuid);
    }

    private function payloadFor(string $uuid): string
    {
        $statement = $this->pdo->prepare('SELECT payload FROM events WHERE session_uuid = ? ORDER BY id ASC LIMIT 1');
        $statement->execute([$uuid]);

        return (string) $statement->fetchColumn();
    }
}

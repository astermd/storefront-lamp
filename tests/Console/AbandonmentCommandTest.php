<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Abandonment\AbandonmentSweep;
use AsterMD\Storefront\Console\AbandonmentCommand;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AbandonmentCommandTest extends TestCase
{
    use TempDatabase;

    private const int NOW = 1_800_000_000;

    private const string CLINICAL_ANSWER = 'semaglutide-2.4mg-weekly';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    private function command(int $idleSeconds = 3600): AbandonmentCommand
    {
        return new AbandonmentCommand(new AbandonmentSweep(
            sessions: new SessionRepository(fn (): \PDO => $this->pdo),
            baseUrl: 'https://shop.example.test',
            idleSeconds: $idleSeconds,
            clock: static fn (): int => self::NOW,
        ));
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

    private static function abandonedIntake(): JourneyState
    {
        $state = new JourneyState();
        $state->cart = ['lines' => [['slug' => 'abc123-tadalafil', 'quantity' => 1]]];
        $state->opportunityId = 'opp_1';
        $state->furthestStep = 'prequalification';
        $state->formAnswers = ['tf_intake' => ['medication' => self::CLINICAL_ANSWER]];
        $state->formStatus = ['tf_intake' => JourneyState::FORM_IN_PROGRESS];

        return $state;
    }

    /** @return array<string, mixed> */
    private static function decode(string $display): array
    {
        $decoded = json_decode(trim($display), true);
        self::assertIsArray($decoded, 'the command must emit a parseable JSON document');

        return $decoded;
    }

    public function testTheCommandEmitsTheCurrentSignalSetAsOneJsonDocument(): void
    {
        $this->seed('11111111-1111-4111-8111-111111111111', self::abandonedIntake(), 7200);
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $document = self::decode($tester->getDisplay());
        self::assertSame(1, $document['count']);
        self::assertSame('intake_abandoned', $document['signals'][0]['state']);
        self::assertSame(
            'https://shop.example.test/?amd_session=11111111-1111-4111-8111-111111111111',
            $document['signals'][0]['resume_url'],
        );
        self::assertSame('prequalification', $document['signals'][0]['furthest_step']);
    }

    public function testAnEmptySweepEmitsAnEmptySignalListRatherThanNothingAtAll(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $document = self::decode($tester->getDisplay());
        self::assertSame(0, $document['count']);
        self::assertSame([], $document['signals']);
    }

    public function testAJourneyStillInsideTheIntervalIsAbsentFromTheDocument(): void
    {
        $this->seed('22222222-2222-4222-8222-222222222222', self::abandonedIntake(), 600);
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(0, self::decode($tester->getDisplay())['count']);
    }

    public function testACompletedJourneyIsAbsentFromTheDocument(): void
    {
        $state = self::abandonedIntake();
        $state->completedAt = '2026-08-25T09:00:00+00:00';
        $this->seed('33333333-3333-4333-8333-333333333333', $state, 7200);
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(0, self::decode($tester->getDisplay())['count']);
    }

    /** PHI: the emitted document reports the fact of an abandoned intake, never its content. */
    public function testNoClinicalAnswerAppearsAnywhereInTheEmittedDocument(): void
    {
        $this->seed('44444444-4444-4444-8444-444444444444', self::abandonedIntake(), 7200);
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringNotContainsString(self::CLINICAL_ANSWER, $tester->getDisplay());
        self::assertStringNotContainsString('medication', $tester->getDisplay());
    }

    /**
     * The document is piped into another system, so a broken database must
     * fail loudly with a non-zero status rather than print an empty signal set
     * that a consumer would read as "nobody abandoned anything".
     */
    public function testAFailedReadIsReportedAsAFailureAndNotAsAnEmptySignalSet(): void
    {
        $this->pdo->exec('DROP TABLE sessions');
        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('"signals"', $tester->getDisplay());
    }

    /**
     * A cron entry's stderr is a mail spool, a CI artefact and a shell history
     * at once, and it is the one sink neither {@see \AsterMD\Storefront\Support\OperatorLog}
     * defence reaches. The class answers what an outage asks; the driver's own
     * sentence about the row it refused does not go there.
     */
    public function testAFailureReportsTheExceptionClassAndNeverItsMessage(): void
    {
        $command = new AbandonmentCommand(new AbandonmentSweep(
            sessions: new SessionRepository(
                static fn (): \PDO => throw new \PDOException(
                    "SQLSTATE[HY000]: cannot read journey_state 'semaglutide-2.4mg-weekly' for dana@example.com",
                ),
            ),
            baseUrl: 'https://shop.example.test',
            idleSeconds: 3600,
            clock: static fn (): int => self::NOW,
        ));
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringNotContainsString('dana@example.com', $tester->getErrorOutput());
        self::assertStringNotContainsString(self::CLINICAL_ANSWER, $tester->getErrorOutput());
        self::assertStringContainsString('PDOException', $tester->getErrorOutput());
    }
}

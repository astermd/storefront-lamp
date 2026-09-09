<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Forms\NullIntakeGateway;
use AsterMD\Storefront\Forms\RecordingIntakeGateway;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\FakeIntakeGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The six questionnaire lifecycle events, landing locally at last (`[18.1]`).
 *
 * They reached the EMR from the day the intake flow was built and landed
 * nowhere else, which left the storefront unable to answer "how far did this
 * journey get" without asking the EMR — the one question a local audit trail
 * exists for.
 */
final class RecordingIntakeGatewayTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);
    }

    public function testEveryLifecycleEventLandsInTheLocalTrailInOrder(): void
    {
        $gateway = new RecordingIntakeGateway(new NullIntakeGateway(), $this->events());

        foreach ([Event::IntakeInitiated, Event::IntakeInProgress, Event::IntakeCompleted] as $event) {
            $gateway->record(self::SESSION, $event, 'tf-medical', [], null);
        }

        self::assertSame(
            ['intake.intake_initiated', 'intake.intake_inprogress', 'intake.intake_completed'],
            $this->events()->namesFor(self::SESSION),
        );
    }

    public function testThePreQualificationTripleIsDistinguishableFromTheIntakeOne(): void
    {
        $gateway = new RecordingIntakeGateway(new NullIntakeGateway(), $this->events());

        $gateway->record(self::SESSION, Event::PreQualifyingInitiated, 'tf-eligibility', [], null);

        self::assertSame(['intake.pre_qualifying_initiated'], $this->events()->namesFor(self::SESSION));
    }

    public function testTheAnswersThemselvesAreNeverWrittenOnlyTheirCount(): void
    {
        // `$data` is PHI. A count distinguishes "we sent nothing" from "a full
        // form went out" and cannot leak an answer.
        $gateway = new RecordingIntakeGateway(new NullIntakeGateway(), $this->events());

        $gateway->record(self::SESSION, Event::IntakeCompleted, 'tf-medical', [
            ['id' => 'q1', 'name' => 'diagnosis', 'label' => 'Diagnosis', 'type' => 'text', 'value' => [['value' => 'type 2 diabetes']]],
        ], ['page' => 2, 'total' => 3]);

        $payload = $this->lastPayload();

        self::assertSame(1, $payload['fields']);
        self::assertSame(2, $payload['page']);
        self::assertStringNotContainsString('diabetes', (string) json_encode($payload));
    }

    public function testTheLineIsWrittenEvenWhenTheEmrRefusedTheSubmission(): void
    {
        // A lost EMR save is exactly the incident the local trail has to
        // survive, so recording only the accepted ones would blind it to the
        // failures it exists to explain.
        $gateway = new RecordingIntakeGateway(new FakeIntakeGateway(result: false), $this->events());

        $accepted = $gateway->record(self::SESSION, Event::IntakeInitiated, 'tf-medical', [], null);

        self::assertFalse($accepted, 'the inner gateway answer is passed through unchanged');
        self::assertSame(['intake.intake_initiated'], $this->events()->namesFor(self::SESSION));
        self::assertFalse($this->lastPayload()['accepted']);
    }

    public function testAJourneyWithNoSessionWritesNothing(): void
    {
        // `[20.8]` forbids inventing an identifier, and the table is keyed on
        // one, so there is nothing to file the line under.
        $gateway = new RecordingIntakeGateway(new NullIntakeGateway(), $this->events());

        $gateway->record('', Event::IntakeInitiated, 'tf-medical', [], null);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    private function events(): EventRepository
    {
        return new EventRepository(fn (): \PDO => $this->pdo);
    }

    /** @return array<string, mixed> */
    private function lastPayload(): array
    {
        $statement = $this->pdo->query('SELECT payload FROM events ORDER BY id DESC LIMIT 1');
        self::assertNotFalse($statement);

        return (array) json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }
}

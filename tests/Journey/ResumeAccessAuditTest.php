<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Journey;

use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Journey\SessionOptions;
use AsterMD\Storefront\Journey\SessionResolver;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `[30.7]`: following a resume link is a read of health information, and it
 * leaves a row saying so.
 *
 * This deployment's resume link is the raw session identifier, carries no
 * expiry and is never revoked (`config/abandonment.php` records the decision),
 * so nothing else in the system can say whether the link was used. The trail
 * is the whole record.
 */
final class ResumeAccessAuditTest extends TestCase
{
    use TempDatabase;

    private const string UUID = 'sess-1234567890abcdef';

    private const string ANSWER = 'semaglutide-2.4mg-weekly';

    private \PDO $pdo;

    private string $logFile;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->logFile = sys_get_temp_dir() . '/resume-audit-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testFollowingAResumeLinkIsRecordedAsAnAccessToHealthInformation(): void
    {
        $this->seedJourneyWithAnswers();

        $this->resolve();

        self::assertContains('access.journey_resumed', $this->names());
    }

    /**
     * `[20.14]`/`[20.6]`: the row records that the access happened and by
     * what. It never becomes a second copy of the thing that was read.
     */
    public function testTheAuditRowNamesTheActorAndTheReasonAndCarriesNoAnswers(): void
    {
        $this->seedJourneyWithAnswers();

        $this->resolve();

        $payload = $this->payloadOf('access.journey_resumed');

        self::assertStringContainsString('resume_link', $payload);
        self::assertStringNotContainsString(self::ANSWER, $payload);
        self::assertStringNotContainsString('Ada', $payload);
    }

    public function testTheRowSaysWhetherIntakeAnswersWereAmongWhatWasRestored(): void
    {
        $this->seedJourneyWithAnswers();

        $this->resolve();

        self::assertStringContainsString('"restored_intake":true', $this->payloadOf('access.journey_resumed'));
    }

    /**
     * A link followed into a journey that never answered anything is still a
     * use of the link, and still recorded — with the flag telling an
     * investigator that nothing clinical was read.
     */
    public function testALinkFollowedIntoAJourneyWithNoAnswersIsStillRecorded(): void
    {
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::UUID, (new JourneyState())->toArray(), null);

        $this->resolve();

        self::assertStringContainsString('"restored_intake":false', $this->payloadOf('access.journey_resumed'));
    }

    /**
     * The other side of the rule. A resume value the EMR does not recognise is
     * rejected before any journey is loaded, so nothing was read and nothing
     * may claim it was — the existing `session_resume_rejected` row is the
     * whole of what happened.
     */
    public function testARejectedResumeValueRecordsNoAccess(): void
    {
        $gateway = new FakeSessionGateway([], mintUuid: 'sess-fresh-1234567890ab');

        $this->resolver($gateway)->resolve(
            (new ServerRequestFactory())->createServerRequest('GET', '/?amd_session=' . self::UUID),
            mayCreate: true,
        );

        self::assertNotContains('access.journey_resumed', $this->names());
    }

    /**
     * A visitor arriving on their own cookie is not a resume link, and the
     * link's exposure is the thing being audited. Auditing every page view
     * would bury it.
     */
    public function testAnOrdinaryCookieVisitRecordsNoAccess(): void
    {
        $this->seedJourneyWithAnswers();

        $this->resolver(new FakeSessionGateway([self::UUID => ['opportunity_id' => null, 'events' => []]]))->resolve(
            (new ServerRequestFactory())->createServerRequest('GET', '/')->withCookieParams(['amd_session' => self::UUID]),
            mayCreate: true,
        );

        self::assertNotContains('access.journey_resumed', $this->names());
    }

    private function resolve(): void
    {
        $this->resolver(new FakeSessionGateway([self::UUID => ['opportunity_id' => 'opp-9', 'events' => []]]))->resolve(
            (new ServerRequestFactory())->createServerRequest('GET', '/?amd_session=' . self::UUID),
            mayCreate: true,
        );
    }

    private function resolver(FakeSessionGateway $gateway): SessionResolver
    {
        $sessions = new SessionRepository(fn (): \PDO => $this->pdo);

        return new SessionResolver(
            $gateway,
            $sessions,
            new JourneyStore($sessions),
            new EventRepository(fn (): \PDO => $this->pdo),
            new OperatorLog($this->logFile),
            new SessionOptions(),
        );
    }

    private function seedJourneyWithAnswers(): void
    {
        $state = new JourneyState();
        $state->storeAnswers('tf-weight-loss', ['first_name' => 'Ada', 'treatment' => self::ANSWER]);

        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::UUID, $state->toArray(), null);
    }

    /** @return list<string> */
    private function names(): array
    {
        return (new EventRepository(fn (): \PDO => $this->pdo))->namesFor(self::UUID);
    }

    private function payloadOf(string $name): string
    {
        $statement = $this->pdo->prepare('SELECT payload FROM events WHERE name = ? ORDER BY id ASC LIMIT 1');
        $statement->execute([$name]);

        return (string) $statement->fetchColumn();
    }
}

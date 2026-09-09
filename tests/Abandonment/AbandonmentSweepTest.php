<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Abandonment;

use AsterMD\Sdk\Enum\CheckoutEvent;
use AsterMD\Storefront\Abandonment\AbandonmentSignal;
use AsterMD\Storefront\Abandonment\AbandonmentState;
use AsterMD\Storefront\Abandonment\AbandonmentSweep;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

final class AbandonmentSweepTest extends TestCase
{
    use TempDatabase;

    private const string BASE_URL = 'https://shop.example.test';

    private const string TELEFORM = 'tf_intake';

    /** A fixed "now", so every window in these tests is arithmetic rather than wall clock. */
    private const int NOW = 1_800_000_000;

    /** The clinical answer that must never reach the emitted payload. */
    private const string CLINICAL_ANSWER = 'semaglutide-2.4mg-weekly';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    private function repository(): SessionRepository
    {
        return new SessionRepository(fn (): \PDO => $this->pdo);
    }

    private function sweep(int $idleSeconds = 3600, int $lookbackSeconds = 2_592_000, int $limit = 500): AbandonmentSweep
    {
        return new AbandonmentSweep(
            sessions: $this->repository(),
            baseUrl: self::BASE_URL,
            resumeParam: 'amd_session',
            idleSeconds: $idleSeconds,
            lookbackSeconds: $lookbackSeconds,
            limit: $limit,
            marketingConsentKey: 'marketing',
            clock: static fn (): int => self::NOW,
        );
    }

    /** Writes one `sessions` row whose `updated_at` is exactly `$idleSeconds` old. */
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

    private static function cartOnly(): JourneyState
    {
        $state = new JourneyState();
        $state->cart = ['lines' => [['slug' => 'abc123-tadalafil', 'quantity' => 1]]];

        return $state;
    }

    private static function intakeInProgress(): JourneyState
    {
        $state = self::cartOnly();
        $state->opportunityId = 'opp_1';
        $state->furthestStep = 'prequalification';
        $state->formAnswers = [self::TELEFORM => ['medication' => self::CLINICAL_ANSWER]];
        $state->formStatus = [self::TELEFORM => JourneyState::FORM_IN_PROGRESS];

        return $state;
    }

    /** @param list<array{key: string, granted: bool}> $consents */
    private static function submittedCheckout(array $consents): JourneyState
    {
        $state = self::intakeInProgress();
        $state->formStatus = [self::TELEFORM => JourneyState::FORM_COMPLETED];
        $state->recordEmrEvent(CheckoutEvent::CheckoutVisited->value);
        $state->buyer = ['email' => 'ada@example.test', 'first_name' => 'Ada'];
        $state->consents = array_map(static fn (array $consent): array => [
            'key' => $consent['key'],
            'granted' => $consent['granted'],
            'copy_version' => 'v1',
            'copy_shown' => 'copy',
            'at' => '2026-08-25T09:00:00+00:00',
        ], $consents);
        // The decline itself, which is what makes this a payment abandoner
        // rather than a checkout one. Consents alone are also true of a
        // submission the provider never saw.
        $state->recordEmrEvent(CheckoutEvent::OrderDeclined->value);

        return $state;
    }

    public function testAJourneyIdleLongerThanTheConfiguredIntervalIsEmittedAsASignal(): void
    {
        $this->seed('11111111-1111-4111-8111-111111111111', self::cartOnly(), 7200);

        $signals = $this->sweep(idleSeconds: 3600)->signals();

        self::assertCount(1, $signals);
        self::assertSame(AbandonmentState::Cart, $signals[0]->state);
        self::assertSame('11111111-1111-4111-8111-111111111111', $signals[0]->sessionUuid);
        self::assertSame(7200, $signals[0]->idleSeconds);
    }

    public function testAJourneyStillInsideTheConfiguredIntervalIsNotYetAbandoned(): void
    {
        $this->seed('22222222-2222-4222-8222-222222222222', self::cartOnly(), 1800);

        self::assertSame([], $this->sweep(idleSeconds: 3600)->signals());
    }

    public function testAJourneyIdleLongerThanTheLookbackWindowIsNoLongerEmitted(): void
    {
        $this->seed('33333333-3333-4333-8333-333333333333', self::cartOnly(), 60 * 86400);

        self::assertSame([], $this->sweep(idleSeconds: 3600, lookbackSeconds: 30 * 86400)->signals());
    }

    public function testACompletedJourneyEmitsNoSignalAtAll(): void
    {
        $state = self::submittedCheckout([['key' => 'marketing', 'granted' => true]]);
        $state->recordPlacedOrder('34660');
        $state->upsellOutcomes = ['offer-a' => JourneyState::UPSELL_OFFERED];
        $state->completedAt = '2026-08-25T09:30:00+00:00';
        $this->seed('44444444-4444-4444-8444-444444444444', $state, 7200);

        self::assertSame([], $this->sweep()->signals());
    }

    /** `[21.11]`: every signal carries the resume link and the furthest completed step. */
    public function testEverySignalCarriesTheResumeLinkAndTheFurthestCompletedStep(): void
    {
        $this->seed('55555555-5555-4555-8555-555555555555', self::intakeInProgress(), 7200);

        $signal = $this->sweep()->signals()[0];

        self::assertSame(
            'https://shop.example.test/?amd_session=55555555-5555-4555-8555-555555555555',
            $signal->resumeUrl,
        );
        self::assertSame('prequalification', $signal->furthestStep);
    }

    /** `[21.11b]` with `[26.4]`: a declined marketing consent is marked so the platform can suppress. */
    public function testADeclinedMarketingConsentIsMarkedOnTheSignal(): void
    {
        $state = self::submittedCheckout([
            ['key' => 'terms', 'granted' => true],
            ['key' => 'marketing', 'granted' => false],
            ['key' => 'transactional_sms', 'granted' => true],
        ]);
        $this->seed('66666666-6666-4666-8666-666666666666', $state, 7200);

        $signal = $this->sweep()->signals()[0];

        self::assertSame(AbandonmentState::Payment, $signal->state);
        self::assertSame(AbandonmentSignal::MARKETING_DECLINED, $signal->marketingConsent);
    }

    public function testAGrantedMarketingConsentIsMarkedSeparatelyFromATransactionalOne(): void
    {
        $state = self::submittedCheckout([
            ['key' => 'marketing', 'granted' => true],
            ['key' => 'transactional_sms', 'granted' => false],
        ]);
        $this->seed('77777777-7777-4777-8777-777777777777', $state, 7200);

        self::assertSame(AbandonmentSignal::MARKETING_GRANTED, $this->sweep()->signals()[0]->marketingConsent);
    }

    /**
     * A cart abandoner never reached the consent controls, so the answer is
     * "not asked" — deliberately its own value rather than being folded into
     * "declined", because `[26.2]` makes consent an explicit affirmative act
     * and a question nobody was asked is neither a grant nor a refusal.
     */
    public function testAJourneyThatNeverReachedTheConsentControlsIsMarkedAsNeverAsked(): void
    {
        $this->seed('88888888-8888-4888-8888-888888888888', self::cartOnly(), 7200);

        self::assertSame(AbandonmentSignal::MARKETING_NOT_ASKED, $this->sweep()->signals()[0]->marketingConsent);
    }

    /**
     * PHI. `JourneyState::$formAnswers` holds clinical answers, and the
     * emitted document reports the *fact* of an abandoned intake and never its
     * content.
     */
    public function testNoClinicalAnswerAppearsAnywhereInTheEmittedPayload(): void
    {
        $this->seed('99999999-9999-4999-8999-999999999999', self::intakeInProgress(), 7200);

        $encoded = (string) json_encode(array_map(
            static fn (AbandonmentSignal $signal): array => $signal->toArray(),
            $this->sweep()->signals(),
        ));

        self::assertStringNotContainsString(self::CLINICAL_ANSWER, $encoded);
        self::assertStringNotContainsString('medication', $encoded);
        self::assertStringContainsString('intake_abandoned', $encoded);
    }

    /** The buyer's own contact details are not the automation's to read out of this document either. */
    public function testTheBuyersContactDetailsDoNotAppearInTheEmittedPayload(): void
    {
        $state = self::submittedCheckout([['key' => 'marketing', 'granted' => true]]);
        $this->seed('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $state, 7200);

        $encoded = (string) json_encode($this->sweep()->signals()[0]->toArray());

        self::assertStringNotContainsString('ada@example.test', $encoded);
        self::assertStringNotContainsString('Ada', $encoded);
    }

    /**
     * `[21.13]`: a resumed visitor must not re-fire what was already
     * recorded. The key is stable for as long as the journey has not moved,
     * so a consumer polling every ten minutes sees the same signal it has
     * already acted on; it changes the moment the journey moves again,
     * because `sessions.updated_at` is fingerprint-gated and advances only on
     * a real change.
     */
    public function testTheSignalKeyIsStableWhileTheJourneyHasNotMovedAndChangesOnceItDoes(): void
    {
        $this->seed('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', self::cartOnly(), 7200);

        $first = $this->sweep()->signals()[0]->signalKey();
        $second = $this->sweep()->signals()[0]->signalKey();
        self::assertSame($first, $second);

        $this->pdo->prepare('UPDATE sessions SET updated_at = ? WHERE session_uuid = ?')
            ->execute([gmdate('c', self::NOW - 5400), 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);

        self::assertNotSame($first, $this->sweep()->signals()[0]->signalKey());
    }

    public function testSignalsArrangeTheLongestIdleJourneyFirstAndStopAtTheConfiguredLimit(): void
    {
        $this->seed('cccccccc-cccc-4ccc-8ccc-cccccccccccc', self::cartOnly(), 7200);
        $this->seed('dddddddd-dddd-4ddd-8ddd-dddddddddddd', self::cartOnly(), 86400);
        $this->seed('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', self::cartOnly(), 10800);

        $signals = $this->sweep(limit: 2)->signals();

        self::assertCount(2, $signals);
        self::assertSame(
            ['dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'],
            array_map(static fn (AbandonmentSignal $signal): string => $signal->sessionUuid, $signals),
        );
    }

    /** A row whose journey classifies as nothing is skipped rather than emitted as a null state. */
    public function testAnIdleSessionThatTookNoStepProducesNoSignal(): void
    {
        $this->seed('ffffffff-ffff-4fff-8fff-ffffffffffff', new JourneyState(), 7200);

        self::assertSame([], $this->sweep()->signals());
    }

    /** The emitted document's key set is the wire contract an external system polls. */
    public function testTheEmittedPayloadCarriesExactlyTheDocumentedKeys(): void
    {
        $this->seed('12121212-1212-4121-8121-121212121212', self::intakeInProgress(), 7200);

        self::assertSame(
            [
                'signal_key',
                'state',
                'session_uuid',
                'opportunity_id',
                'furthest_step',
                'resume_url',
                'marketing_consent',
                'last_moved_at',
                'idle_seconds',
            ],
            array_keys($this->sweep()->signals()[0]->toArray()),
        );
    }
}

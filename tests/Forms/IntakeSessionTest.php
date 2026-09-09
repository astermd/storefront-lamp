<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Forms\AnswerEncoder;
use AsterMD\Storefront\Forms\AnswerValidator;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\Disqualification;
use AsterMD\Storefront\Forms\FormUnavailable;
use AsterMD\Storefront\Forms\IntakeSession;
use AsterMD\Storefront\Forms\LeadWriter;
use AsterMD\Storefront\Forms\RecordMapper;
use AsterMD\Storefront\Forms\RuleEvaluator;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeIntakeGateway;
use AsterMD\Storefront\Tests\Support\FakeLeadGateway;
use PHPUnit\Framework\TestCase;

/**
 * One visitor's pass through one questionnaire, exercised against the real
 * recorded definition wherever its size is the point and against a two-page
 * form wherever "every field is valid" has to be a readable array.
 *
 * The collaborators are the real ones — the evaluator, the validator, the
 * disqualification reader and the record mapper all run — and only the two
 * EMR gateways are fakes, because what this class is *for* is the order those
 * collaborators run in. A mocked validator would let every ordering pass.
 *
 * The ordering that matters most is asserted directly: answers reach the
 * journey before anything is validated, evaluated or sent, so a visitor whose
 * page failed validation still has their typing on file. The other half of
 * the same asymmetry is asserted too — a failed submission record is
 * swallowed and the visitor advances (`[10.26]`), while a matched hard rule
 * always stops them (`[10.44a]`).
 */
final class IntakeSessionTest extends TestCase
{
    private const string SESSION = 'sess-1234567890abcdef';

    private const string TELEFORM = 'tf-weight-loss';

    /**
     * The shape of the recorded metadata's `db_fields`, reduced to the keys
     * these cases exercise — including `[11.7]`'s inconsistency, where the BMI
     * score has no `.value` segment and its measurements do.
     */
    private const array DB_FIELDS = [
        'first_name' => 'opportunity.first_name',
        'last_name' => 'opportunity.last_name',
        'email' => 'opportunity.email',
        'phone' => 'opportunity.phone',
        'bmi_measurement' => 'opportunity.clinical.bmi',
        'bmi_height' => 'opportunity.clinical.height.value',
        'bmi_weight' => 'opportunity.clinical.weight.value',
    ];

    /**
     * A complete, qualifying first page of the recorded form: 66 inches and
     * 200 pounds put the derived BMI at 32, clear of the `less_than '27'` hard
     * stop, and every choice answer is an option *value* rather than a label.
     */
    private const array PAGE_ONE = [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'phone' => '5551234567',
        'date_of_birth' => '1990-04-01',
        'age_confirmation' => ['yes'],
        'sex_at_birth' => ['female'],
        'pregnancy_status' => ['no'],
        'comorbidities_conditions' => ['high-blood-pressure'],
        'bmi_height' => '66',
        'bmi_weight' => '200',
    ];

    private string $dir;

    private JourneyState $state;

    private FakeIntakeGateway $submissions;

    private FakeLeadGateway $leads;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/intake-session-' . bin2hex(random_bytes(6));
        $this->state = JourneyState::fromArray([], null, null);
        $this->submissions = new FakeIntakeGateway();
        $this->leads = new FakeLeadGateway();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testOpenFiresTheInitiatedEventOnceAndNeverAgainForTheSameJourney(): void
    {
        $session = $this->session(self::recordedForm());

        $session->open(self::TELEFORM, IntakeSession::STEP_INTAKE);
        $session->open(self::TELEFORM, IntakeSession::STEP_INTAKE);

        // `[4.13]`: the read model is what knows whether the EMR already has
        // the event, so a re-opened form must not produce a second one.
        self::assertCount(1, $this->submissions->records);
        self::assertSame(Event::IntakeInitiated, $this->submissions->records[0]['event']);
        self::assertSame(['page' => 1, 'total' => 5], $this->submissions->records[0]['progress']);
        self::assertTrue($this->state->hasEmrEvent(Event::IntakeInitiated->value));
    }

    public function testThePreQualificationStepFiresItsOwnEventTriple(): void
    {
        $this->session(self::recordedForm())->open(self::TELEFORM, IntakeSession::STEP_PREQUALIFICATION);

        self::assertSame(Event::PreQualifyingInitiated, $this->submissions->records[0]['event']);
    }

    public function testOpenPrefillsTheFormFromTheAnswersAlreadyStoredOnTheJourney(): void
    {
        // `[9.11]`: the server's own copy is the prefill source, so a returning
        // visitor sees what they told us rather than what a browser remembered.
        $this->state->storeAnswers(self::TELEFORM, ['first_name' => 'Ada', 'pregnancy_status' => ['no']]);

        $view = $this->session(self::recordedForm())->open(self::TELEFORM, IntakeSession::STEP_INTAKE);

        self::assertSame('Ada', $view->answers->value('first_name'));
        self::assertSame(['no'], $view->answers->value('pregnancy_status'));
        self::assertSame([], $view->errors, 'a first render has nothing to correct');
    }

    public function testAnAnswerToAQuestionTheFormNoLongerAsksReachesNoControlAndNoSubmission(): void
    {
        // `[9.12]`: a form is republished with a question removed while an
        // answer to it is still on the journey. Both surfaces that leave this
        // class are driven by the current definition rather than by the stored
        // map, so the retired answer has nowhere to appear: no field to draw it
        // into, and no entry in what the submission carries.
        $this->state->storeAnswers(self::TELEFORM, [
            'first_name' => 'Ada',
            'retired_question' => 'an answer to a question no longer asked',
        ]);

        $view = $this->session(self::recordedForm())->open(self::TELEFORM, IntakeSession::STEP_INTAKE);

        self::assertNull($view->definition->field('retired_question'));
        self::assertSame('Ada', $view->answers->value('first_name'));
        self::assertNotContains(
            'retired_question',
            array_column(AnswerEncoder::encode($view->definition, $view->answers), 'name'),
        );
    }

    public function testAnAnswerToAWithdrawnQuestionSurvivesTheNextSaveWithoutReachingAnythingDownstream(): void
    {
        // `[28.16]`: a republished form that drops a question must not destroy
        // the answer already given to it. The question may come back, and the
        // answer is the visitor's rather than the form's — so it is carried
        // through storage untouched while every surface that leaves this class
        // stays driven by the current definition.
        $this->state->storeAnswers(self::TELEFORM, ['retired_question' => 'an answer to a question no longer asked']);

        $result = $this->session(self::recordedForm())
            ->save(self::TELEFORM, IntakeSession::STEP_INTAKE, self::PAGE_ONE, 0);

        self::assertTrue($result->valid());
        self::assertSame(
            'an answer to a question no longer asked',
            $this->state->answersFor(self::TELEFORM)['retired_question'] ?? null,
            'the save must not have overwritten the retired answer away',
        );
        self::assertSame('Ada', $this->state->answersFor(self::TELEFORM)['first_name']);

        // Preserved in storage, invisible everywhere else: neither the
        // submission the EMR receives nor the mapped lead record may carry a
        // field the current form does not declare.
        self::assertNotContains(
            'retired_question',
            array_column($this->submissions->records[0]['data'], 'name'),
        );
        self::assertStringNotContainsString(
            'retired_question',
            (string) json_encode($this->leads->creates[0]),
        );
    }

    public function testTwoSavesOfDifferentPagesLeaveBothPagesAnswersStored(): void
    {
        // `[10.27]`: a save carries one page, so merging rather than replacing
        // is what lets someone step back and forward without emptying the rest.
        $session = $this->session(self::recordedForm());

        $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, self::PAGE_ONE, 0);
        $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, ['mtc_men2_history' => ['no']], 1);

        $stored = $this->state->answersFor(self::TELEFORM);
        self::assertSame('Ada', $stored['first_name']);
        self::assertSame(['no'], $stored['mtc_men2_history']);
    }

    public function testSaveReportsTheClampedOneBasedPositionOfThePageBeingAdvancedTo(): void
    {
        // `[10.24]`: the position names where the visitor is going, not where
        // they were, and it can never exceed the form's own page count.
        $session = $this->session(self::recordedForm());

        $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, self::PAGE_ONE, 0);
        self::assertSame(['page' => 2, 'total' => 5], $this->submissions->records[0]['progress']);
        self::assertSame(Event::IntakeInProgress, $this->submissions->records[0]['event']);

        // A page number past the end is clamped rather than allowed to select
        // no page at all, so it validates the last page instead of validating
        // nothing -- which is why this one reports errors rather than an
        // advance. Submission is the gate either way.
        $beyond = $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, self::PAGE_ONE, 9);
        self::assertFalse($beyond->valid(), 'an out-of-range page cannot skip validation');
        self::assertCount(1, $this->submissions->records, 'an invalid advance records nothing');
    }

    public function testThePositionNeverExceedsTheFormsOwnPageCount(): void
    {
        // A single-page form: advancing off its only page still reports page 1
        // of 1 rather than page 2 of 1 (`[10.24]`).
        $session = $this->session(['pages' => [[
            'pageId' => 'only', 'order' => 0, 'fields' => [
                ['fieldId' => 'note', 'name' => 'note', 'type' => 'text', 'label' => 'Note'],
            ],
        ]]]);

        $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, ['note' => 'ok'], 0);

        self::assertSame(['page' => 1, 'total' => 1], $this->submissions->records[0]['progress']);
    }

    public function testAnInvalidPageReturnsItsOwnFieldsErrorsAndDoesNotAdvance(): void
    {
        $result = $this->session(self::recordedForm())
            ->save(self::TELEFORM, IntakeSession::STEP_INTAKE, ['first_name' => 'Ada'], 0);

        self::assertFalse($result->valid());
        self::assertArrayHasKey('last_name', $result->errors);
        self::assertArrayNotHasKey('first_name', $result->errors);
        // `[10.20]`: a later page's required questions are not yet overdue.
        self::assertArrayNotHasKey('telehealth_consent', $result->errors);
        self::assertSame([], $this->submissions->records, 'a page that did not pass is not an advance');
    }

    public function testTheAnswersAreStoredBeforeValidationSoAFailedSaveNeverLosesThem(): void
    {
        // The ordering this class exists to guarantee: the journey is written
        // before anything is validated, evaluated or sent, because a visitor
        // whose typing vanished because a later step threw is the failure that
        // ordering prevents.
        $result = $this->session(self::recordedForm())
            ->save(self::TELEFORM, IntakeSession::STEP_INTAKE, ['first_name' => 'Ada', 'phone' => '5551234567'], 0);

        self::assertFalse($result->valid());
        self::assertSame('Ada', $this->state->answersFor(self::TELEFORM)['first_name']);
        self::assertSame('5551234567', $this->state->answersFor(self::TELEFORM)['phone']);
    }

    public function testAFailedSubmissionRecordProducesNoErrorAndDoesNotBlockTheAdvance(): void
    {
        // `[10.26]`: losing a save is preferable to trapping someone inside a
        // medical questionnaire, so the visitor never learns the EMR refused.
        $this->submissions->result = false;

        $result = $this->session(self::recordedForm())
            ->save(self::TELEFORM, IntakeSession::STEP_INTAKE, self::PAGE_ONE, 0);

        self::assertSame([], $result->errors);
        self::assertTrue($result->valid());
        self::assertFalse($result->disqualified());
        self::assertCount(1, $this->submissions->records, 'the record was attempted, and its refusal swallowed');
    }

    public function testTheLeadIsCapturedOnlyOnceAFirstNameAndAnEmailAreBothPresent(): void
    {
        // `[9.9]`: below both, the record is a fragment nobody can follow up
        // on, so the gateway is not called at all.
        $session = $this->session(self::shortForm());

        $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, ['first_name' => 'Ada'], 0);
        self::assertSame([], $this->leads->creates);

        $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, ['email' => 'ada@example.com'], 0);
        self::assertCount(1, $this->leads->creates);
        self::assertSame('Ada', $this->leads->creates[0]['first_name']);
    }

    public function testAnEarlyCaptureWritesTheLeadFromAPageValidationWouldHaveRefused(): void
    {
        // `[9.4]`: a capture fires on a field blur, so the page it arrives from
        // is half-answered by definition. Two of the recorded form's eleven
        // required first-page questions are answered here — which is exactly
        // the shape of body page validation refuses, and exactly the visitor
        // this mechanism exists to catch.
        $session = $this->session(self::recordedForm());
        $partial = ['first_name' => 'Ada', 'email' => 'ada@example.com'];

        $saved = $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, $partial, 0);

        self::assertFalse($saved->valid(), 'a step advance still owes the page its required answers');
        self::assertSame([], $this->leads->creates, 'and reaches no lead write at all');

        $outcome = $session->capture(self::TELEFORM, $partial);

        // The two paths have to differ: a capture routed through the save
        // would be refused by the same validation and write nothing, which is
        // the whole mechanism being inert.
        self::assertTrue($outcome->created());
        self::assertCount(1, $this->leads->creates);
        self::assertSame('Ada', $this->leads->creates[0]['first_name']);

        // `[10.24]`: a capture is not a step advance, so it records no
        // in-progress event and reports no position.
        self::assertSame([], $this->submissions->records);
    }

    public function testAMatchedHardRuleStopsTheSaveAndIsRecordedOnTheJourney(): void
    {
        // The BMI hard stop is the one rule in the recorded form that can
        // actually fire: it compares a numeric threshold against a score the
        // server derives, not an option label against an option value.
        $result = $this->session(self::recordedForm())->save(
            self::TELEFORM,
            IntakeSession::STEP_INTAKE,
            [...self::PAGE_ONE, 'bmi_weight' => '120'],
            0,
        );

        self::assertTrue($result->disqualified());
        self::assertSame('bmi_low_hard_stop_notice', $result->ruleId);
        self::assertNotSame('', (string) $result->message, '[10.44]: the visitor is owed the authored reason');
        self::assertTrue($this->state->isDisqualified());
        self::assertSame('bmi_low_hard_stop_notice', $this->state->disqualifiedRule);
        self::assertSame(self::TELEFORM, $this->state->disqualifiedTeleform);
    }

    public function testADisqualifiedSaveReportsNoFieldErrors(): void
    {
        // `[10.43]`: someone who answered honestly and is not eligible has not
        // filled the form in wrongly. This page is missing almost everything
        // it requires, and none of that is what they are told.
        $result = $this->session(self::recordedForm())->save(
            self::TELEFORM,
            IntakeSession::STEP_INTAKE,
            ['bmi_height' => '66', 'bmi_weight' => '120'],
            0,
        );

        self::assertTrue($result->disqualified());
        self::assertSame([], $result->errors);
        self::assertSame([], $this->submissions->records, 'a stopped journey is not an advance');
    }

    public function testCorrectingTheAnswerThatCausedTheStopLiftsTheDisqualification(): void
    {
        // `[10.43]`: a disqualifying answer is very often a mistyped one, and
        // the terminal page invites the visitor back to fix it. A verdict that
        // outlived the answer behind it would make that invitation a lie — the
        // only way out would be abandoning the treatment and every answer with
        // it.
        $session = $this->session(self::recordedForm());
        $mistyped = [...self::PAGE_ONE, 'bmi_height' => '70', 'bmi_weight' => '120'];

        $stopped = $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, $mistyped, 0);

        self::assertTrue($stopped->disqualified(), '70 inches at 120 pounds is a BMI of 17, under the form\'s threshold of 27');
        self::assertSame('bmi_low_hard_stop_notice', $this->state->disqualifiedRule);

        // The same person, 220 pounds: a BMI of 32, and no hard rule left to
        // trip.
        $corrected = $session->save(self::TELEFORM, IntakeSession::STEP_INTAKE, ['bmi_weight' => '220'], 0);

        self::assertFalse($corrected->disqualified());
        self::assertFalse($this->state->isDisqualified());
        self::assertNull($this->state->disqualifiedRule);
        self::assertNull($this->state->disqualifiedTeleform);
    }

    public function testAVerdictFromAnotherQuestionnaireIsNotLiftedByCorrectingThisOne(): void
    {
        // The scope of the lift. The eligibility form and the intake form ask
        // overlapping questions under names each chose for itself, so an
        // answer corrected here says nothing about the rule that stopped the
        // journey there — and clearing it would re-open a funnel the server
        // closed on a question the visitor never revisited (`[10.44a]`).
        $this->state->recordDisqualification('tf-eligibility', 'pregnancy_hard_stop');

        $result = $this->session(self::recordedForm())
            ->save(self::TELEFORM, IntakeSession::STEP_INTAKE, self::PAGE_ONE, 0);

        self::assertTrue($result->valid(), 'this form\'s own answers are acceptable');
        self::assertTrue($this->state->isDisqualified());
        self::assertSame('pregnancy_hard_stop', $this->state->disqualifiedRule);
        self::assertSame('tf-eligibility', $this->state->disqualifiedTeleform);
    }

    public function testSubmitValidatesEveryPageAndNotOnlyTheLast(): void
    {
        // The check that a form cannot be completed by posting its last step
        // directly: page one is filled in and nothing else is.
        $result = $this->session(self::recordedForm())
            ->submit(self::TELEFORM, IntakeSession::STEP_INTAKE, self::PAGE_ONE);

        self::assertFalse($result->valid());
        self::assertFalse($result->completed);
        self::assertArrayHasKey('mtc_men2_history', $result->errors, 'page two');
        self::assertArrayHasKey('telehealth_consent', $result->errors, 'page five');
        self::assertFalse($this->state->formCompleted(self::TELEFORM));
    }

    public function testSubmitRecordsTheCompletedEventAndMarksTheFormCompleted(): void
    {
        $result = $this->session(self::shortForm())->submit(self::TELEFORM, IntakeSession::STEP_INTAKE, [
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'consent' => 'on',
        ]);

        self::assertTrue($result->completed);
        self::assertSame([], $result->errors);
        self::assertTrue($this->state->formCompleted(self::TELEFORM));
        self::assertSame(IntakeSession::STEP_INTAKE, $this->state->furthestStep);

        $last = $this->submissions->records[array_key_last($this->submissions->records)];
        self::assertSame(Event::IntakeCompleted, $last['event']);
        self::assertSame(['page' => 2, 'total' => 2], $last['progress']);
    }

    public function testResubmittingAnEarlierQuestionnaireDoesNotMoveTheFurthestStepBackwards(): void
    {
        // `[21.7]`: a visitor who reached checkout and went back to correct
        // their eligibility answers has not un-reached checkout. A plain
        // assignment here demoted them, and the abandonment signal then
        // described a buyer at the payment step as still being mid-funnel
        // (`[21.11]`).
        $this->state->furthestStep = 'checkout';

        $result = $this->session(self::shortForm())->submit(self::TELEFORM, IntakeSession::STEP_PREQUALIFICATION, [
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'consent' => 'on',
        ]);

        self::assertTrue($result->completed);
        self::assertSame('checkout', $this->state->furthestStep);
    }

    public function testCompletingTheMedicalFormStillAdvancesPastTheEligibilityOne(): void
    {
        // The other half of the rule: monotonic must not mean frozen.
        $this->state->furthestStep = IntakeSession::STEP_PREQUALIFICATION;

        $this->session(self::shortForm())->submit(self::TELEFORM, IntakeSession::STEP_INTAKE, [
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'consent' => 'on',
        ]);

        self::assertSame(IntakeSession::STEP_INTAKE, $this->state->furthestStep);
    }

    public function testSubmitRefusesToCompleteWhenAHardRuleMatchesEvenThoughEveryFieldIsValid(): void
    {
        // `[10.45]`: the server is the gate. Every answer here is acceptable
        // and the submission is still refused.
        $result = $this->session(self::shortFormWithHardStop())->submit(self::TELEFORM, IntakeSession::STEP_INTAKE, [
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'consent' => 'on',
            'pregnancy_status' => 'yes',
        ]);

        self::assertTrue($result->disqualified());
        self::assertSame('pregnancy_hard_stop', $result->ruleId);
        self::assertFalse($result->completed);
        self::assertSame([], $result->errors);
        self::assertFalse($this->state->formCompleted(self::TELEFORM));
    }

    public function testSubmitRefusesToCompleteWhenTheDefinitionContainsATypeThisRendererCannotDraw(): void
    {
        // `[10.13]`: a clinician must never read an intake that silently
        // dropped a question, so an unimplemented type refuses the submission
        // rather than completing without it.
        $result = $this->session(self::shortFormWithUnsupportedType())->submit(self::TELEFORM, IntakeSession::STEP_INTAKE, [
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'consent' => 'on',
        ]);

        self::assertFalse($result->valid());
        self::assertFalse($result->completed);
        self::assertArrayHasKey('_form', $result->errors);
        self::assertStringContainsString('cannot collect', $result->errors['_form']);
        self::assertFalse($this->state->formCompleted(self::TELEFORM));
    }

    public function testOpenOnAnUnresolvableTeleformIsTheFormUnavailableState(): void
    {
        // `[10.3]`: the controller turns this into a page that says so. An
        // empty form would look like it worked and collect nothing.
        $this->expectException(FormUnavailable::class);

        $this->session(null)->open(self::TELEFORM, IntakeSession::STEP_INTAKE);
    }

    /**
     * The class under test with its real collaborators and fake gateways.
     *
     * A null definition stands for a teleform the EMR will not resolve.
     *
     * @param array<string, mixed>|null $definition
     */
    private function session(?array $definition): IntakeSession
    {
        $log = new OperatorLog($this->dir . '/app.log');
        $evaluator = new RuleEvaluator($log);
        $mapper = new RecordMapper($log);

        return new IntakeSession(
            new TeleformSource(self::gateway($definition), new DefinitionCache($this->dir, 3600), $log),
            $this->journey(),
            new AnswerValidator($evaluator),
            // No config directory, so no configured fallback rules: every rule
            // these cases evaluate is one the definition itself authored.
            new Disqualification($evaluator, Config::load($this->dir), $log),
            $this->submissions,
            new LeadWriter($this->leads, $mapper, $log),
            $mapper,
            $log,
        );
    }

    /**
     * A store holding this test's state directly, the way
     * {@see \AsterMD\Storefront\Http\Middleware\AttributionMiddleware} adopts
     * a journey for a session minted on the current request. Nothing here
     * reads or writes the database, so the connection is never opened — a
     * closure that would throw if it were is how that stays true.
     */
    private function journey(): JourneyStore
    {
        $store = new JourneyStore(new SessionRepository(
            static fn (): \PDO => throw new \LogicException('This journey is adopted, never loaded.'),
        ));
        $store->adopt(self::SESSION, $this->state);

        return $store;
    }

    /** @param array<string, mixed>|null $definition */
    private static function gateway(?array $definition): TeleformGateway
    {
        return new class($definition, self::DB_FIELDS) implements TeleformGateway {
            /**
             * @param array<string, mixed>|null $definition
             * @param array<string, string>     $dbFields
             */
            public function __construct(private readonly ?array $definition, private readonly array $dbFields)
            {
            }

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                if ($this->definition === null) {
                    return null;
                }

                return new TeleformMetadata(
                    id: $teleformId,
                    // The identifier carries the form's version and publish
                    // epoch in real data; it varies with the definition here
                    // for the same reason, so one test's cached copy can never
                    // answer another's fetch.
                    identifier: 'acct/org/form_' . md5((string) json_encode($this->definition)) . '.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: $this->dbFields,
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return $this->definition;
            }
        };
    }

    /** The real 5-page, 56-field weight-loss intake recorded from staging. @return array<string, mixed> */
    private static function recordedForm(): array
    {
        return (array) json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/teleform-definition.json'),
            true,
        );
    }

    /**
     * Two pages, three questions: small enough that "every field is valid" is
     * a readable array, which the recorded form's five pages of required
     * questions is not.
     *
     * @param list<array<string, mixed>> $extraFields appended to the second page
     *
     * @return array<string, mixed>
     */
    private static function shortForm(array $extraFields = []): array
    {
        return [
            'formId' => 'f-short',
            'formName' => 'Short intake',
            'pages' => [
                [
                    'pageId' => 'p1',
                    'title' => 'About you',
                    'order' => 0,
                    'fields' => [
                        ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
                        // Optional on purpose: the capture threshold has to be
                        // reachable one field at a time.
                        ['fieldId' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => false],
                    ],
                ],
                [
                    'pageId' => 'p2',
                    'title' => 'Consent',
                    'order' => 1,
                    'fields' => [
                        ['fieldId' => 'consent', 'name' => 'consent', 'type' => 'terms', 'label' => 'Consent', 'required' => true],
                        ...$extraFields,
                    ],
                ],
            ],
        ];
    }

    /**
     * The same short form plus a danger alert revealed by an answer — which is
     * how a hard stop is really authored, an alert field with a `show`
     * condition rather than a declared termination rule.
     *
     * @return array<string, mixed>
     */
    private static function shortFormWithHardStop(): array
    {
        return self::shortForm([
            [
                'fieldId' => 'pregnancy_status', 'name' => 'pregnancy_status', 'type' => 'choice-single',
                'label' => 'Are you pregnant?', 'required' => false,
                'properties' => ['options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
            ],
            [
                'fieldId' => 'pregnancy_hard_stop', 'name' => 'pregnancy_hard_stop', 'type' => 'alert',
                'label' => 'Not eligible', 'required' => false,
                'properties' => [
                    'alertType' => 'danger',
                    'alertText' => 'We are unable to prescribe this treatment during pregnancy.',
                ],
                'conditions' => [[
                    'conditionId' => 'c-pregnancy', 'action' => 'show', 'target' => 'pregnancy_hard_stop',
                    'logic' => 'and',
                    'rules' => [['field' => 'pregnancy_status', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);
    }

    /** The same short form plus a question no shipped renderer implements. @return array<string, mixed> */
    private static function shortFormWithUnsupportedType(): array
    {
        return self::shortForm([
            ['fieldId' => 'sig', 'name' => 'sig', 'type' => 'signature', 'label' => 'Sign here', 'required' => false],
        ]);
    }
}

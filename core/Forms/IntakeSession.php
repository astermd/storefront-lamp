<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Funnel\FurthestStep;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * One visitor's pass through one questionnaire: what to render, what a step
 * advance does, and what a submission decides.
 *
 * The ordering inside {@see self::save()} is the substance of this class and
 * is not arbitrary. Answers are persisted **before** anything is validated,
 * evaluated or sent anywhere, because a visitor whose answers vanish because
 * a later step threw is the failure this ordering exists to prevent. Only
 * once they are safely stored does the rest happen — and of that rest, only
 * one outcome may stop the visitor.
 *
 * That asymmetry is the rule worth remembering: a failed submission record or
 * a failed lead write is swallowed and logged, because losing a save is
 * better than trapping someone inside a medical questionnaire (`[10.26]`,
 * `[20.2]`). A matched hard rule always stops them (`[10.44a]`). Nothing else
 * does.
 *
 * The class also owns the two lifecycle-event triples, so the mapping from a
 * funnel step to the EMR's event vocabulary lives in exactly one place.
 */
final class IntakeSession
{
    public const string STEP_PREQUALIFICATION = 'prequalification';
    public const string STEP_INTAKE = 'intake';

    public function __construct(
        private readonly TeleformSource $source,
        private readonly JourneyStore $journey,
        private readonly AnswerValidator $validator,
        private readonly Disqualification $disqualification,
        private readonly IntakeGateway $submissions,
        private readonly LeadWriter $leads,
        private readonly RecordMapper $mapper,
        private readonly OperatorLog $log,
    ) {
    }

    /**
     * @throws FormUnavailable when the definition can be neither fetched nor served from cache
     */
    public function open(string $teleformId, string $step): IntakeView
    {
        $definition = $this->definition($teleformId);
        $metadata = $this->metadata($teleformId);
        $state = $this->journey->state();
        $answers = $this->answers($teleformId, $definition);

        [$initiated] = self::events($step);

        // Fired only if the EMR has not already recorded it (`[4.13]`): a
        // returning visitor who re-opens the form must not produce a second
        // lead record, and the read-model is what knows whether they have.
        if ($state !== null && !$state->hasEmrEvent($initiated->value)) {
            $recorded = $this->submissions->record(
                (string) $this->journey->sessionUuid(),
                $initiated,
                $teleformId,
                AnswerEncoder::encode($definition, $answers),
                self::position(1, $definition),
            );

            if ($recorded) {
                $state->recordEmrEvent($initiated->value);
            }
        }

        return new IntakeView(
            definition: $definition,
            metadata: $metadata,
            answers: $answers,
            errors: [],
            terminationMessage: $this->standingTermination($teleformId, $definition),
            unsupportedTypes: $definition->unsupportedTypes(),
            rules: $this->disqualification->rulesFor($teleformId, $definition),
        );
    }

    /**
     * One step advance: store what arrived, decide whether the journey may go
     * on, and report anything the visitor has to fix.
     *
     * @param array<string, mixed> $posted
     *
     * @throws FormUnavailable
     */
    public function save(string $teleformId, string $step, array $posted, int $pageIndex): IntakeResult
    {
        $definition = $this->definition($teleformId);
        $answers = $this->store($teleformId, $definition, $posted);

        $outcome = $this->disqualification->evaluate($teleformId, $definition, $answers);
        if ($outcome->isHard()) {
            return $this->stop($teleformId, $outcome);
        }

        $this->lift($teleformId);

        // Clamped, so a posted page number past the end cannot select no page
        // at all and thereby validate nothing.
        $errors = $this->validator->validate($definition, $answers, max(0, min($pageIndex, $definition->pageCount() - 1)));
        if ($errors !== []) {
            return IntakeResult::invalid($errors);
        }

        [, $inProgress] = self::events($step);
        $this->report($teleformId, $definition, $answers, $inProgress, self::position($pageIndex + 2, $definition));

        return IntakeResult::saved();
    }

    /**
     * Final submission. Every page is validated, not just the last, and a
     * matched hard rule refuses the completion even when every field is
     * valid — the server decides whether the journey may proceed (`[10.45]`).
     *
     * @param array<string, mixed> $posted
     *
     * @throws FormUnavailable
     */
    public function submit(string $teleformId, string $step, array $posted): IntakeResult
    {
        $definition = $this->definition($teleformId);
        $answers = $this->store($teleformId, $definition, $posted);

        $unsupported = $definition->unsupportedTypes();
        if ($unsupported !== []) {
            // Refusing here rather than accepting a form with missing answers
            // is the whole point of `[10.13]`: a clinician must never read an
            // intake that silently dropped a question.
            $this->log->warning('intake.submission_blocked_unsupported_types', [
                'teleform' => $teleformId,
                'types' => implode(',', $unsupported),
            ]);

            return IntakeResult::invalid([
                '_form' => sprintf(
                    'This questionnaire contains a question we cannot collect here (%s). Please contact us and we will complete it with you.',
                    implode(', ', $unsupported),
                ),
            ]);
        }

        $outcome = $this->disqualification->evaluate($teleformId, $definition, $answers);
        if ($outcome->isHard()) {
            return $this->stop($teleformId, $outcome);
        }

        $this->lift($teleformId);

        $errors = $this->validator->validate($definition, $answers, null);
        if ($errors !== []) {
            return IntakeResult::invalid($errors);
        }

        [, , $completed] = self::events($step);
        $this->report($teleformId, $definition, $answers, $completed, self::position($definition->pageCount(), $definition));

        $state = $this->journey->state();
        if ($state !== null) {
            $state->markFormCompleted($teleformId);

            // `[21.7]`: forwards only. A visitor who reached checkout and came
            // back to correct an eligibility answer has not un-reached
            // checkout, and the assignment this replaced said they had -- so a
            // buyer sitting on a declined payment was advertised to as though
            // they were still mid-questionnaire (`[21.11]`).
            FurthestStep::advance($state, $step);
        }

        return IntakeResult::finished();
    }

    /**
     * Early capture (`[9.4]`): store what has been typed so far and write the
     * lead if it has crossed the threshold.
     *
     * Deliberately not routed through {@see self::save()}. A capture fires on
     * a field blur, so by definition the page is half-answered — running page
     * validation first would refuse every real capture, and the mechanism that
     * exists to catch abandoners would write nothing at all. No in-progress
     * event is recorded either: this is not a step advance.
     *
     * @param array<string, mixed> $posted
     *
     * @throws FormUnavailable
     */
    public function capture(string $teleformId, array $posted): LeadOutcome
    {
        $definition = $this->definition($teleformId);
        $answers = $this->store($teleformId, $definition, $posted);

        $state = $this->journey->state();
        $session = (string) $this->journey->sessionUuid();
        if ($state === null || $session === '') {
            return LeadOutcome::noneCreated();
        }

        return $this->leads->capture(
            $session,
            $state,
            $this->mapper->build($this->metadata($teleformId), $answers, $definition),
        );
    }

    /**
     * The definition exactly as it was authored, for the renderer that hands
     * it to the hosted engine. Deliberately the source document rather than a
     * re-serialisation of {@see Definition}: the engine understands keys this
     * codebase does not model, and round-tripping through the parser would
     * quietly drop them.
     *
     * @return array<string, mixed>
     *
     * @throws FormUnavailable
     */
    public function rawDefinition(string $teleformId): array
    {
        return $this->source->definitionFor($teleformId);
    }

    /**
     * Stored answers in the shape the hosted engine wants for its
     * `initialValues`, filtered the same way a server render filters them
     * (`[9.12]`) so neither renderer prefills a question the form dropped.
     *
     * @return array<string, mixed>
     *
     * @throws FormUnavailable
     */
    public function initialValues(string $teleformId): array
    {
        return $this->answers($teleformId, $this->definition($teleformId))->all();
    }

    /**
     * Merges what arrived into what was already known and persists it before
     * anything else can fail (`[10.27]`).
     *
     * @param array<string, mixed> $posted
     */
    private function store(string $teleformId, Definition $definition, array $posted): AnswerSet
    {
        $state = $this->journey->state();
        $stored = $state === null ? [] : $state->answersFor($teleformId);

        $answers = $this->answers($teleformId, $definition)
            ->merge($posted, $definition)
            ->withDerived($definition);

        // Answers to questions the form no longer asks are carried through
        // rather than dropped. Nothing reads them — everything downstream goes
        // through the filtered set — but a republished form that removes a
        // question must not destroy what was already answered, because the
        // question may come back and the answer is the visitor's (`[28.16]`).
        $state?->storeAnswers($teleformId, array_merge(self::orphans($stored, $definition), $answers->all()));

        return $answers;
    }

    /**
     * The stored answers, filtered to what the form still asks (`[9.12]`).
     *
     * A question removed from a republished form leaves its answer behind, and
     * that answer must not be resurrected: nothing renders it, but
     * {@see RecordMapper} walks the metadata's mapping rather than the
     * definition, so an orphaned answer whose target is still mapped would
     * otherwise keep being written to the clinical record. Filtering here is
     * what makes "the form no longer asks this" mean the same thing everywhere
     * downstream. The answers are left on the journey untouched — dropping
     * them from storage would destroy data a corrected form could still want
     * (`[28.16]`).
     */
    private function answers(string $teleformId, Definition $definition): AnswerSet
    {
        $state = $this->journey->state();
        $stored = $state === null ? [] : $state->answersFor($teleformId);

        return AnswerSet::fromArray(self::declaredOnly($stored, $definition))->withDerived($definition);
    }

    /**
     * Stored answers the current definition no longer declares.
     *
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    private static function orphans(array $answers, Definition $definition): array
    {
        $orphans = [];
        foreach ($answers as $name => $value) {
            if ($definition->field((string) $name) === null) {
                $orphans[$name] = $value;
            }
        }

        return $orphans;
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    private static function declaredOnly(array $answers, Definition $definition): array
    {
        $kept = [];
        foreach ($answers as $name => $value) {
            $field = $definition->field((string) $name);
            if ($field !== null && !$field->isDisplayOnly()) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /**
     * Records the disqualification and reports it. The cart line that
     * triggered it is deliberately left alone: clearing it here would empty
     * the drawer while the visitor is still reading why, and a resumed
     * terminal state has to re-evaluate against the line that caused it
     * (`[10.48]`). Starting over is what clears it.
     */
    private function stop(string $teleformId, DisqualificationOutcome $outcome): IntakeResult
    {
        $ruleId = (string) $outcome->ruleId();
        $this->journey->state()?->recordDisqualification($teleformId, $ruleId);

        // Which rule fired is the whole point of capturing a disqualification
        // (`[10.46]`). The rule id names a question, never an answer.
        $this->log->info('intake.disqualified', ['teleform' => $teleformId, 'rule' => $ruleId]);

        return IntakeResult::stopped($ruleId, (string) $outcome->message());
    }

    /**
     * Withdraws a standing disqualification once the answers no longer trip
     * any hard rule.
     *
     * This is what makes `[10.43]`'s correctable termination real: a
     * disqualifying answer is often simply a mistyped one, and the terminal
     * page invites the visitor back to fix it. Without this the verdict
     * outlived the answer that caused it, so the only way out was to abandon
     * the treatment and lose every answer.
     *
     * Scoped to the form that produced the verdict, so correcting the intake
     * cannot clear an eligibility form's stop.
     */
    private function lift(string $teleformId): void
    {
        $state = $this->journey->state();
        if ($state === null || !$state->isDisqualified() || $state->disqualifiedTeleform !== $teleformId) {
            return;
        }

        $this->log->info('intake.disqualification_lifted', [
            'teleform' => $teleformId,
            'rule' => $state->disqualifiedRule,
        ]);
        $state->clearDisqualification();
    }

    /**
     * The two swallowed writes: the submission record and the lead. Neither
     * may surface as an error, because neither failing is the visitor's
     * problem to solve (`[10.26]`, `[20.2]`).
     *
     * @param array{page: int, total: int}|null $progress
     */
    private function report(
        string $teleformId,
        Definition $definition,
        AnswerSet $answers,
        Event $event,
        ?array $progress,
    ): void {
        $session = (string) $this->journey->sessionUuid();
        if ($session !== '') {
            $this->submissions->record($session, $event, $teleformId, AnswerEncoder::encode($definition, $answers), $progress);
        }

        $state = $this->journey->state();
        if ($state === null || $session === '') {
            return;
        }

        $this->leads->capture($session, $state, $this->mapper->build($this->metadata($teleformId), $answers, $definition));
    }

    /** The message a journey already stopped on this form should still be shown on re-entry (`[10.48]`). */
    private function standingTermination(string $teleformId, Definition $definition): ?string
    {
        $state = $this->journey->state();
        if ($state === null || !$state->isDisqualified() || $state->disqualifiedTeleform !== $teleformId) {
            return null;
        }

        foreach ($this->disqualification->rulesFor($teleformId, $definition) as $rule) {
            if ($rule->id === $state->disqualifiedRule) {
                return $rule->message;
            }
        }

        return null;
    }

    private function definition(string $teleformId): Definition
    {
        return Definition::fromArray($this->source->definitionFor($teleformId));
    }

    private function metadata(string $teleformId): TeleformMetadata
    {
        $metadata = $this->source->metadataFor($teleformId);
        if ($metadata === null) {
            throw new FormUnavailable(sprintf('No teleform metadata for "%s".', $teleformId));
        }

        return $metadata;
    }

    /**
     * The visitor's position as a page/total pair, 1-based and clamped to the
     * form's real page count (`[10.24]`). Null when the count is unknown,
     * since a guessed position is worse than none (`[10.25]`).
     *
     * @return array{page: int, total: int}|null
     */
    private static function position(int $page, Definition $definition): ?array
    {
        $total = $definition->pageCount();
        if ($total < 1) {
            return null;
        }

        return ['page' => max(1, min($page, $total)), 'total' => $total];
    }

    /** @return array{0: Event, 1: Event, 2: Event} initiated, in-progress, completed */
    private static function events(string $step): array
    {
        return $step === self::STEP_PREQUALIFICATION
            ? [Event::PreQualifyingInitiated, Event::PreQualifyingInProgress, Event::PreQualifyingCompleted]
            : [Event::IntakeInitiated, Event::IntakeInProgress, Event::IntakeCompleted];
    }
}

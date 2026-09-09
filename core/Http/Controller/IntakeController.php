<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Forms\FieldViewModel;
use AsterMD\Storefront\Forms\FormUnavailable;
use AsterMD\Storefront\Forms\IntakeSession;
use AsterMD\Storefront\Forms\IntakeView;
use AsterMD\Storefront\Forms\RuleEvaluator;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\FunnelRules;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The intake and pre-qualification steps.
 *
 * Which questionnaire a step collects comes from the cart, and specifically
 * from {@see FunnelRules} — the same answer {@see \AsterMD\Storefront\Funnel\StepPreconditions}
 * guards the step with and {@see FunnelRouter} routes on (`[8.2]`, `[8.3]`).
 * Deciding it here as well is what stranded a visitor between a gate waiting
 * for one form and a page serving another, so the rule is asked for rather
 * than restated. A step with no form to collect forwards instead of rendering
 * an empty page, which is the live configuration on any channel that folds its
 * eligibility questions into the intake form.
 *
 * Both renderers post here. Mode A's engine draws its own fields and shows
 * its own notices, and mode C's markup is drawn from the same definition, but
 * neither decides anything: {@see IntakeSession} evaluates every save and
 * every submit, so the two modes cannot disagree about whether a journey may
 * proceed (`[28.3]`, `[10.45]`).
 */
final class IntakeController
{
    /**
     * Built from the catalog rather than injected, the same way
     * {@see FunnelRouter} and {@see \AsterMD\Storefront\Funnel\StepPreconditions}
     * build it: the shared rule is an implementation detail of the three
     * collaborators that must agree, not a fourth thing the container has to
     * know how to wire.
     */
    private readonly FunnelRules $rules;

    /**
     * How many completed submits may fail forward before the visitor is told
     * plainly that the questionnaire cannot be recorded.
     *
     * Two, because one is the ruling and the second is the proof: the first
     * completed form goes on to checkout on the chance that the outage was a
     * single request, and a visitor who has been handed the same form back
     * after that is in a loop rather than in a blip.
     */
    private const int FAIL_FORWARD_ATTEMPTS = 2;

    /**
     * Where the tally of completed-but-unrecordable submits is kept.
     *
     * The PHP session rather than the journey, which is the thing that is
     * missing whenever this counts at all.
     */
    private const string UNRECORDED_COMPLETIONS = '_intake_unrecorded_completions';

    public function __construct(
        private readonly IntakeSession $intake,
        private readonly CartStore $carts,
        private readonly JourneyStore $journeys,
        ProductCatalog $catalog,
        private readonly FlowDefinition $flow,
        private readonly RuleEvaluator $evaluator,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly OperatorLog $log,
        private readonly FunnelRouter $router,
    ) {
        $this->rules = new FunnelRules($catalog);
    }

    public function prequalification(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, IntakeSession::STEP_PREQUALIFICATION);
    }

    public function intake(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, IntakeSession::STEP_INTAKE);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $step = $this->stepOf($request);
        $teleformId = $this->teleformId($step);
        if ($teleformId === null) {
            return self::redirect($response, $this->onwardPath());
        }

        try {
            $result = $this->intake->save($teleformId, $step, self::answersFrom($body), (int) ($body['page'] ?? 0));
        } catch (FormUnavailable) {
            return $this->unavailable($request, $response);
        }

        if ($result->disqualified()) {
            return self::redirect($response, $this->flow->pathFor('not_eligible'));
        }

        if (!$result->valid()) {
            return $this->render($request, $response, $step, $result->errors);
        }

        return self::redirect($response, $this->flow->stepForPath($this->pathOf($step)) === null ? '/' : $this->pathOf($step));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $step = $this->stepOf($request);
        $teleformId = $this->teleformId($step);
        if ($teleformId === null) {
            return $this->onward($request, $response, $step);
        }

        try {
            $result = $this->intake->submit($teleformId, $step, self::answersFrom($body));
        } catch (FormUnavailable) {
            return $this->unavailable($request, $response);
        }

        if ($result->disqualified()) {
            return self::redirect($response, $this->flow->pathFor('not_eligible'));
        }

        if (!$result->valid()) {
            return $this->render($request, $response, $step, $result->errors);
        }

        return $this->onward($request, $response, $step);
    }

    /**
     * Early capture (`[9.4]`): the lead is created the moment a first name and
     * an email are both known, which is what turns someone who fills in two
     * fields and leaves into a lead rather than nothing.
     *
     * The response says only whether a record was made (`[9.6]`). Echoing any
     * of the submitted values back would put clinical answers into a body a
     * proxy or an access log could keep.
     */
    public function capture(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $step = $this->stepOf($request);
        $teleformId = $this->teleformId($step);

        if (!$this->limiter->allow('intake.capture')) {
            $this->log->info('intake.capture_rate_limited', []);

            return self::json($response->withStatus(429), ['captured' => false]);
        }

        if ($teleformId === null) {
            return self::json($response, ['captured' => false]);
        }

        try {
            $outcome = $this->intake->capture($teleformId, self::answersFrom((array) ($request->getParsedBody() ?? [])));
        } catch (FormUnavailable) {
            return self::json($response, ['captured' => false]);
        }

        // Whether a record was actually made, not whether the page validated
        // (`[9.6]`): the client uses this to tell "captured" from "already had
        // one", and a half-answered page is the normal case here.
        return self::json($response, ['captured' => $outcome->created()]);
    }

    /** @param array<string, string> $errors */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $step,
        array $errors = [],
    ): ResponseInterface {
        // Drawing the questionnaire is the commonest way a visitor comes back
        // through an outage, so it is where the tally has to be forgotten.
        // See {@see self::forgetFailedCompletions()}.
        $this->forgetFailedCompletions();

        $teleformId = $this->teleformId($step);
        if ($teleformId === null) {
            // Nothing to ask. Forwarding rather than drawing an empty page is
            // what keeps a channel with one combined intake form walkable.
            return self::redirect($response, $this->onwardPath());
        }

        try {
            $view = $this->intake->open($teleformId, $step);
        } catch (FormUnavailable) {
            return $this->unavailable($request, $response);
        }

        $renderer = (string) $this->config->get('intake.renderer', 'server');
        $template = $renderer === 'js-engine'
            ? 'pages/intake/form-js-engine.twig'
            : 'pages/intake/form.twig';

        return Twig::fromRequest($request)->render($response, $template, $this->payload($view, $step, $errors, $renderer));
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function payload(IntakeView $view, string $step, array $errors, string $renderer): array
    {
        $answers = $view->answers->all();
        $rules = array_map(
            static fn ($rule): array => [
                'id' => $rule->id,
                'mode' => $rule->mode,
                'message' => $rule->message,
                'conditions' => $rule->conditions,
            ],
            $view->rules,
        );

        $payload = [
            'noindex' => true,
            'definition' => $view->definition,
            'form_title' => $step === IntakeSession::STEP_PREQUALIFICATION ? 'Eligibility' : 'Medical intake',
            'form_step' => $step,
            'unsupported_types' => $view->unsupportedTypes,
            'termination_message' => $view->terminationMessage,
            'stepper_steps' => self::stepperSteps(),
            'stepper_active' => $step === IntakeSession::STEP_PREQUALIFICATION ? 1 : 3,
            'intake_engine' => (array) $this->config->get('intake.engine', []),
            'definition_json' => self::embed($this->intake->rawDefinition($view->metadata->id)),
            // An object even when empty: the hosted engine spreads this into
            // its form state, and `[]` would reach it as an array.
            'initial_values_json' => $answers === [] ? '{}' : self::embed($answers),
            'rules_json' => self::embed($rules),
            'form_errors' => $errors,
        ];

        if ($renderer === 'js-engine') {
            return $payload;
        }

        // Mode C needs a view model per field, grouped by page, plus the raw
        // conditions so the stepper can re-evaluate them client-side.
        $fields = [];
        foreach ($view->definition->pages() as $index => $page) {
            $models = [];
            foreach ($page->fields as $field) {
                $models[] = $this->viewModel($field, $view, $answers, $errors);
            }
            $fields[$index] = $models;
        }

        $payload['fields'] = $fields;
        $payload['definition_has_buttons'] = self::hasAuthoredButtons($view);

        return $payload;
    }

    /**
     * @param array<string, mixed>  $answers
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function viewModel(\AsterMD\Storefront\Forms\Field $field, IntakeView $view, array $answers, array $errors): array
    {
        $state = $this->evaluator->state($field, $answers, $view->definition);
        $answer = $field->type === 'bmi' ? $answers : ($answers[$field->name] ?? null);
        return FieldViewModel::for($field, $state, $answer, $errors[$field->name] ?? null);
    }

    private static function hasAuthoredButtons(IntakeView $view): bool
    {
        foreach ($view->definition->fields() as $field) {
            if ($field->type === 'button') {
                return true;
            }
        }

        return false;
    }

    /**
     * Only what the visitor could have answered. A posted key the definition
     * does not declare is dropped by {@see AnswerSet::merge()} anyway; this
     * strips the framework's own fields first so they never reach it.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function answersFrom(array $body): array
    {
        unset($body['_csrf'], $body['page'], $body['intake_next'], $body['intake_submit'], $body['step']);

        return $body;
    }

    /**
     * Which questionnaire a request concerns.
     *
     * The save, submit and capture endpoints are shared by both steps, and
     * none of their paths names one — so the posted `step` field is the only
     * thing that can distinguish them, and both templates emit it. Guessing
     * from the URI instead would silently file every eligibility answer
     * against the medical form, completing a questionnaire the visitor was
     * never shown and opening checkout behind it.
     */
    private function stepOf(ServerRequestInterface $request): string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $posted = $body['step'] ?? null;
        if ($posted === IntakeSession::STEP_PREQUALIFICATION || $posted === IntakeSession::STEP_INTAKE) {
            return (string) $posted;
        }

        return str_contains($request->getUri()->getPath(), '/eligibility/')
            ? IntakeSession::STEP_PREQUALIFICATION
            : IntakeSession::STEP_INTAKE;
    }

    private function pathOf(string $step): string
    {
        return $this->flow->pathFor($step === IntakeSession::STEP_PREQUALIFICATION ? 'prequalification' : 'intake.medical');
    }

    /**
     * Where a finished questionnaire step hands the visitor next.
     *
     * The routing decision owns this (`[8.1]`); the controller only translates
     * a step name into a path. It was a local reimplementation while the
     * routing table was forward-only and could not see form completion, and
     * duplicating a decision is how the two came to disagree.
     *
     * The one thing the router cannot answer is what to do when the journey
     * could not be loaded at all. It reads a missing state as "nothing is
     * finished", which is the right answer for the step guard — that has to
     * fail closed — and the wrong one here: the visitor has just submitted the
     * form, and a router that cannot see the completion would send them back
     * to it. This fails forward instead and lets checkout's own guard decide
     * (`[20.1]`), which is the ruling and stands.
     *
     * **What does not hold is that failing forward ends the redirect.**
     * Checkout's guard degrades by failing closed, so for a prescription cart
     * it answers the fail-forward by sending the visitor straight back to the
     * form they have just filled in — and the round begins again, silently,
     * for as long as the outage lasts. {@see self::onward()} is what stops it;
     * this method answers only where the visitor is being sent.
     */
    private function onwardPath(): string
    {
        $state = $this->journeys->state();
        if ($state === null) {
            $this->log->warning('intake.completion_unknown', [
                'teleform' => $this->teleformId(IntakeSession::STEP_INTAKE),
            ]);

            return $this->flow->pathFor('checkout');
        }

        return $this->flow->pathFor($this->router->nextStep($this->cart(), $state));
    }

    /**
     * The response a finished questionnaire step gets, and the end of the
     * fail-forward loop.
     *
     * The fail-forward is kept exactly as ruled: the first completed form that
     * cannot be recorded still goes on to checkout, because the guard there is
     * a better judge of a degraded journey than this controller is, and most
     * outages are one request long.
     *
     * What is added is a floor under it. A visitor who has completed the form
     * and been handed it back is in a loop the storefront cannot route out of,
     * and the honest answer is the page that already exists for a
     * questionnaire that cannot be served: it says the fault is ours, that
     * nothing they entered is lost, and it offers a way to retry and a way
     * back to the store. Any of those is better than a third identical round
     * with no message at all.
     *
     * **The tally lives in the PHP session, not in the journey.** The journey
     * is precisely what is missing during this outage, and the visitor's own
     * session is the one piece of per-visitor state that survives it — it is
     * where the cart itself is being kept for the same reason. It is cleared
     * wherever this controller sees a journey again, which
     * {@see self::forgetFailedCompletions()} states, so an outage a visitor
     * has already come through cannot spend the next one's first attempt.
     */
    private function onward(ServerRequestInterface $request, ResponseInterface $response, string $step): ResponseInterface
    {
        if ($this->journeys->state() !== null) {
            $this->forgetFailedCompletions();

            return self::redirect($response, $this->onwardPath());
        }

        $attempt = 1 + (int) ($_SESSION[self::UNRECORDED_COMPLETIONS] ?? 0);
        $_SESSION[self::UNRECORDED_COMPLETIONS] = $attempt;

        if ($attempt < self::FAIL_FORWARD_ATTEMPTS) {
            return self::redirect($response, $this->onwardPath());
        }

        // Stated once, and at error level, because it is a different event
        // from the warning above: one visitor failing forward is a degraded
        // request, and a visitor being handed back the form they completed is
        // a storefront that has stopped working for every prescription buyer
        // until the session service returns.
        if ($attempt === self::FAIL_FORWARD_ATTEMPTS) {
            $this->log->error('intake.completion_looping', [
                'attempts' => $attempt,
                'step' => $step,
                'reason' => 'the journey cannot be loaded, so the completed form cannot be routed past checkout',
            ]);
        }

        return $this->unavailable($request, $response, $this->pathOf($step));
    }

    /**
     * Forgets the fail-forward tally, because the outage it was counting is
     * over.
     *
     * The ruling the floor sits under is that the **first** completed form of
     * an outage still fails forward, and the floor is only meant to stop the
     * second, third and fourth. That makes "which outage is this" the whole
     * question, and the tally answers it only if it is cleared between them.
     *
     * It was cleared in {@see self::onward()} alone, which nothing but a
     * completed-form POST reaches — so a visitor who met a one-request blip,
     * came back and carried on normally still had the allowance spent, and the
     * *first* attempt of an unrelated blip an hour later got the dead-end page
     * the ruling grants nobody. Drawing the questionnaire is the request every
     * such visitor makes and the one this controller can see, so it clears the
     * tally too.
     *
     * A journey that loads is the whole test. It is exactly the fact whose
     * absence the tally counts, and reading it costs nothing here — the store
     * hands back what this request already resolved rather than opening
     * anything.
     */
    private function forgetFailedCompletions(): void
    {
        if ($this->journeys->state() === null) {
            return;
        }

        unset($_SESSION[self::UNRECORDED_COMPLETIONS]);
    }

    /**
     * Which questionnaire this step collects, or null when it has nothing to
     * ask.
     *
     * Deliberately not decided here. {@see FunnelRules} is the same answer the
     * step guard and the routing decision act on, so the form on screen is the
     * form checkout is waiting for by construction rather than by coincidence.
     * This used to walk the cart for the first line naming a form — which
     * agreed with a guard that did the same, and stopped agreeing the moment
     * the guard started demanding *every* form the cart names. That drift
     * stranded the visitor: the guard sent them here for a form they had not
     * filled in, this page served the one they had, and its submit sent them
     * back here for as long as they kept trying.
     *
     * Null when nothing is outstanding, and no invented identifier in its place
     * (`[20.8]`, `[21.9b]`): every caller forwards instead, which is also what
     * a channel folding its eligibility questions into the intake form needs.
     */
    private function teleformId(string $step): ?string
    {
        $cart = $this->cart();
        $state = $this->journeys->state();

        return $step === IntakeSession::STEP_PREQUALIFICATION
            ? $this->rules->prequalificationFormToCollect($cart, $state)
            : $this->rules->intakeFormToCollect($cart, $state);
    }

    /**
     * The cart is read through the store rather than fabricated, and a store
     * that cannot be built yields an empty cart rather than a 500 — the same
     * degrade-silently contract the guard honours (`[20.1]`).
     */
    private function cart(): Cart
    {
        try {
            return $this->carts->cart();
        } catch (\Throwable $e) {
            $this->log->warning('intake.cart_unavailable', ['exception' => $e::class]);

            return new Cart();
        }
    }

    /**
     * @param ?string $retryPath where the page's "try again" goes, when the request's own path is
     *                           not somewhere a visitor can be sent back to — a submit is a POST
     *                           endpoint and answers nothing to a GET
     */
    private function unavailable(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $retryPath = null,
    ): ResponseInterface {
        return Twig::fromRequest($request)->render($response, 'pages/intake/form-unavailable.twig', [
            'noindex' => true,
            'current_path' => $retryPath ?? $request->getUri()->getPath(),
        ]);
    }

    /** @return list<array{label: string, icon: string}> */
    private static function stepperSteps(): array
    {
        return [
            ['label' => 'Eligibility', 'icon' => 'gauge'],
            ['label' => 'Contact', 'icon' => 'user'],
            ['label' => 'Medical', 'icon' => 'list-ordered'],
            ['label' => 'Verify & Review', 'icon' => 'user-check'],
        ];
    }

    /**
     * JSON destined for a `<script type="application/json">` block. Encoded
     * here rather than in the template so the escaping is a property of the
     * value and not of whoever remembers to filter it: with the tag, ampersand
     * and quote flags set, the result cannot close its own script element,
     * which is what makes the template's `|raw` safe (`[13.4]`).
     */
    private static function embed(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @param array<string, mixed> $data */
    private static function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private static function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }
}

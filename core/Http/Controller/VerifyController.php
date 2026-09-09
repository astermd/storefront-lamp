<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\FurthestStep;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\RequestContext;
use AsterMD\Storefront\Verification\VerificationStep;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The identity-verification step (`[22.13]`).
 *
 * **One form, one POST, no JavaScript required.** Checkout set the precedent —
 * a single form whose sub-actions are `formaction` buttons, correct with
 * scripting off — and this page follows it. The page it replaces had an
 * unnamed file input and an unnamed text box in no form at all, and a "submit"
 * button whose only behaviour was a click handler assigning `window.location`:
 * it collected nothing and recorded nothing.
 *
 * **Guarding is not repeated here.** `/verify/` is a step in
 * `config/funnel.php` with its own requirements, and
 * {@see \AsterMD\Storefront\Http\Middleware\StepGuardMiddleware} enforces them
 * before this controller is reached (`[22.11]`). The one precondition this
 * controller *asks* about is `verification_satisfied`, and it asks
 * {@see StepPreconditions} rather than re-deriving it: `[22.16]` says placement
 * and blocking are separate settings that "must not be conflated", and a second
 * reading of that pair here is exactly how they would come to disagree.
 *
 * **The outcome is read back off the journey rather than passed through the
 * redirect.** `[22.20]` already requires the verdict to be durable, so a
 * post/redirect/get costs nothing and buys a page that survives a refresh, a
 * back button and a guard bouncing the visitor back onto it.
 *
 * **Three outcomes reach the buyer as three different sentences**, and only one
 * of them says a check failed. `[20.1]` allows that sentence for a refused
 * identity and for nothing else: a provider that answered without deciding, a
 * request this storefront built wrongly, and an integration that is switched
 * off all arrive as `inconclusive`, and none of them is a statement about the
 * person reading the page.
 *
 * **And each of those sentences has two forms, because the same verdict means
 * two different things under the two placements.** A verdict that did not pass
 * is a note on the file under a non-blocking placement and a closed door under
 * a blocking one, so a single table of copy is wrong for one of them by
 * construction — it told a buyer the gate had just refused that they could
 * carry on. Which form is used is not a second reading of the configuration:
 * it is {@see StepPreconditions} answering the same question the gate on the
 * next step will answer, so what the buyer is told and what happens to them
 * cannot disagree.
 */
final class VerifyController
{
    /**
     * This controller's own step name, as `config/funnel.php` declares it.
     *
     * Named because {@see self::onwardPath()} has to recognise it: a redirect
     * to the step the request is already on is not a hop the guard's
     * self-redirect check can see — neither end of it is a guard — so it is
     * logged nowhere and, under a blocking placement, exits nowhere.
     */
    private const string STEP = 'verify';

    /**
     * Where a visitor goes when the routing decision names the step they are
     * standing on.
     *
     * This is not a second routing decision. It is the answer
     * {@see FunnelRouter::nextStep()} itself gives once identity verification
     * is out of the way — the line after the placement check — and it is only
     * ever reached when the gate has already agreed this journey may pass
     * verification. What produces the disagreement is a journey that could not
     * be loaded: the placement reads a missing state as "never asked", which
     * is right for the router's usual caller and wrong for the step doing the
     * asking.
     */
    private const string STEP_AFTER = 'checkout';

    /**
     * What each recorded outcome says to a buyer the gate will let past.
     *
     * Written as a table because the alternative — a conditional in the
     * template — is where "did not pass" quietly becomes "failed". The
     * `inconclusive` line shares no phrase with the `failed` one, so no
     * substring of one can be read as the other.
     *
     * @var array<string, string>
     */
    private const array NOTICE = [
        JourneyState::VERIFICATION_PASSED => 'Your identity has been confirmed. You can carry on.',
        JourneyState::VERIFICATION_FAILED => 'We could not confirm your identity from the details we hold. We have noted that and you can carry on; contact support if you would like us to look again.',
        JourneyState::VERIFICATION_INCONCLUSIVE => 'This check could not be completed just now. Nothing is wrong with the details you gave us, and you can carry on.',
    ];

    /**
     * What the same outcomes say to a buyer the gate is holding.
     *
     * Every line here has to do two things the table above must not: say that
     * the journey has stopped, and name something the buyer can actually do
     * about it. The page offers exactly two actions — go back and correct a
     * detail, or submit again and re-run the check — so those are the two
     * offered, and support is the third for a buyer neither one helps.
     *
     * `inconclusive` deliberately still opens by saying nothing is wrong with
     * their details. `[20.1]` forbids an unrunnable check reading as a refused
     * identity, and a hold is not a licence to blur that: the buyer is being
     * stopped by an outage on this side of the conversation, and they are
     * entitled to know that is what happened.
     *
     * @var array<string, string>
     */
    private const array HELD_NOTICE = [
        JourneyState::VERIFICATION_FAILED => 'We could not confirm your identity from the details we hold, and we cannot continue until it is confirmed. There is nothing to change on this page — if one of the details you gave us earlier is wrong, go back and correct it. Otherwise contact support and we will confirm it with you.',
        JourneyState::VERIFICATION_INCONCLUSIVE => 'This check could not be completed just now, and we cannot continue until it has run. Nothing is wrong with the details you gave us. Try again in a few minutes, or contact support and we will complete it with you.',
    ];

    public function __construct(
        private readonly VerificationStep $step,
        private readonly CartStore $carts,
        private readonly JourneyStore $journeys,
        private readonly FlowDefinition $flow,
        private readonly FunnelRouter $router,
        private readonly StepPreconditions $preconditions,
        private readonly OperatorLog $log,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, $this->cart(), $this->journeys->state(), attempted: false);
    }

    /**
     * Runs the checks, records the outcome, and hands the visitor on.
     *
     * The redirect target is the routing decision (`[8.1]`) rather than a
     * hardcoded `/checkout/`, so a journey that has been disqualified or has
     * lost its cart between the render and the submit is sent somewhere that
     * will have it.
     *
     * Re-rendering instead of redirecting is reserved for the one case where
     * the redirect would not land: a placement that is holding this visitor.
     * There the guard on the next step would bounce them straight back, and
     * answering here is the same page with the outcome on it and one fewer
     * round trip.
     *
     * **A journey that cannot be loaded is not a fourth branch.** It used to
     * be: it logged and forwarded, on `[20.1]`'s rule that a bookkeeping
     * outage must not become a visitor who cannot proceed. But it forwarded
     * through the same routing decision, which reads a missing journey as a
     * journey that has never been asked and therefore names this very step —
     * so the fail-forward was a 303 from `/verify/` to `/verify/`, and under a
     * blocking placement `/checkout/` bounced back here too and the visitor
     * had no exit at all. The rule still stands and is honoured below by the
     * gate deciding, exactly as it decides for every other visitor: where it
     * would let them past, {@see self::onwardPath()} makes sure they actually
     * go somewhere; where it would hold them, they are told so.
     */
    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cart = $this->cart();
        $state = $this->journeys->state();

        if ($state === null) {
            $this->log->warning('verify.journey_unavailable', []);
        } else {
            $this->step->run(
                $cart,
                $state,
                (array) ($request->getParsedBody() ?? []),
                RequestContext::fromRequest($request)->clientIp,
            );
        }

        if (!$this->preconditions->satisfied('verification_satisfied', $cart, $state)) {
            return $this->render($request, $response, $cart, $state, attempted: true);
        }

        // The step is behind them now, which is what `[21.7]` means by
        // furthest *completed* step: the verdict is recorded and the gate has
        // agreed it may stand. Deliberately not written for a visitor the gate
        // is holding — they are still on this step, and the `[21.11]` signal
        // saying otherwise would be the same overstatement a checkout *visit*
        // was ruled not to be.
        FurthestStep::advance($state, self::STEP);

        return self::redirect($response, $this->onwardPath($cart, $state));
    }

    /**
     * The page, with the copy that matches what the gate will do to this
     * visitor.
     *
     * `$attempted` separates a buyer who has just run the checks from one who
     * has only opened the page. Under a blocking placement the gate refuses
     * both — the verdict is outstanding for the second one — so without it a
     * first visit would open with an outage notice for an outage that has not
     * happened.
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Cart $cart,
        ?JourneyState $state,
        bool $attempted,
    ): ResponseInterface {
        $status = $state?->verification['status'] ?? null;
        $status = is_string($status) ? $status : null;
        $held = !$this->preconditions->satisfied('verification_satisfied', $cart, $state);
        $notice = $this->notice($status, $held, $attempted);

        return Twig::fromRequest($request)->render($response, 'pages/verify.twig', [
            'noindex' => true,
            'collect_ssn' => $this->step->collectsSsn(),
            'verification_status' => $status,
            'verification_notice' => $notice,
            'verification_failed' => $status === JourneyState::VERIFICATION_FAILED,
            // Tied to the notice rather than to the hold, so the page cannot
            // offer a buyer a "Try again" with nothing above it saying why —
            // or, worse, print the hold and then label the only button out of
            // it as though this were an ordinary first visit.
            'verification_held' => $held && $notice !== null,
            'back_path' => $this->flow->pathFor('intake.medical'),
        ]);
    }

    /**
     * The sentence this visitor is owed, or null when they are owed none.
     *
     * A held submit with no recorded verdict at all takes the `inconclusive`
     * line, and that is the honest reading rather than a convenience: the
     * outcome could not be written down, so the check did not complete. The
     * one thing it must not become is a `failed` line — `[20.1]` — and the
     * journey being unreadable says nothing whatever about the buyer.
     */
    private function notice(?string $status, bool $held, bool $attempted): ?string
    {
        if ($status === null) {
            return $held && $attempted ? self::HELD_NOTICE[JourneyState::VERIFICATION_INCONCLUSIVE] : null;
        }

        if ($held) {
            return self::HELD_NOTICE[$status] ?? self::NOTICE[$status] ?? null;
        }

        return self::NOTICE[$status] ?? null;
    }

    /**
     * Where the routing decision sends this visitor, with the one answer it
     * must never give here taken out.
     *
     * A controller that 303s to the step it is already on is a loop no guard
     * can see and no log records, because `funnel.guard_self_redirect` fires
     * on a guard's own destination and neither end of this hop is a guard.
     * Guarded generally rather than by special-casing the journey that
     * provokes it today: the property that matters is that a step never
     * forwards onto itself, and it should hold whatever future condition makes
     * the router answer `verify` for somebody the gate has already let past.
     */
    private function onwardPath(Cart $cart, ?JourneyState $state): string
    {
        $next = $this->router->nextStep($cart, $state);

        if ($next === self::STEP) {
            $this->log->warning('verify.onward_self_redirect', [
                'reason' => 'the routing decision named this step for a journey the gate has already let past',
            ]);

            $next = self::STEP_AFTER;
        }

        return $this->flow->pathFor($next);
    }

    /**
     * The cart through the store, with a store that cannot be built yielding an
     * empty one rather than a 500 — the same degrade-silently contract the step
     * guard honours (`[20.1]`).
     */
    private function cart(): Cart
    {
        try {
            return $this->carts->cart();
        } catch (\Throwable $e) {
            $this->log->warning('verify.cart_unavailable', ['exception' => $e::class]);

            return new Cart();
        }
    }

    private static function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }
}

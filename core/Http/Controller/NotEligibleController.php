<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\CartMirror;
use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\Disqualification;
use AsterMD\Storefront\Forms\FormUnavailable;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\FunnelRules;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The terminal page a disqualified journey lands on, and the way back off it.
 *
 * The explanation shown is the one the form author wrote for the rule that
 * fired, not a generic line: it is a clinical message about a specific
 * contraindication, and only whoever authored the questionnaire can word it
 * (`[10.42]`). A visitor who reached here by typing the URL sees a neutral
 * fallback instead.
 *
 * Both ways off the page exist because a disqualifying answer is sometimes
 * simply a mistake (`[10.43]`). Going back re-opens the questionnaire with
 * every answer intact so one can be corrected. Starting over is the
 * destructive option, and it is where the triggering line leaves the cart
 * (`[10.47]`) — deliberately here rather than at the moment the rule fired,
 * because emptying the drawer while someone is still reading why would also
 * destroy what a resumed terminal state re-evaluates against (`[10.48]`).
 *
 * Nothing on this page mentions money. No payment has been taken at this
 * point in the funnel; the refund language of `[29.26]` belongs to a
 * termination that fires after checkout, which is a different state.
 */
final class NotEligibleController
{
    /**
     * Built from the catalog rather than injected, the same way every other
     * reader of the funnel's questionnaire rules builds it: which step collects
     * a given form is one answer shared with the guard and the routing
     * decision, not a fourth thing the container has to wire.
     */
    private readonly FunnelRules $funnel;

    public function __construct(
        private readonly TeleformSource $source,
        private readonly Disqualification $disqualification,
        private readonly CartStore $carts,
        ProductCatalog $catalog,
        private readonly CartRules $rules,
        private readonly CartMirror $mirror,
        private readonly JourneyStore $journeys,
        private readonly FlowDefinition $flow,
        private readonly OperatorLog $log,
    ) {
        $this->funnel = new FunnelRules($catalog);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $state = $this->journeys->state();

        return Twig::fromRequest($request)->render($response, 'pages/not-eligible.twig', [
            'noindex' => true,
            'reason' => $this->reason(),
            'treatment' => $this->treatmentName(),
            'back_path' => $this->flow->pathFor($this->stepOfVerdict()),
            'disqualified' => $state !== null && $state->isDisqualified(),
        ]);
    }

    /**
     * Clears the verdict and the answers behind it, drops the line that
     * triggered it, and sends the visitor back to browse.
     *
     * A POST because it destroys state: a link that wiped a questionnaire on
     * a prefetch would be a link that loses someone's answers without them
     * asking.
     */
    public function startOver(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $state = $this->journeys->state();

        if ($state !== null) {
            $teleformId = $state->disqualifiedTeleform;
            $state->clearDisqualification();

            if ($teleformId !== null) {
                $state->storeAnswers($teleformId, []);
                unset($state->formStatus[$teleformId]);
            }
        }

        $this->clearTriggeringLine($request);

        return $response->withStatus(303)->withHeader('Location', $this->flow->pathFor('home'));
    }

    /**
     * Removes the prescription the disqualification was about, leaving any
     * independently purchasable line behind (`[10.47]`).
     */
    private function clearTriggeringLine(ServerRequestInterface $request): void
    {
        try {
            $cart = $this->carts->cart();
        } catch (\Throwable $e) {
            $this->log->warning('intake.start_over_cart_unavailable', ['exception' => $e::class]);

            return;
        }

        $rx = $cart->rxLine();
        if ($rx === null) {
            return;
        }

        $this->rules->remove($cart, $rx->slug);
        $this->carts->save($cart);
        $this->mirror->mirror($request->getAttribute('session_uuid'), $cart, $this->journeys->state());
    }

    /**
     * Which questionnaire to send the visitor back to.
     *
     * The verdict may have come from the eligibility form rather than the
     * medical one, and sending someone back to a form they have not reached
     * would strand them in front of questions that cannot change the answer
     * that stopped them.
     *
     * Which step collects which form is {@see FunnelRules}' answer rather than
     * this class's, so it carries the part that matters here: pre-qualification
     * is opt-in twice (`[8.2]`), and a line naming an eligibility form without
     * asking for the step is not served by that step at all. Matching on the
     * name alone sent the way back to a step the routing decision will never
     * send anyone to, so the visitor's one chance to correct a mistyped answer
     * bounced them off it.
     */
    private function stepOfVerdict(): string
    {
        $state = $this->journeys->state();
        $teleformId = $state?->disqualifiedTeleform;
        if ($teleformId === null) {
            return 'intake.medical';
        }

        try {
            $cart = $this->carts->cart();
        } catch (\Throwable) {
            return 'intake.medical';
        }

        return $this->funnel->collectedAtPrequalification($teleformId, $cart) ? 'prequalification' : 'intake.medical';
    }

    /** The authored wording for the rule that fired, or null when this page was reached without a verdict. */
    private function reason(): ?string
    {
        $state = $this->journeys->state();
        if ($state === null || !$state->isDisqualified()) {
            return null;
        }

        $teleformId = $state->disqualifiedTeleform;
        if ($teleformId === null) {
            return null;
        }

        try {
            $definition = Definition::fromArray($this->source->definitionFor($teleformId));
        } catch (FormUnavailable) {
            // The verdict stands whether or not its wording can be fetched --
            // it was the server's, and it is recorded on the journey. Only the
            // explanation is lost, so the page falls back to its own copy.
            return null;
        }

        foreach ($this->disqualification->rulesFor($teleformId, $definition) as $rule) {
            if ($rule->id === $state->disqualifiedRule) {
                return $rule->message;
            }
        }

        return null;
    }

    private function treatmentName(): ?string
    {
        try {
            $cart = $this->carts->cart();
        } catch (\Throwable) {
            return null;
        }

        return $cart->rxLine()?->name;
    }
}

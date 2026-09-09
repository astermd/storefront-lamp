<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Storefront\Domain\StepRouter;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\Url;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Enforces step preconditions in one place rather than page by page
 * (`[22.11]`), which is what closes `[8.6]` structurally: no funnel template
 * carries a guard of its own, so none of them can be inconsistent about it.
 *
 * Steps are matched by path from the flow definition rather than by route
 * argument, because Slim's routing middleware runs inside this one and there
 * is no matched route to read yet. That is not merely a workaround: it keeps
 * the flow expressed as data (`[22.1]`, `[8.8]`) instead of as annotations
 * scattered over the route table.
 *
 * The redirect target is the routing decision (`[8.1]`), not "the first step
 * with unmet preconditions". With an empty cart every funnel step is
 * unsatisfied, and walking the list would send the visitor to a step that
 * would bounce them again; the routing decision answers "home" and the loop
 * cannot form.
 *
 * When the routing decision names the step the visitor is already on, the
 * guard fails closed: it redirects to the flow's entry step and logs
 * `funnel.guard_self_redirect`, because a flow whose routing decision points
 * at a step that cannot satisfy its own requirements is a defect, and serving
 * the unguarded page is never the safe branch.
 *
 * An unreachable database must not become a blocked storefront (`[20.1]`), and
 * the cart half of that holds outright. The visitor's live cart lives entirely
 * in the PHP session, and {@see \AsterMD\Storefront\Repository\SessionRepository}
 * opens its connection on first query rather than on construction, so building
 * the stores costs nothing and `cart_not_empty` and
 * `plan_chosen_for_every_rx_line` go on being evaluated against the visitor's
 * real cart right through an outage.
 *
 * The journey half cannot, and pretending otherwise is what this docblock used
 * to do. What an outage actually breaks is session resolution: it fails inside
 * {@see AttributionMiddleware}'s error boundary, no journey is loaded, and
 * {@see JourneyStore::state()} answers null for the rest of the request. At
 * that point the durable facts — which questionnaires were finished, whether
 * an order was placed — are *unknowable* rather than known-false, which is a
 * different question and one this guard has no way to answer. So it does not
 * answer it: {@see StepPreconditions} decides, per requirement, which way to
 * fail, because the right answer differs by requirement. The questionnaire
 * gates stay shut, since an outage does not make an uncollected medical form
 * safe (`[8.6]`) and nothing irreversible has happened to the visitor they
 * stop; `order_placed` opens, because by the time the receipt or the upsell is
 * asked for the money is taken and locking the buyer out of their own receipt
 * is the worse failure. Someone holding a *prescription* therefore does not
 * reach checkout during an outage — the earlier claim here that they did was
 * simply false — but someone holding a *receipt* does.
 *
 * What is left for {@see self::resolveStores()} to catch is a failure to
 * *build* the store pair at all. It degrades by letting the request through
 * unguarded rather than evaluating preconditions against a fabricated empty
 * cart, which would bounce a visitor who has every right to be there, and logs
 * the failure so an operator can see it.
 */
final class StepGuardMiddleware implements MiddlewareInterface
{
    /** @param \Closure(): array{0: CartStore, 1: JourneyStore} $stores resolved on first use, inside the error boundary */
    public function __construct(
        private readonly \Closure $stores,
        private readonly FlowDefinition $flow,
        private readonly StepRouter $router,
        private readonly StepPreconditions $preconditions,
        private readonly OperatorLog $log,
        private readonly bool $trace = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = Url::canonicalizePath($request->getUri()->getPath());
        $step = $this->flow->stepForPath($path);
        $requirements = $step === null ? [] : $this->flow->requirementsFor($step);

        // Resolved only when a requirement actually needs checking: a step
        // with nothing to require (home, upsell, receipt) has no reason to
        // build a cart store at all, and nothing that fails while building one
        // should be able to reach a page that was never guarded.
        if ($requirements !== []) {
            $stores = $this->resolveStores($path);

            if ($stores !== null) {
                [$carts, $journeys] = $stores;
                $cart = $carts->cart();
                $state = $journeys->state();

                foreach ($requirements as $requirement) {
                    if ($this->preconditions->satisfied($requirement, $cart, $state)) {
                        continue;
                    }

                    $target = $this->flow->pathFor($this->router->nextStep($cart, $state));

                    // A routing decision that names the step the visitor is
                    // already on cannot be followed, and must not be ignored
                    // either: falling through to the handler serves a page
                    // whose precondition is unmet, which is how someone
                    // reaches checkout without a chosen plan. Fail closed to
                    // the flow's entry step instead, and say so, because this
                    // means the flow definition and the routing decision
                    // disagree (`[22.11]`).
                    if ($target === $path) {
                        $this->log->warning('funnel.guard_self_redirect', [
                            'step' => $step,
                            'path' => $path,
                            'requirement' => $requirement,
                        ]);

                        $target = $this->flow->pathFor(FlowDefinition::ENTRY_STEP);

                        // The entry step failing its own requirement is the one
                        // case with nowhere safe to send anyone; redirecting
                        // would loop. Serving it is the lesser failure -- the
                        // entry step is a public page, not a guarded one.
                        if ($target === $path) {
                            break;
                        }
                    }

                    $response = (new Response(302))->withHeader('Location', $target);

                    return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'guard') : $response;
                }
            }
        }

        $response = $handler->handle($request);

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'guard') : $response;
    }

    /**
     * Null when the stores could not be built at all. The caller must not fall
     * back to evaluating preconditions against a fabricated empty cart: the
     * visitor's real cart lives in the PHP session regardless of what the
     * database is doing, and treating it as empty would bounce someone who has
     * every right to be on this page. Swallowing the failure here and logging it
     * is the same degrade-silently contract {@see AttributionMiddleware} and
     * {@see \AsterMD\Storefront\Journey\SessionResolver} already honour
     * (`[20.1]`).
     *
     * @return array{0: CartStore, 1: JourneyStore}|null
     */
    private function resolveStores(string $path): ?array
    {
        try {
            return ($this->stores)();
        } catch (\Throwable $e) {
            $this->log->warning('funnel.guard_unavailable', [
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Support\RequestContext;
use AsterMD\Storefront\Upsell\UpsellResult;
use AsterMD\Storefront\Upsell\UpsellService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The upsell step's HTTP surface: the offer, and the two answers to it.
 *
 * Thin by construction, the same way {@see CartController} and
 * {@see CheckoutController} are. Nothing is decided here — not whether there is
 * an offer, not whether the charge may be attempted, not where the buyer goes
 * next — because a copy of a rule in a controller is a copy that drifts from
 * the one {@see UpsellService} enforces.
 *
 * **The body may confirm which offer is being answered, and may never choose
 * one.** The offer acted on is always the one at the journey's own cursor
 * (`[16.16]`); the posted `upsell_key` is compared with it and a disagreement
 * refuses outright. So a stale page — or a hand-made request — cannot name an
 * offer this journey never reached, because naming a different one is exactly
 * what gets refused rather than honoured.
 *
 * The distinction is worth keeping sharp, because the obvious reading of a key
 * in the body is the dangerous one: an instruction from the browser about which
 * add-on to charge for. It is the opposite — an assertion about which add-on
 * was on screen, which the server is free to disbelieve and act on by showing
 * the buyer what is actually current.
 *
 * **An accept whose body omits the key confirms nothing, and is refused on
 * exactly those grounds.** Treating the omission as permission to skip the
 * check made the confirmation opt-out: leaving the field off charged whatever
 * the cursor pointed at, at whatever that offer costs, and a page rendered
 * before the field shipped does that by itself. The frozen quote does not
 * cover it either — once the current offer has been rendered in any tab it has
 * a quote of its own, and the stripped body charges it.
 *
 * The decline is deliberately *not* held to the same requirement. A refusal
 * redirects to the offer page, which renders server-side and does carry the
 * field, so the next click is confirmed — but if a deployment's theme dropped
 * the field, requiring it on both answers would leave the buyer with no way
 * off this page at all. Declining costs an offer rather than money, so it
 * stays the way out.
 *
 * Every method answers with a **303 See Other**, for the reason
 * {@see CartController}'s do: a reload of the response replays the GET the
 * redirect pointed at rather than reposting the mutation, and a charge is the
 * one mutation that must not be replayable by a refresh. That holds for the
 * GET with an empty queue too, which is `[16.6]`'s redirect to the receipt.
 */
final class UpsellController
{
    /**
     * What an accept whose body confirmed no offer is forwarded as.
     *
     * Empty rather than null, and never a key: no offer can be named by it, so
     * it agrees with nothing and the service's own refusal answers it. That
     * keeps the rule in one place — the controller says what the body said,
     * and {@see UpsellService::accept()} decides what to do about it.
     */
    private const string CONFIRMS_NOTHING = '';

    public function __construct(
        private readonly UpsellService $upsells,
        private readonly CartStore $carts,
        private readonly FlowDefinition $flow,
    ) {
    }

    /**
     * The offer, or the receipt when there is nothing left to offer (`[16.3]`,
     * `[16.5]`, `[16.6]`).
     *
     * `[16.16]` is the whole defence this step has, and it is here rather than
     * in a guard: a visitor who types this URL with no queue is not shown an
     * error, they are sent to the page they were going to next anyway. The
     * `order_placed` precondition in the flow definition is the other half.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $view = $this->upsells->view();

        if ($view === null) {
            return self::redirect($response, $this->receiptPath());
        }

        return Twig::fromRequest($request)->render($response, 'pages/upsell.twig', [
            'noindex' => true,
            'upsell' => $view,
        ]);
    }

    /** `[16.8]`, `[16.11]`: the charge is attempted, and the buyer moves on either way. */
    public function accept(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $answering = $body['upsell_key'] ?? null;

        return $this->answer($response, $this->upsells->accept(
            RequestContext::fromRequest($request),
            // Which offer the page that posted this was showing. It confirms
            // and can never choose: what is charged is whatever the queue's
            // cursor points at, and a key that disagrees refuses. The buyer's
            // own stale tab is what this catches -- one tab answering an offer
            // the other has already moved past.
            //
            // **A body that names nothing names nothing, and is answered as
            // such rather than as permission to skip the check.** Null is the
            // service's "there is nothing to confirm against"; forwarding it
            // for an absent field made the whole confirmation opt-out, and the
            // way out was simply to leave the field off -- which a page
            // rendered before the field shipped does by itself.
            is_string($answering) ? $answering : self::CONFIRMS_NOTHING,
        ));
    }

    /** `[16.9]`: no charge, no provider call, next offer. */
    public function decline(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $answering = $body['upsell_key'] ?? null;

        return $this->answer($response, $this->upsells->decline(
            is_string($answering) && $answering !== '' ? $answering : null,
        ));
    }

    /**
     * The result decides the response and nothing else does.
     *
     * A notice is flashed rather than rendered, because the answer is always a
     * redirect: `partials/cart-notice.twig`, which the layout this page extends
     * already includes, is the single-read sink every other flashed notice on
     * the site goes through.
     */
    private function answer(ResponseInterface $response, UpsellResult $result): ResponseInterface
    {
        if ($result->notice !== null) {
            $this->carts->flash($result->notice);
        }

        return self::redirect($response, $result->redirectTo);
    }

    private function receiptPath(): string
    {
        return isset($this->flow->steps()['receipt'])
            ? $this->flow->pathFor('receipt')
            : $this->flow->pathFor(FlowDefinition::ENTRY_STEP);
    }

    private static function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }
}

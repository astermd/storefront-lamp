<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Checkout\BuyerDetails;
use AsterMD\Storefront\Checkout\CheckoutResult;
use AsterMD\Storefront\Checkout\CheckoutService;
use AsterMD\Storefront\Checkout\CheckoutViewModel;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Support\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The checkout page's HTTP surface.
 *
 * Thin by construction, the same way {@see CartController} is: every method
 * reads the posted body, hands the decision to {@see CheckoutService}, and
 * either renders the view model or redirects. No rule is evaluated here — not
 * the geo gate, not the consent check, not the duplicate guard — because a
 * copy of a rule in a controller is a copy that drifts from the one the
 * service enforces everywhere else.
 *
 * **Every sub-action is a native form post that carries the whole form**
 * (`[27.10]`): a bump toggle, a promo apply, a promo remove and a plan switch
 * each repost the buyer's typed contact details, store them in journey state
 * on the way through, and answer with a **303 See Other** back to
 * `/checkout/`. That is what lets the summary update without leaving the page
 * and without losing anything typed, and it needs no JavaScript to be correct
 * (`[8.7]`'s reasoning applied here). The 303 also means a reload replays the
 * GET rather than the mutation, exactly as `CartController`'s do.
 *
 * `submit()` is the one method that does not always redirect. A decline, a
 * geo block and a failed validation all re-render the page at 200 with the
 * cart intact and the form re-filled (`[13.30]`, `[13.7]`) — **never the
 * card**, which is not a contact detail: echoing it into a `value` attribute
 * would put it in the browser's back-forward cache and in any proxy that logs
 * response bodies (`[15.8]`).
 */
final class CheckoutController
{
    /** `[13.8]`: what a refused flood answers with, so a client can back off rather than retry immediately. */
    private const int TOO_MANY_REQUESTS = 429;

    /**
     * Where every sub-action returns to.
     *
     * Hardcoded rather than resolved through the flow definition because it is
     * this controller's own page: a sub-action is a round trip to the page it
     * was posted from, not a funnel routing decision.
     */
    private const string CHECKOUT_PATH = '/checkout/';

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly CartStore $carts,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, $this->checkout->view());
    }

    /**
     * `[13.5]`, `[13.30]`, `[13.32]`, `[13.37]`.
     *
     * The result decides the response and nothing else does: a `redirectTo`
     * means the order was placed, a `refusedBecause` of
     * {@see CheckoutResult::REFUSED_RATE_LIMITED} means 429, and everything
     * else is the checkout page again with whatever the service has to say
     * about it.
     */
    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $buyer = BuyerDetails::fromSubmitted($body);

        $result = $this->checkout->submit(
            $buyer,
            self::credentialFrom($body),
            self::consentsFrom($body),
            RequestContext::fromRequest($request),
        );

        if ($result->redirectTo !== null) {
            return self::redirect($response, $result->redirectTo);
        }

        if ($result->refusedBecause === CheckoutResult::REFUSED_RATE_LIMITED) {
            $response = $response->withStatus(self::TOO_MANY_REQUESTS);
        }

        return $this->render(
            $request,
            $response,
            $this->checkout->view($buyer, $result->errors, $result->notice),
        );
    }

    /** `[27.10]`, `[27.11]`: the summary changes, the typed form does not. */
    public function bump(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $this->checkout->rememberBuyer(BuyerDetails::fromSubmitted($body));

        $outcome = $this->checkout->toggleBump(self::text($body['bump_slug'] ?? null));
        if ($outcome->notice !== null) {
            $this->carts->flash($outcome->notice);
        }

        return self::redirect($response, self::CHECKOUT_PATH);
    }

    /** `[13.11]`: the provider prices the code; this only records the answer. */
    public function promoApply(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $this->checkout->rememberBuyer(BuyerDetails::fromSubmitted($body));

        $outcome = $this->checkout->applyPromotion(
            self::text($body['discount_code'] ?? null),
            RequestContext::fromRequest($request),
        );

        if ($outcome->notice !== null) {
            $this->carts->flash($outcome->notice);
        }

        return self::redirect($response, self::CHECKOUT_PATH);
    }

    /** `[13.13]`: removing the code removes the discount, and nothing else. */
    public function promoRemove(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $this->checkout->rememberBuyer(BuyerDetails::fromSubmitted($body));
        $this->checkout->removePromotion();

        return self::redirect($response, self::CHECKOUT_PATH);
    }

    /** `[12.4]`, `[12.7]`: switching the plan re-prices the Rx line. */
    public function plan(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $this->checkout->rememberBuyer(BuyerDetails::fromSubmitted($body));

        $outcome = $this->checkout->choosePlan(
            self::text($body['slug'] ?? null),
            self::text($body['variant_id'] ?? null),
        );

        if ($outcome->notice !== null) {
            $this->carts->flash($outcome->notice);
        }

        return self::redirect($response, self::CHECKOUT_PATH);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        CheckoutViewModel $view,
    ): ResponseInterface {
        return Twig::fromRequest($request)->render($response, 'pages/checkout.twig', [
            'noindex' => true,
            'checkout' => $view,
        ]);
    }

    /**
     * The card, straight from the body and nowhere else.
     *
     * The expiry arrives as one `MM / YY` box because that is how a card is
     * printed and how the mockup asks for it. A two-digit year is expanded to
     * four in this century, which is the only reading a card can have: an
     * expiry is always in the future and no card is issued eighty years out.
     *
     * Nothing here validates the number. A card is the provider's to judge,
     * and a storefront-side "invalid card" message would either duplicate
     * their rules or contradict them (`[13.28]` shows their reason verbatim).
     *
     * @param array<string, mixed> $body
     */
    private static function credentialFrom(array $body): PaymentCredential
    {
        [$month, $year] = self::expiry(self::text($body['card_expiry'] ?? null));

        return PaymentCredential::card(
            self::text($body['card_number'] ?? null),
            $month,
            $year,
            self::text($body['card_cvc'] ?? null),
        );
    }

    /**
     * `MM / YY` or `MM/YYYY` → the two parts, or two empty strings when it is
     * neither.
     *
     * @return array{0: string, 1: string}
     */
    private static function expiry(string $raw): array
    {
        if (preg_match('#^\s*(\d{1,2})\s*[/\-\s]\s*(\d{2}|\d{4})\s*$#', $raw, $matches) !== 1) {
            return ['', ''];
        }

        $year = $matches[2];

        return [
            str_pad($matches[1], 2, '0', STR_PAD_LEFT),
            strlen($year) === 2 ? substr(gmdate('Y'), 0, 2) . $year : $year,
        ];
    }

    /**
     * The consent checkboxes, which the template groups under one name so an
     * unticked one is simply absent — which is what
     * {@see \AsterMD\Storefront\Checkout\Consents::record()} reads as a
     * decline rather than as a question never asked (`[26.10]`).
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function consentsFrom(array $body): array
    {
        $consents = $body['consents'] ?? null;

        return is_array($consents) ? $consents : [];
    }

    /**
     * '' when $value is anything other than a string — a missing field and an
     * array-valued one are both "nothing supplied" rather than being coerced
     * with `(string)`, which turns an array into the literal `"Array"`.
     */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }
}

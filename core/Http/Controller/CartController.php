<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Emr\CartMirror;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Support\Url;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The cart's HTTP surface: `add`, `remove` and `quantity`, each posted to
 * from the mini-cart drawer that partials all over the site render.
 *
 * The controller stays thin on purpose — it reads the posted body, hands the
 * mutation to {@see CartRules} (the only place `[7.x]` is actually
 * evaluated), saves the result, mirrors it, and redirects. No method here
 * decides whether a mutation is allowed; that decision, and the notice that
 * explains it, belongs entirely to the domain rule. A controller that grew
 * its own copy of a rule — "reject an unknown slug", say — would let that
 * copy drift from the one `CartRules` enforces everywhere else a cart is
 * touched (`[8.0c]`).
 *
 * Every method answers with a **303 See Other**, never a 200, so that a
 * browser reload of the response — or a user hitting back then forward —
 * replays the GET the redirect pointed at instead of reposting the mutation.
 * That holds whether the mutation was accepted or refused: a refusal still
 * has somewhere to send the visitor back to, and treating it as a 200 would
 * make "add" idempotent for acceptance but not for rejection, which is the
 * inconsistency this uniform response code avoids.
 *
 * `return_to` names the page to bounce back to and arrives as ordinary
 * attacker-controlled request data, so it is never trusted as a `Location`
 * verbatim: an unchecked redirect target is an open redirect.
 * {@see self::safePath()} is the one gate every `return_to` passes through;
 * nothing else in this class writes a `Location` header directly.
 */
final class CartController
{
    /** Flashed when `/cart/quantity/` receives a `quantity` that is present but not numeric, rather than silently treating it as zero. */
    public const string INVALID_QUANTITY = 'Enter a valid quantity.';

    public function __construct(
        private readonly CartRules $rules,
        private readonly CartStore $carts,
        private readonly CartMirror $mirror,
        private readonly FunnelRouter $router,
        private readonly FlowDefinition $flow,
        private readonly JourneyStore $journeys,
    ) {
    }

    /**
     * `[7.1]`–`[7.7]`, `[7.16]`, `[8.1]`. An accepted add is saved and
     * mirrored, then sent to the routing decision — the next step the cart's
     * contents actually call for — rather than back to wherever the form was
     * posted from, because the whole point of adding to the cart is to move
     * the visitor forward through the funnel. A rejected add changes nothing
     * and returns to `return_to` instead: there is no "next step" for a
     * mutation that never happened.
     *
     * The journey goes to the router along with the cart, and must: the
     * routing decision is completion-aware, so asking it what comes next
     * without the journey answers with a questionnaire this visitor may
     * already have finished — adding an accessory would march a buyer back
     * through the medical intake they completed a moment ago — and loses the
     * disqualification branch, which is the only thing that routes a
     * terminated journey to the page explaining why (`[10.46]`).
     *
     * A product page's "Proceed to Checkout" button posts `intent=checkout`
     * to skip straight to the checkout step rather than wherever the funnel
     * would otherwise send this cart next — safe to do unconditionally
     * because `/checkout/`'s own `requires` in `config/funnel.php` still
     * gates it, so a visitor with an outstanding questionnaire is bounced
     * back to it exactly as `StepGuardMiddleware` would from a deep link.
     * Any other (or missing) `intent`, including "Start Assessment", keeps
     * the funnel-optimal routing decision above.
     */
    public function add(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $cart = $this->carts->cart();

        $outcome = $this->rules->add(
            $cart,
            self::requiredString($body['slug'] ?? null),
            self::optionalString($body['variant_id'] ?? null),
            max(1, (int) ($body['quantity'] ?? 1)),
        );

        if ($outcome->notice !== null) {
            $this->carts->flash($outcome->notice);
        }

        if (!$outcome->accepted) {
            return self::redirect($response, self::safePath($body['return_to'] ?? null));
        }

        $state = $this->journeys->state();

        $this->carts->save($cart);
        $this->mirror->mirror($request->getAttribute('session_uuid'), $cart, $state);

        $step = ($body['intent'] ?? null) === 'checkout' ? 'checkout' : $this->router->nextStep($cart, $state);

        return self::redirect($response, $this->flow->pathFor($step));
    }

    /**
     * `[7.8]`, `[7.9]`. Unlike {@see self::add()} there is no routing
     * decision to defer to here — removing a line does not advance the
     * funnel — so both outcomes redirect to `return_to`, the page the removal
     * was posted from.
     */
    public function remove(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $cart = $this->carts->cart();

        $outcome = $this->rules->remove($cart, self::requiredString($body['slug'] ?? null));

        if ($outcome->notice !== null) {
            $this->carts->flash($outcome->notice);
        }

        if ($outcome->accepted) {
            $this->carts->save($cart);
            $this->mirror->mirror($request->getAttribute('session_uuid'), $cart, $this->journeys->state());
        }

        return self::redirect($response, self::safePath($body['return_to'] ?? null));
    }

    /**
     * `[7.16]`. Same shape as {@see self::remove()}: a quantity change never
     * advances the funnel on its own, so both outcomes redirect to
     * `return_to`. A quantity of zero or less is `CartRules::setQuantity()`'s
     * own business — it routes to the same refusal a bundled child's removal
     * would get, or to an outright removal for anything else — so once the
     * posted value is known to be numeric, this method passes it through
     * rather than special-casing it here.
     *
     * A `quantity` that is missing or not numeric at all is a different
     * thing from a genuine zero, and is refused before it ever reaches
     * `CartRules`: `(int) "abc"` is `0`, and a `0` is "remove this line" as
     * far as `setQuantity()` is concerned, so letting a garbled value fall
     * through would let a broken request delete a line the buyer never
     * touched.
     */
    public function quantity(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $cart = $this->carts->cart();

        $rawQuantity = $body['quantity'] ?? null;
        if (!is_numeric($rawQuantity)) {
            $this->carts->flash(self::INVALID_QUANTITY);

            return self::redirect($response, self::safePath($body['return_to'] ?? null));
        }

        $outcome = $this->rules->setQuantity(
            $cart,
            self::requiredString($body['slug'] ?? null),
            (int) $rawQuantity,
        );

        if ($outcome->notice !== null) {
            $this->carts->flash($outcome->notice);
        }

        if ($outcome->accepted) {
            $this->carts->save($cart);
            $this->mirror->mirror($request->getAttribute('session_uuid'), $cart, $this->journeys->state());
        }

        return self::redirect($response, self::safePath($body['return_to'] ?? null));
    }

    /**
     * '' when $value is anything other than a non-empty string — a missing
     * field and an array-valued one (`slug[]=x`, which a hand-crafted or
     * malformed request can send) are both "no slug supplied" rather than
     * being coerced with `(string)`, which would turn an array into the
     * literal string `"Array"` plus a PHP warning.
     */
    private static function requiredString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @return non-empty-string|null null when $value is missing, not a string, or blank */
    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }

    /**
     * `return_to` is ordinary request data a visitor's browser sent, not a
     * value this storefront generated, so an unchecked `Location` built from
     * it is an open redirect — `https://evil.example/`,
     * `//evil.example/` (a schemeless redirect to another host), and
     * `javascript:` all reach a browser's address bar exactly as a same-site
     * path would.
     *
     * The gate is deliberately narrow rather than a denylist of known-bad
     * schemes: a value is trusted only once it begins with exactly one `/`
     * (so `//host/...` is refused even though it also "begins with `/`"),
     * carries no backslash (a browser normalises `/\host` toward `//host`
     * exactly as it would a doubled forward slash), no control character
     * (which rules out a header-splitting attempt riding along in the same
     * field), and no `:` before its first `/` (redundant with the leading-
     * slash check above for every value reaching this line today, since
     * nothing that fails to start with `/` gets this far — kept anyway as
     * the same kind of defence-in-depth the self-redirect check in
     * {@see \AsterMD\Storefront\Http\Middleware\StepGuardMiddleware} is,
     * against a future relaxation of the leading-slash rule reintroducing
     * exactly the hazard it currently closes). Anything that fails any of
     * these checks falls back to `/` rather than being echoed back partially
     * sanitised.
     *
     * The path portion of a value that passes is still run through
     * {@see Url::canonicalizePath()} — the same normalisation every other
     * internal link goes through — rather than being trusted verbatim. Only
     * the path portion: `canonicalizePath()` lowercases and trailing-slashes
     * the *entire* string, which is correct for a bare path (every other
     * caller only ever gives it one) but would mangle a query string riding
     * along with `return_to` — folding its case and appending a stray
     * trailing slash after it. Same-origin either way, so not a redirect
     * hazard, but a wrong URL, so the value is split at its first `?` and
     * only the path half is canonicalised; the query half is reattached
     * verbatim. The character checks above run against the *whole* raw
     * value before this split, so a control character or a backslash hidden
     * inside the query is refused exactly as one in the path would be.
     */
    private static function safePath(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '/';
        }

        if ($value[0] !== '/' || str_starts_with($value, '//')) {
            return '/';
        }

        if (str_contains($value, '\\')) {
            return '/';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '/';
        }

        $firstSlash = strpos($value, '/');
        if ($firstSlash !== false && str_contains(substr($value, 0, $firstSlash), ':')) {
            return '/';
        }

        $queryAt = strpos($value, '?');
        if ($queryAt === false) {
            return Url::canonicalizePath($value);
        }

        return Url::canonicalizePath(substr($value, 0, $queryAt)) . '?' . substr($value, $queryAt + 1);
    }
}

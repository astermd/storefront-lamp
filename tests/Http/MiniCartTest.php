<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The mini-cart drawer (rendered by every marketing page via
 * `partials/header.twig`), the product-page add-to-cart form, and the cart
 * notice — all driven by the `cart` Twig global
 * {@see \AsterMD\Storefront\Http\Middleware\TemplateGlobalsMiddleware}
 * publishes.
 *
 * The notice cases assert against rendered HTML on each of the three layouts,
 * because where it renders is the whole of its correctness: the view-model can
 * carry a perfectly good notice and still show the buyer nothing.
 */
final class MiniCartTest extends TestCase
{
    use TempDatabase;

    /** The one shipped product that declares an intake questionnaire, and the form it names. */
    private const string SEMAGLUTIDE_FORM = '6a8aec0dec745c70f5f3e69a';

    /** The journey the drawer's completion-aware cases are read as. */
    private const string JOURNEY_SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function seedCart(array $lines): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private function rxLine(string $slug = 'tadalafil', int $quantity = 1, int $unitPriceCents = 4900, ?string $variantId = 'tadalafil-1m'): array
    {
        return [
            'slug' => $slug, 'name' => 'Tadalafil', 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => $quantity, 'unit_price_cents' => $unitPriceCents, 'variant_id' => $variantId,
        ];
    }

    /** @return array<string, mixed> */
    private function accessoryLine(string $slug = 'travel-case', int $quantity = 1, int $unitPriceCents = 1999, string $kind = 'otc'): array
    {
        return [
            'slug' => $slug, 'name' => 'Travel Case', 'kind' => $kind,
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => $quantity, 'unit_price_cents' => $unitPriceCents, 'variant_id' => null,
        ];
    }

    /**
     * A throwaway migrated SQLite file is substituted for the configured
     * database, the same seam every other full-app suite uses: none of these
     * paths should reach PDO, and binding a temp one is how that stays a
     * property of the code rather than of whichever database the developer
     * running the suite happens to have configured.
     */
    private function get(string $path = '/'): string
    {
        $app = AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            CatalogProvider::class => SampleCatalog::provider(),
        ]);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));

        return (string) $response->getBody();
    }

    /**
     * The same page, read as a visitor whose journey the storefront can
     * actually see.
     *
     * Form completion is a durable fact in the sessions table rather than
     * anything the request carries, so the row is written before the container
     * is built and the request presents the `amd_session` cookie that adopts
     * it. Without the session gateway there is no journey at all and every
     * assertion about completion is vacuously true.
     *
     * @param array<string, string> $formStatus teleform id => status
     */
    private function getAsJourney(string $path, array $formStatus): string
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $pdo))
            ->insert(self::JOURNEY_SESSION, ['reconciled' => true, 'form_status' => $formStatus], null);

        $app = AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $pdo,
            CatalogProvider::class => SampleCatalog::provider(),
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::JOURNEY_SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
                ->withCookieParams(['amd_session' => self::JOURNEY_SESSION]),
        );

        return (string) $response->getBody();
    }

    /**
     * The cart-panel's own markup, isolated from the rest of the page —
     * the footer's "Treatments" column links every catalog product
     * (including the real "Ramelteon") on every marketing page regardless
     * of what is in the cart, so an absence assertion about the mockup's
     * hardcoded sample names has to be scoped to the drawer itself rather
     * than the whole body.
     */
    private function cartPanelHtml(string $body): string
    {
        $start = strpos($body, 'id="cart-panel"');
        $end = strpos($body, '<a href="#sign-in"', $start);

        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($body, $start, $end - $start);
    }

    public function testAnEmptyCartRendersTheEmptyStateAndNoBadge(): void
    {
        $body = $this->get('/');

        self::assertStringContainsString('Your cart is empty.', $body);
        self::assertStringNotContainsString('rounded-xs bg-primary px-[3px] text-[10px] font-bold leading-none text-white', $body);
        self::assertStringContainsString('aria-label="Open cart, 0 items"', $body);
    }

    public function testASingleLineCartUsesTheSingularAriaLabel(): void
    {
        $this->seedCart([$this->rxLine()]);
        $body = $this->get('/');

        self::assertStringContainsString('aria-label="Open cart, 1 item"', $body);
        self::assertStringNotContainsString('aria-label="Open cart, 1 items"', $body);
    }

    public function testTheDrawerRendersOneCardPerLineFromTheCart(): void
    {
        $this->seedCart([$this->rxLine(), $this->accessoryLine()]);
        $body = $this->get('/');

        $panel = $this->cartPanelHtml($body);

        self::assertStringContainsString('Tadalafil', $panel);
        self::assertStringContainsString('Travel Case', $panel);
        self::assertStringNotContainsString('Ramelteon', $panel);
        self::assertStringNotContainsString('Super Duper Cool Pill Organizer', $panel);
        self::assertStringContainsString('aria-label="Open cart, 2 items"', $body);
    }

    public function testAnRxLineWithAQuestionnaireNotYetStartedOffersTheWelcomePage(): void
    {
        // The drawer asks the routing decision rather than the line's kind:
        // "rx" says what was bought, not what is left to do. Semaglutide is
        // the catalog's one product that declares a questionnaire.
        //
        // A visitor who has answered nothing is sent to the welcome page, not
        // into the middle of the questionnaire — and the drawer is the only
        // surface that may say so, because a link is followed once by choice
        // where a routing decision would be applied on every request.
        $this->seedCart([$this->rxLine('semaglutide', variantId: 'semaglutide-1m')]);
        $body = $this->get('/');

        self::assertStringContainsString('Start Assessment', $body);
        self::assertStringContainsString('href="/intake/"', $body);
    }

    public function testAnRxLineWithNoQuestionnaireOffersCheckoutRatherThanAnAssessmentThatDoesNotExist(): void
    {
        // Tadalafil declares no teleform, so there is nothing to assess. The
        // drawer used to offer "Continue Assessment" for every Rx line and
        // send this buyer to a questionnaire the funnel never asks them for.
        $this->seedCart([$this->rxLine()]);
        $body = $this->get('/');

        self::assertStringContainsString('Continue Checkout', $body);
        self::assertStringNotContainsString('Continue Assessment', $body);
    }

    /**
     * The half of the drawer's continue action that only a real journey can
     * exercise: a questionnaire that has already been answered.
     *
     * The two cases above distinguish "asks the routing decision" from "reads
     * the line's kind", and both hold with no journey at all — so on their own
     * they leave the *journey* argument unasserted, and dropping it (asking the
     * router with a null state) changes nothing they can see. That argument is
     * the whole of the fix: a buyer who finished their assessment was still
     * being offered "Continue Assessment" back to the form they had completed.
     */
    public function testAFinishedQuestionnaireTurnsTheDrawerActionIntoCheckout(): void
    {
        $this->seedCart([$this->rxLine('semaglutide', variantId: 'semaglutide-1m')]);
        $body = $this->getAsJourney('/', [self::SEMAGLUTIDE_FORM => 'completed']);

        self::assertStringContainsString('Continue Checkout', $body);
        self::assertStringContainsString('href="/checkout/"', $body);
        self::assertStringNotContainsString('Continue Assessment', $body);
    }

    /**
     * And the other direction, so the case above cannot be satisfied by a
     * drawer that has simply stopped offering the assessment to anybody: a
     * journey that has *started* the questionnaire and not finished it is sent
     * back into it, at the step rather than at the welcome page.
     *
     * This is the pair that distinguishes the three answers the drawer can
     * give about one unfinished cart — welcome page, questionnaire, checkout —
     * and it is the only one that reads a journey which is neither empty nor
     * complete, so it is what stops the not-yet-started branch swallowing the
     * in-progress case.
     */
    public function testAStartedButUnfinishedQuestionnaireOffersTheStepRatherThanTheWelcomePage(): void
    {
        $this->seedCart([$this->rxLine('semaglutide', variantId: 'semaglutide-1m')]);
        $body = $this->getAsJourney('/', [self::SEMAGLUTIDE_FORM => 'in_progress']);

        self::assertStringContainsString('Continue Assessment', $body);
        self::assertStringContainsString('href="/intake/medical/"', $body);
        self::assertStringNotContainsString('Start Assessment', $body);
    }

    /**
     * The same not-yet-started answer on a journey the storefront can actually
     * see, rather than on the null journey the case above reads. Both paths
     * reach the branch and a fix that only covered one of them would leave the
     * welcome page orphaned for every real visitor.
     */
    public function testAJourneyThatHasAnsweredNothingIsAlsoOfferedTheWelcomePage(): void
    {
        $this->seedCart([$this->rxLine('semaglutide', variantId: 'semaglutide-1m')]);
        $body = $this->getAsJourney('/', []);

        self::assertStringContainsString('Start Assessment', $body);
        self::assertStringContainsString('href="/intake/"', $body);
    }

    public function testAnRxLineNeverOffersAQuantityStepper(): void
    {
        $this->seedCart([$this->rxLine()]);
        $body = $this->get('/');

        self::assertStringNotContainsString('cart-qty-increment', $body);
        self::assertStringNotContainsString('cart-qty-decrement', $body);
    }

    public function testAnAccessoryLineOffersAStepperAndContinueCheckout(): void
    {
        $this->seedCart([$this->accessoryLine()]);
        $body = $this->get('/');

        self::assertStringContainsString('cart-qty-increment', $body);
        self::assertStringContainsString('cart-qty-decrement', $body);
        self::assertStringContainsString('Continue Checkout', $body);
        self::assertStringContainsString('href="/checkout/"', $body);
    }

    /**
     * At a quantity of one the decrement button removes the line, so a label
     * promising a decrease describes something that does not happen. Its
     * wording still has to differ from the card's own remove button, or the
     * two controls become indistinguishable to a screen reader. Above one it
     * genuinely decrements and says so.
     */
    public function testTheDecrementButtonsLabelTellsTheTruthAtEachQuantity(): void
    {
        $this->seedCart([$this->accessoryLine(quantity: 1)]);
        $atOne = $this->get('/');

        self::assertStringContainsString('aria-label="Remove last Travel Case from cart"', $atOne);
        self::assertStringNotContainsString('aria-label="Decrease Travel Case quantity"', $atOne);
        self::assertStringContainsString('aria-label="Remove Travel Case from cart"', $atOne);

        $this->seedCart([$this->accessoryLine(quantity: 2)]);
        $atTwo = $this->get('/');

        self::assertStringContainsString('aria-label="Decrease Travel Case quantity"', $atTwo);
        self::assertStringNotContainsString('aria-label="Remove last Travel Case from cart"', $atTwo);
        self::assertStringContainsString('name="quantity" value="1"', $atTwo);
    }

    /**
     * A slug the cart still remembers but the catalog no longer carries
     * resolves `image` to null (`TemplateGlobalsMiddleware::variantName()`'s
     * sibling logic for `image`) — the tile must fall back to the icon
     * placeholder rather than rendering an `<img src="">`, which some
     * browsers treat as a request to reload the current document. Covers
     * all three tile-rendering branches (`rx`, a bundled child, and a
     * non-child non-Rx line) so a regression in any one branch's
     * `{% if line.image %}` doesn't go unnoticed by only ever exercising
     * the branch that already had a real image.
     */
    public function testALineWithNoCatalogImageFallsBackToTheIconTileInsteadOfAnEmptySrc(): void
    {
        $this->seedCart([
            [
                'slug' => 'discontinued-rx', 'name' => 'Discontinued Rx', 'kind' => 'rx',
                'emr_product_id' => null, 'parent_slug' => null,
                'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => null,
            ],
            $this->accessoryLine(slug: 'imageless-accessory'),
            [
                'slug' => 'imageless-child', 'name' => 'Imageless Child', 'kind' => 'free-addon',
                'emr_product_id' => null, 'parent_slug' => 'discontinued-rx',
                'quantity' => 1, 'unit_price_cents' => 0, 'variant_id' => null,
            ],
        ]);
        $body = $this->get('/');

        self::assertStringContainsString('Discontinued Rx', $body);
        self::assertStringContainsString('Travel Case', $body);
        self::assertStringContainsString('Imageless Child', $body);
        self::assertStringNotContainsString('src=""', $body);
        self::assertSame(3, substr_count($body, 'data-lucide="pill"'));
    }

    public function testABundledChildHasNeitherARemoveButtonNorAStepper(): void
    {
        $this->seedCart([
            $this->rxLine(),
            [
                'slug' => 'syringes', 'name' => 'Syringe Kit', 'kind' => 'free-addon',
                'emr_product_id' => null, 'parent_slug' => 'tadalafil',
                'quantity' => 1, 'unit_price_cents' => 0, 'variant_id' => null,
            ],
        ]);
        $body = $this->get('/');

        self::assertStringContainsString('Syringe Kit', $body);
        self::assertSame(1, substr_count($body, 'action="/cart/remove/"'));
        self::assertStringNotContainsString('cart-qty-increment', $body);
        self::assertStringNotContainsString('cart-qty-decrement', $body);
    }

    public function testEveryMutationControlCarriesTheCsrfToken(): void
    {
        $this->seedCart([
            $this->rxLine(),
            $this->accessoryLine(),
            [
                'slug' => 'syringes', 'name' => 'Syringe Kit', 'kind' => 'free-addon',
                'emr_product_id' => null, 'parent_slug' => 'travel-case',
                'quantity' => 1, 'unit_price_cents' => 0, 'variant_id' => null,
            ],
        ]);
        $body = $this->get('/');

        $forms = substr_count($body, '<form method="post"');
        $csrfFields = substr_count($body, 'name="_csrf"');

        self::assertGreaterThan(0, $forms);
        self::assertSame($forms, $csrfFields);
    }

    public function testPricesRenderAsFormattedMoney(): void
    {
        $this->seedCart([$this->rxLine(unitPriceCents: 13800)]);
        $body = $this->get('/');

        self::assertStringContainsString('$138.00', $body);
    }

    public function testAFreeAddonLineRendersAsZero(): void
    {
        $this->seedCart([$this->accessoryLine(unitPriceCents: 0, kind: 'free-addon')]);
        $body = $this->get('/');

        self::assertStringContainsString('$0.00', $body);
    }

    public function testTheProductPageCtasPostToTheCart(): void
    {
        $body = $this->get('/products/tadalafil/');

        self::assertStringContainsString('action="/cart/add/"', $body);
        self::assertStringContainsString('name="slug" value="tadalafil"', $body);
        self::assertStringContainsString('name="variant_id" id="product-variant-id" value="tadalafil-1m"', $body);
        self::assertStringContainsString('Start Assessment', $body);
        self::assertStringContainsString('Proceed to Checkout', $body);
        self::assertSame(2, substr_count($body, '<button type="submit"'));
    }

    public function testTheProductPageStillCarriesTheMockupClassAttributes(): void
    {
        $body = $this->get('/products/tadalafil/');

        self::assertStringContainsString(
            'class="inline-flex w-full items-center justify-center gap-2 rounded-sm bg-primary h-[41px] px-5 text-base font-medium sm:h-auto sm:py-3 text-primary-foreground transition-colors hover:bg-primary-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-hover"',
            $body,
        );
        self::assertStringContainsString(
            'class="inline-flex w-full items-center justify-center gap-2 rounded-sm border border-border bg-background h-[41px] px-5 text-base font-medium sm:h-auto sm:py-3 text-heading transition-colors hover:border-primary hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"',
            $body,
        );
    }

    public function testARejectedAddRendersItsNoticeOnTheProductPage(): void
    {
        $_SESSION['cart_notice'] = 'Not available in your state.';
        $body = $this->get('/products/tadalafil/');

        self::assertStringContainsString('Not available in your state.', $body);
        self::assertStringContainsString('role="status"', $body);
    }

    /**
     * Exactly once. The product page used to render the notice inline *and*
     * inside the drawer, which is two of them on the one page a buyer is most
     * likely to be adding from.
     */
    public function testTheNoticeRendersOnceOnAPageThatUsedToRenderItTwice(): void
    {
        $_SESSION['cart_notice'] = 'Not available in your state.';
        $body = $this->get('/products/tadalafil/');

        self::assertSame(1, substr_count($body, 'Not available in your state.'));
        self::assertSame(1, substr_count($body, 'role="status"'));
    }

    /**
     * The notice must be readable with JavaScript off. `#cart-panel` ships
     * with the `hidden` class and is opened by `cart.js`, so a notice rendered
     * inside it told a scriptless buyer nothing at all — and a rejected add
     * from a marketing page is exactly the moment they need to be told.
     */
    public function testTheNoticeOnAMarketingPageIsOutsideTheScriptOpenedDrawer(): void
    {
        $_SESSION['cart_notice'] = 'Not available in your state.';
        $body = $this->get('/treatments/');

        self::assertStringContainsString('Not available in your state.', $body);
        self::assertStringNotContainsString('Not available in your state.', $this->cartPanelHtml($body));
    }

    /**
     * An accepted add redirects to the funnel's next step, whose layout has no
     * drawer and no product page to fall back on. Without a render site here,
     * `CartRules::RX_REPLACED` — the notice that is the entire reason `[8.0h]`
     * permits a silent-looking replacement — was consumed and dropped.
     */
    public function testTheNoticeRendersOnAFunnelPage(): void
    {
        $this->seedCart([$this->rxLine()]);
        $_SESSION['cart_notice'] = 'Ramelteon was replaced with Semaglutide — one prescription per order.';
        $body = $this->get('/intake/');

        self::assertStringContainsString('Ramelteon was replaced with Semaglutide', $body);
        self::assertStringContainsString('role="status"', $body);
    }

    /** The checkout layout is the other place a mutation can land the buyer. */
    public function testTheNoticeRendersOnTheCheckoutPage(): void
    {
        $this->seedCart([$this->accessoryLine()]);
        $_SESSION['cart_notice'] = 'You can order up to 5 of Travel Case.';
        $body = $this->get('/checkout/');

        self::assertStringContainsString('You can order up to 5 of Travel Case.', $body);
        self::assertStringContainsString('role="status"', $body);
    }
}

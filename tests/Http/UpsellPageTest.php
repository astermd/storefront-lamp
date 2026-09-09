<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Upsell\Upsells;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The shipped `pages/upsell.twig`, rendered through the real container.
 *
 * {@see UpsellControllerTest} pins the *contract* between the controller and
 * the page against a stub template; this pins what the page actually does with
 * it. The two are deliberately separate: a stub cannot tell you that the offer
 * page still renders a mockup's hardcoded marketing copy, and the real
 * template cannot tell you which fields the view model carries.
 *
 * What is asserted here is one claim in three parts. Every string and number on
 * the page comes from the offer; both answers are real form posts that a
 * browser with JavaScript switched off can make; and the offer nobody drew —
 * no bullets, no image — still renders a page. That last part is the one a real
 * `config/upsells.php` is most likely to hit, since both fields are optional
 * and the mockup filled in both.
 */
final class UpsellPageTest extends TestCase
{
    use TempDatabase;

    /** The journey the offer is reached as: it has bought something. */
    private const string BUYER_SESSION = 'a3d90c11-52f4-4b7e-8c61-0f7b2d4e9a15';

    /** The configured key seeded into the journey's queue. */
    /** A product this file owns, so no channel's catalog can make these cases fail. */
    private const string OFFER_SLUG = 'wellness-pack';

    private const string OFFER_PRODUCT_NAME = 'Wellness Pack';

    private const string OFFER_KEY = 'wellness-pack';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testEveryStringAndNumberOnThePageComesFromTheOffer(): void
    {
        $response = $this->appWithOffer($this->offer())->handle($this->getAsBuyer('/upsell/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('A word before you go', $body, 'the eyebrow');
        self::assertStringContainsString('Round Out Your Routine', $body, 'the headline');
        self::assertStringContainsString('Configured body copy for the offer.', $body, 'the body');
        self::assertStringContainsString('First configured bullet', $body);
        self::assertStringContainsString('Second configured bullet', $body);
        self::assertStringContainsString('Third configured bullet', $body);
        self::assertStringContainsString('Yes, Add It', $body, 'the accept label');
        self::assertStringContainsString('Not This Time', $body, 'the decline label');

        // The product name is the catalog's, not configuration's, so the page
        // and the order line describe the same thing.
        self::assertStringContainsString('>Wellness Pack</p>', $body);
        self::assertStringContainsString('src="/assets/img/upsell.png" alt="Wellness Pack"', $body);

        // None of the mockup's own hardcoded copy survives.
        self::assertStringNotContainsString('Daily Wellness Vitamin Pack', $body);
        self::assertStringNotContainsString('Immune Support formulated for daily resilience', $body);
        self::assertStringNotContainsString('Enhance Your Wellness Journey', $body);
    }

    public function testThePriceIsTheDecidedFigureAndClaimsNoRecurrence(): void
    {
        $response = $this->appWithOffer($this->offer())->handle($this->getAsBuyer('/upsell/'));
        $body = (string) $response->getBody();

        // 899 cents through the `money` filter, and nothing else: an upsell is
        // one separate order placed once, so there is no "/ mo" to render and
        // nothing in the model that could back one.
        self::assertStringContainsString(
            '<p class="shrink-0 text-base font-medium sm:text-lg text-heading">$8.99</p>',
            $body,
        );
        self::assertStringNotContainsString('$8.99 / mo', $body);
        self::assertStringNotContainsString('Cancel anytime', $body);
    }

    public function testBothAnswersAreRealFormPostsCarryingTheCsrfToken(): void
    {
        $response = $this->appWithOffer($this->offer())->handle($this->getAsBuyer('/upsell/'));
        $body = (string) $response->getBody();

        // `contents` is asserted with the action rather than separately: it is
        // what keeps the two buttons themselves the grid items of the mockup's
        // `grid-cols-2`, so a form that lost it would silently reflow the page.
        self::assertStringContainsString('<form method="post" action="/upsell/decline/" class="contents">', $body);
        self::assertStringContainsString('<form method="post" action="/upsell/accept/" class="contents">', $body);
        self::assertStringContainsString('type="submit" id="offer-decline"', $body);
        self::assertStringContainsString('type="submit" id="offer-accept"', $body);

        $token = $_SESSION['_csrf'] ?? null;
        self::assertIsString($token);
        self::assertSame(
            2,
            substr_count($body, '<input type="hidden" name="_csrf" value="' . $token . '" />'),
            'one token per form, or the middleware refuses the post',
        );
    }

    public function testNoActionOnThePageDependsOnJavaScript(): void
    {
        $response = $this->appWithOffer($this->offer())->handle($this->getAsBuyer('/upsell/'));
        $body = (string) $response->getBody();

        // The mockup's script read each button's destination from here and
        // navigated to it, which meant the one step in the funnel that charges
        // a card did nothing at all with JavaScript off.
        self::assertStringNotContainsString('data-next-url', $body);
        self::assertStringNotContainsString('type="button"', $body);
    }

    public function testDecliningWithoutScriptMovesTheBuyerOn(): void
    {
        // The whole no-JS claim, end to end: render the page, post the decline
        // form carrying exactly the fields the page rendered, and land on the
        // receipt. The token deliberately comes out of the HTML rather than out
        // of the session — a post that reached into the session would pass
        // against a page that rendered no token at all.
        $app = $this->appWithOffer($this->offer());
        $page = (string) $app->handle($this->getAsBuyer('/upsell/'))->getBody();

        $response = $app->handle($this->postAsBuyer('/upsell/decline/', self::postedFields($page, '/upsell/decline/')));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/thank-you/', $response->getHeaderLine('Location'));
    }

    public function testAPostWithNoCsrfTokenIsRefusedAndAnswersNothing(): void
    {
        $app = $this->appWithOffer($this->offer());

        $response = $app->handle($this->postAsBuyer('/upsell/decline/', []));

        self::assertSame(419, $response->getStatusCode());

        // And the offer is still on the table: the refusal happened before
        // anything could advance the queue.
        $again = $app->handle($this->getAsBuyer('/upsell/'));
        self::assertSame(200, $again->getStatusCode());
        self::assertStringContainsString('Round Out Your Routine', (string) $again->getBody());
    }

    public function testAnOfferWithNoBulletsAndNoImageStillRendersAPage(): void
    {
        // Both fields are optional and the mockup filled in both, so this is
        // the shape nobody drew. An empty <ul> would take the gap the mockup
        // drew for three lines of it, and an image column with no image would
        // stand as a coloured panel across 38% of a wide screen.
        $response = $this->appWithOffer($this->bareOffer())->handle($this->getAsBuyer('/upsell/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Round Out Your Routine', $body);
        self::assertStringContainsString('<p class="shrink-0 text-base font-medium sm:text-lg text-heading">$8.99</p>', $body);
        self::assertStringContainsString('action="/upsell/accept/"', $body);
        self::assertStringNotContainsString('mt-4 flex flex-col gap-3', $body, 'no bullet list at all');
        // The image column and nothing else: the footer's compliance badge is
        // an <img> on this page too, so the column's own class is what says it
        // is gone.
        self::assertStringNotContainsString('bg-primary-soft', $body, 'no image column at all');
        self::assertStringNotContainsString('/assets/img/upsell.png', $body);
    }

    // ---------------------------------------------------------------- setup

    /**
     * The application, plus a journey that has bought something and has this
     * offer at its cursor.
     *
     * The queue is journey state as well as configuration: it is built once at
     * checkout from what was bought, so the row goes in before the first
     * request. A throwaway migrated SQLite file rather than the developer's
     * own database, for {@see FunnelPagesTest}'s reason — the page should not
     * reach PDO for anything but the journey, and binding a temp one keeps
     * that a property of the code.
     */
    private function appWithOffer(Upsells $upsells): \Slim\App
    {
        $pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $pdo))->insert(
            self::BUYER_SESSION,
            ['placed_orders' => ['34660'], 'reconciled' => true, 'upsell_queue' => [self::OFFER_KEY]],
            null,
        );

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $pdo,
            Upsells::class => $upsells,
            SessionGateway::class => new FakeSessionGateway(
                sessions: [self::BUYER_SESSION => ['opportunity_id' => null, 'events' => []]],
            ),
        ]);
    }

    /**
     * An offer configured with everything the page can render, and deliberately
     * none of it in the mockup's wording — copy that matched the transcription
     * would pass whether the page read the model or not.
     *
     * It names a real catalog product because an offer whose product cannot be
     * resolved is skipped rather than shown.
     */
    private function offer(): Upsells
    {
        return $this->layer([
            'slug' => self::OFFER_SLUG,
            'offer_after' => ['tirzepatide'],
            'eyebrow' => 'A word before you go',
            'headline' => 'Round Out Your Routine',
            'body' => 'Configured body copy for the offer.',
            'bullets' => ['First configured bullet', 'Second configured bullet', 'Third configured bullet'],
            'image' => '/assets/img/upsell.png',
            'price_cents_override' => 899,
            'accept_label' => 'Yes, Add It',
            'decline_label' => 'Not This Time',
            'footnote' => 'Billed monthly until you cancel.',
        ]);
    }

    /** The same offer with every optional field left out. */
    private function bareOffer(): Upsells
    {
        return $this->layer([
            'slug' => self::OFFER_SLUG,
            'offer_after' => ['tirzepatide'],
            'eyebrow' => 'A word before you go',
            'headline' => 'Round Out Your Routine',
            'body' => 'Configured body copy for the offer.',
            'price_cents_override' => 899,
            'accept_label' => 'Yes, Add It',
            'decline_label' => 'Not This Time',
        ]);
    }

    /**
     * The offer layer, over a catalog this test owns.
     *
     * Deliberately not the deployment's real catalog. What this file asserts is
     * that the page renders whatever offer it is given, which is true of every
     * catalog — so reading the shipped one would make these cases fail the day
     * a channel stops selling the product they happened to name, for a reason
     * that has nothing to do with the page.
     *
     * @param array<string, mixed> $row
     */
    private function layer(array $row): Upsells
    {
        return Upsells::fromConfig(
            ['upsells' => [self::OFFER_KEY => $row]],
            new FakeCatalog([self::OFFER_SLUG => [
                'slug' => self::OFFER_SLUG,
                'name' => self::OFFER_PRODUCT_NAME,
                'kind' => 'otc',
                'price_cents' => 2400,
                'variants' => [[
                    'id' => self::OFFER_SLUG . '-1m',
                    'name' => '1 Month',
                    'price_cents' => 2400,
                    'provider' => ['offer_id' => '412', 'product_id' => '3414'],
                ]],
            ]]),
            new OperatorLog(sys_get_temp_dir() . '/upsell-page-test.log'),
        );
    }

    public function testTheFootnoteIsTheDeploymentsOwnWordingAndNotTheMockups(): void
    {
        // The small print under the two buttons is a billing claim, and which
        // claim is true depends on the provider offer behind the product rather
        // than on anything this application can see. So the page prints what it
        // was configured with and never a default: the mockup's "Cancel anytime"
        // promises a cancellation surface that does not exist here, and the
        // obvious correction to "one-time charge" is false against an offer that
        // reports itself as recurring.
        $body = (string) $this->appWithOffer($this->offer())->handle($this->getAsBuyer('/upsell/'))->getBody();

        self::assertStringContainsString('Billed monthly until you cancel.', $body);
        self::assertStringNotContainsString('Cancel anytime', $body);
        self::assertStringNotContainsString('One-time charge', $body);
    }

    public function testAnOfferWithNoFootnoteMakesNoBillingClaimAtAll(): void
    {
        // Silence is the default, and it has to be real silence: an empty
        // paragraph element would be a styled gap where a disclosure belongs,
        // and a reader would not be able to tell it from one that failed to
        // render.
        $body = (string) $this->appWithOffer($this->bareOffer())->handle($this->getAsBuyer('/upsell/'))->getBody();

        self::assertStringNotContainsString('Cancel anytime', $body);
        self::assertStringNotContainsString('One-time charge', $body);
        self::assertStringNotContainsString('Billed monthly', $body);
        self::assertDoesNotMatchRegularExpression(
            '#<p class="mt-4 text-center text-sm text-muted">\s*</p>#',
            $body,
            'no empty footnote element is left behind',
        );
    }

    /**     * Everything a browser would serialise from one of the page's two forms.
     *
     * @return array<string, string>
     */
    private static function postedFields(string $body, string $action): array
    {
        $matched = preg_match(
            '#<form method="post" action="' . preg_quote($action, '#') . '"[^>]*>(.*?)</form>#s',
            $body,
            $form,
        );
        self::assertSame(1, $matched, 'the page renders no form posting to ' . $action);

        preg_match_all('#<input type="hidden" name="([^"]+)" value="([^"]*)"#', $form[1], $inputs, PREG_SET_ORDER);

        $fields = [];
        foreach ($inputs as $input) {
            $fields[$input[1]] = $input[2];
        }

        return $fields;
    }

    private function getAsBuyer(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withCookieParams(['amd_session' => self::BUYER_SESSION]);
    }

    /** @param array<string, string> $body */
    private function postAsBuyer(string $path, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withCookieParams(['amd_session' => self::BUYER_SESSION])
            ->withParsedBody($body);
    }
}

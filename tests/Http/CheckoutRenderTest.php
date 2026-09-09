<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Http\TwigExtensions;
use AsterMD\Storefront\Support\AssetManifest;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * The checkout page's markup, rendered through the real template the way
 * {@see IntakeRenderTest} renders the intake partials.
 *
 * The controller tests next door prove the right errors come back; this one
 * proves a visitor who cannot see them is told which control each belongs to,
 * which is a different claim and not one a status code can carry. The consent
 * checkboxes are what it watches: every other control on the page has carried
 * `aria-invalid` and `aria-describedby` since it was written, and the consents
 * — the one group whose error stops the order — carried neither.
 */
final class CheckoutRenderTest extends TestCase
{
    /**
     * The same Twig construction {@see \AsterMD\Storefront\Bootstrap\AppFactory}
     * uses, extension included: this page calls `asset()` and `url()`, so the
     * partials cannot render without it.
     */
    private static function twig(): Twig
    {
        $root = dirname(__DIR__, 2);
        $twig = Twig::create($root . '/theme/templates', ['cache' => false]);
        $twig->addExtension(new TwigExtensions(new AssetManifest($root . '/public/assets/build/manifest.json')));

        return $twig;
    }

    /**
     * The page with a cart's worth of context and nothing else — enough for
     * the consent block, which is all this file is about.
     *
     * @param list<array{key: string, html: string}> $consents
     * @param array<string, string>                  $errors
     */
    private static function render(array $consents, array $errors = []): string
    {
        return self::twig()->fetch('pages/checkout.twig', [
            'checkout' => [
                'consents' => $consents,
                'errors' => $errors,
                'itemCount' => 1,
                'totals' => ['subtotalCents' => 9900, 'discountCents' => 0, 'totalCents' => 9900],
            ],
        ]);
    }

    public function testAConsentInErrorPointsAtTheElementCarryingItsMessage(): void
    {
        // `[26.5]` stops the order on an ungranted consent, and the message is
        // rendered beside the box. Without the association it is rendered
        // *nowhere* a screen reader will connect to the control it is about.
        $html = self::render(
            [['key' => 'terms', 'html' => 'I agree to the terms']],
            ['terms' => 'Please accept this to continue.'],
        );

        self::assertMatchesRegularExpression(
            '/<input type="checkbox"[^>]*data-consent="terms"[^>]*aria-invalid="true"[^>]*aria-describedby="checkout-error-terms"/',
            $html,
        );
        self::assertStringContainsString('id="checkout-error-terms"', $html);
    }

    public function testAConsentWithNoErrorCarriesNeitherAttribute(): void
    {
        // The absence that matters: `aria-describedby` naming an id that is not
        // on the page is a dangling reference, and a permanent `aria-invalid`
        // tells every visitor the box is wrong before they have touched it.
        $html = self::render([['key' => 'terms', 'html' => 'I agree to the terms']]);

        self::assertStringNotContainsString('aria-describedby="checkout-error-terms"', $html);
        self::assertStringNotContainsString('id="checkout-error-terms"', $html);
        self::assertStringContainsString('data-consent="terms"', $html);
    }

    public function testOnlyTheConsentInErrorIsMarkedInvalid(): void
    {
        // Two boxes, one message. Marking both would send a visitor to fix a
        // control that is already correct.
        $html = self::render(
            [
                ['key' => 'terms', 'html' => 'I agree to the terms'],
                ['key' => 'telehealth', 'html' => 'I consent to telehealth care'],
            ],
            ['telehealth' => 'Please accept this to continue.'],
        );

        self::assertMatchesRegularExpression(
            '/data-consent="telehealth"[^>]*aria-describedby="checkout-error-telehealth"/',
            $html,
        );
        self::assertDoesNotMatchRegularExpression('/data-consent="terms"[^>]*aria-invalid/', $html);
    }

    public function testEveryOtherFieldInErrorStillPointsAtItsOwnMessage(): void
    {
        // The pattern the consents were made to match, pinned here so a future
        // edit cannot quietly take it off the controls that already had it.
        $html = self::render([], ['postal_code' => 'Enter a valid ZIP code.']);

        self::assertMatchesRegularExpression(
            '/id="checkout-postal"[^>]*aria-invalid="true"[^>]*aria-describedby="checkout-error-postal_code"/',
            $html,
        );
    }
}

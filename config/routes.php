<?php

/** Route table. URL => handler. Handlers live in core; templates in theme/. */

use AsterMD\Storefront\Http\Controller\CartController;
use AsterMD\Storefront\Http\Controller\CheckoutController;
use AsterMD\Storefront\Http\Controller\HealthController;
use AsterMD\Storefront\Http\Controller\HomeController;
use AsterMD\Storefront\Http\Controller\IntakeController;
use AsterMD\Storefront\Http\Controller\NotEligibleController;
use AsterMD\Storefront\Http\Controller\PageController;
use AsterMD\Storefront\Http\Controller\VerifyController;
use AsterMD\Storefront\Http\Controller\ProductDetailController;
use AsterMD\Storefront\Http\Controller\ProductListController;
use AsterMD\Storefront\Http\Controller\ReceiptController;
use AsterMD\Storefront\Http\Controller\RobotsController;
use AsterMD\Storefront\Http\Controller\SitemapController;
use AsterMD\Storefront\Http\Controller\UpsellController;
use Slim\App;

return function (App $app): void {
    $app->get('/', HomeController::class)->setName('home');
    $app->get('/health/', HealthController::class)->setName('health');

    // Both are file-like paths, so `CanonicalUrlMiddleware` leaves them alone
    // — a `.`-bearing basename is exempt from the trailing-slash rule, which
    // is the exemption that exists for exactly these two documents. They
    // are generated from configuration and the catalog rather than served as
    // static files (`[24.4]`), so a re-sync cannot leave them stale.
    $app->get('/sitemap.xml', SitemapController::class)->setName('sitemap');
    $app->get('/robots.txt', RobotsController::class)->setName('robots');
    $app->get('/treatments/', ProductListController::class)->setName('treatments');
    $app->get('/products/{slug}/', ProductDetailController::class)->setName('product');

    // The array-callable form ([CartController::class, 'add']) fails at
    // Slim's assertCallable() check: CallableResolver::resolveByPredicate()
    // only container-resolves the string ClassName::method notation, and an
    // array naming a non-static method isn't itself is_callable(). The
    // string form below is resolved through the container instead.
    $app->post('/cart/add/', CartController::class . ':add')->setName('cart.add');
    $app->post('/cart/remove/', CartController::class . ':remove')->setName('cart.remove');
    $app->post('/cart/quantity/', CartController::class . ':quantity')->setName('cart.quantity');

    $app->get('/intake/', PageController::class)
        ->setArgument('template', 'pages/intake/welcome.twig')
        ->setArgument('noindex', '1')
        ->setName('intake.welcome');
    // The two questionnaire steps are definition-driven, so they render
    // through their own controller rather than the static PageController.
    $app->get('/intake/eligibility/', IntakeController::class . ':prequalification')->setName('intake.eligibility');
    $app->get('/intake/medical/', IntakeController::class . ':intake')->setName('intake.medical');
    $app->post('/intake/save/', IntakeController::class . ':save')->setName('intake.save');
    $app->post('/intake/submit/', IntakeController::class . ':submit')->setName('intake.submit');
    $app->post('/intake/capture/', IntakeController::class . ':capture')->setName('intake.capture');
    $app->post('/intake/start-over/', NotEligibleController::class . ':startOver')->setName('intake.start-over');
    // Identity verification (`[22.13]`). A real step now rather than the
    // static page it once was: the GET renders what this
    // deployment's configured checks actually ask for, and the POST runs them
    // and records the verdict on the journey (`[22.20]`). Both halves are
    // needed -- the page must work with JavaScript off, so the answer arrives
    // as a form post rather than as a fetch.
    $app->get('/verify/', VerifyController::class . ':show')->setName('verify');
    $app->post('/verify/', VerifyController::class . ':submit')->setName('verify.submit');
    // Every sub-action is a POST that carries the whole checkout form and
    // redirects back to /checkout/, so the summary can change without the
    // visitor leaving the page or losing what they have typed.
    $app->get('/checkout/', CheckoutController::class . ':show')->setName('checkout');
    $app->post('/checkout/', CheckoutController::class . ':submit')->setName('checkout.submit');
    $app->post('/checkout/bump/', CheckoutController::class . ':bump')->setName('checkout.bump');
    $app->post('/checkout/promo/', CheckoutController::class . ':promoApply')->setName('checkout.promo.apply');
    $app->post('/checkout/promo/remove/', CheckoutController::class . ':promoRemove')->setName('checkout.promo.remove');
    $app->post('/checkout/plan/', CheckoutController::class . ':plan')->setName('checkout.plan');
    // One offer per step, so the page is a GET and each answer is its own POST
    // back to a 303. The two POSTs are sub-actions rather than funnel steps —
    // they name no path in `funnel.php`, so the step guard lets them through
    // exactly as the checkout's own sub-actions are let through, and the offer
    // being charged is decided by the queue rather than by anything the body
    // says.
    $app->get('/upsell/', UpsellController::class . ':show')->setName('upsell');
    $app->post('/upsell/accept/', UpsellController::class . ':accept')->setName('upsell.accept');
    $app->post('/upsell/decline/', UpsellController::class . ':decline')->setName('upsell.decline');
    // Reaching the receipt is what completes the journey, so this cannot be a
    // static page: the controller fires the completion actions exactly once
    // and renders what it captured before tearing the journey down.
    $app->get('/thank-you/', ReceiptController::class)->setName('thank-you');
    $app->get('/not-eligible/', NotEligibleController::class)->setName('not-eligible');

    // The documents the checkout consents link to. They are routed here rather
    // than left to the deployment because a consent control whose document
    // 404s is not consent (`[26.9]`), and `config:validate` refuses to pass
    // when a configured consent links somewhere this application does not
    // serve. The shipped copy is placeholder and says so.
    $app->get('/terms/', PageController::class)
        ->setArgument('template', 'pages/legal/terms.twig')
        ->setName('terms');
    $app->get('/privacy/', PageController::class)
        ->setArgument('template', 'pages/legal/privacy.twig')
        ->setName('privacy');
    $app->get('/telehealth-consent/', PageController::class)
        ->setArgument('template', 'pages/legal/telehealth-consent.twig')
        ->setName('telehealth-consent');
};

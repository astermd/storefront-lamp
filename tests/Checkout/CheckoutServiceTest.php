<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\CheckoutResult;
use AsterMD\Storefront\Checkout\CheckoutService;
use AsterMD\Storefront\Checkout\ConsentRecord;
use AsterMD\Storefront\Checkout\Consents;
use AsterMD\Storefront\Checkout\IdempotencyKey;
use AsterMD\Storefront\Checkout\NullCheckoutEventReporter;
use AsterMD\Storefront\Checkout\NullOrderRecorder;
use AsterMD\Storefront\Checkout\OrderBumps;
use AsterMD\Storefront\Checkout\OrderRecorder;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Checkout\BuyerDetails;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\NullVerificationGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\NullTeleformGateway;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Upsell\Upsells;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Payment\RedeclaredAdapter;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\DeadAfterChargePdo;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\FrozenAttemptReadPdo;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The order of operations inside {@see CheckoutService::submit()}, which is
 * the specification's rather than a convenience, and every branch that must
 * stop before the provider is reached.
 *
 * The service is assembled directly here rather than resolved from the
 * application container, because these cases are about the class and not the
 * pipeline: a collaborator swapped for a stub is visible in the constructor
 * call a line above the assertion. The container's own binding, and the
 * service as the HTTP surface actually reaches it, are covered end to end in
 * {@see \AsterMD\Storefront\Tests\Http\CheckoutControllerTest}.
 * The adapter is the real one driven by {@see FakeVrioTransport} and the
 * recorded fixtures, so a placement here proves the whole payload assembly
 * without a packet leaving the process.
 *
 * The logger is a real {@see \AsterMD\Storefront\Support\OperatorLog} writing
 * to a temp file, which is what makes "no card number reached the log" mean
 * anything: a recording double would never run the redaction pass.
 */
final class CheckoutServiceTest extends TestCase
{
    use TempDatabase;

    private const string SESSION_UUID = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    /** The card every case pays with, so the "it is never written anywhere" assertions have one string to look for. */
    private const string CARD = '4111111100084444';

    /** Distinct from every other digit run in this fixture, so a containment assertion on it cannot pass by luck. */
    private const string SECURITY_CODE = '806';

    /**
     * The upsell layer every case is built with, ordered so a queue assertion
     * can tell configuration order from cart order.
     *
     * `sleep-kit` follows nothing this cart contains and `mask` follows the
     * free attachment, so a queue built from an emptied cart, a queue built
     * from the anchor alone, and a queue built from every line are three
     * distinguishable answers.
     */
    private const array UPSELLS = [
        'wellness-pack' => ['slug' => 'unmapped', 'offer_after' => ['tirzepatide']],
        'sleep-kit' => ['slug' => 'unmapped', 'offer_after' => ['ramelteon']],
        'mask' => ['slug' => 'unmapped', 'offer_after' => ['blocked-thing']],
    ];

    private \PDO $pdo;

    private CartStore $cartStore;

    private JourneyStore $journeys;

    private ?JourneyState $journey = null;

    private FakeVrioTransport $transport;

    private CapturedLog $log;

    private string $cacheDir;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cacheDir = sys_get_temp_dir() . '/checkout-service-' . bin2hex(random_bytes(6));
        $this->log = new CapturedLog();
        $this->transport = new FakeVrioTransport();
        $this->boot(self::SESSION_UUID);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function testASuccessfulPlacementCapturesTotalsBeforeClearingTheCart(): void
    {
        // [13.32] step 1 before step 2: the running totals are captured because
        // the cart is about to be cleared. Clearing first loses the receipt.
        $result = $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame(12000, $result->totals->subtotalCents);
        self::assertTrue($this->cartStore->cart()->isEmpty());
    }

    public function testASuccessfulPlacementDropsThePromotionSoItCannotReachTheUpsellFlow(): void
    {
        // [13.17].
        $service = $this->service(null);
        $this->queueDiscount('12.00');
        $service->applyPromotion('SAVE10');
        $this->transport->queueFixture('vrio-order-approved.json');

        $result = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced());
        self::assertNull($this->journey->promotion);
    }

    public function testADeclineLeavesTheCartCompletelyIntact(): void
    {
        // [13.30]: the cart is left completely intact, the buyer's details are
        // preserved so the form re-fills, and they stay on checkout.
        $result = $this->serviceThatDeclines()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($result->outcome->isPlaced());
        self::assertFalse($this->cartStore->cart()->isEmpty());
        self::assertSame('ada@example.com', $this->journey->buyer()['email']);
        self::assertNull($result->redirectTo, 'they stay on checkout');
    }

    public function testADeclineShowsTheProvidersOwnReasonVerbatim(): void
    {
        // [13.28]: the reason is the provider's, not ours. The recorded
        // decline says "Failed test transaction" and that is what the buyer
        // is shown.
        $result = $this->serviceThatDeclines()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame('Failed test transaction', $result->notice);
    }

    public function testADeclineLeavesNothingChargeableBehind(): void
    {
        // `[15.8]`: a card held after a failed charge is held for nothing, and
        // neither is a handle — a decline is not the event that proves the
        // provider now holds an instrument it will accept, so there is nothing
        // to keep and nothing an upsell could be charged against.
        $this->serviceThatDeclines()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertNull($this->journey->paymentCredential);
        self::assertNull($this->journey->paymentHandle);
        self::assertNull($this->journey->reusableCredential());
    }

    public function testTheUpsellQueueIsBuiltFromWhatWasBoughtBeforeTheCartIsCleared(): void
    {
        // `[16.1]`, `[16.2]`. The cart is the only record of what was bought
        // and `[13.32]` empties it on success, so a queue derived after that is
        // always empty. Configuration order decides the queue, not cart order:
        // `mask` follows the free attachment and still comes second because it
        // is configured second.
        $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($this->cartStore->cart()->isEmpty(), 'the cart really was cleared in the same call');
        self::assertSame(['wellness-pack', 'mask'], $this->journey->upsellQueue);
        self::assertSame(0, $this->journey->upsellCursor);
        self::assertSame([], $this->journey->upsellOutcomes);
    }

    public function testAPurchaseThatEarnsNoOfferGetsAnEmptyQueueRatherThanEveryOffer(): void
    {
        $this->serviceThatPlaces(upsells: [
            'sleep-kit' => ['slug' => 'unmapped', 'offer_after' => ['ramelteon']],
        ])->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame([], $this->journey->upsellQueue);
    }

    public function testTheUpsellQueueSurvivesTheDurableWriteRatherThanOnlyTheRequest(): void
    {
        // The queue is read on a later request, so it has to be in the column
        // and not merely on the object that built it.
        $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $this->journeys->flush();

        $reread = (new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo)))->load(self::SESSION_UUID);

        self::assertSame(['wellness-pack', 'mask'], $reread->upsellQueue);
        self::assertNotNull($reread->reusableCredential(), 'and so does the handle it will be charged against');
    }

    public function testASuccessKeepsTheProvidersHandleAndNotTheCard(): void
    {
        // `[15.12]`, `[15.13]`: what survives a placement is the opaque handle
        // the provider issued, which is not a card and may therefore be kept.
        // The card is not kept at all -- this provider charges a later order
        // against the instrument it already holds, so there is nothing a held
        // card would buy and `[15.10]` prefers not holding one.
        $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        $handle = $this->journey->reusableCredential();

        self::assertNotNull($handle, 'the placement left a handle behind');
        self::assertTrue($handle->isReusable());
        self::assertSame(
            ['customer_id' => '13957', 'customer_card_id' => '16764'],
            $handle->handle,
            'the pair the recorded placement actually returned',
        );
        self::assertNull($this->journey->paymentCredential, 'and no card was kept, whatever the adapter declares');
    }

    public function testTheHandleIsKeptOnWhatTheOutcomeCarriedRatherThanOnWhatTheAdapterDeclares(): void
    {
        // The declared strategy used to decide what was kept, which is the
        // wrong question asked in the wrong place: an adapter that cannot reuse
        // an instrument returns no handle to keep, so the outcome has already
        // answered it. Gating on the declaration instead means a deployment
        // that changes strategy loses its upsells by a branch here rather than
        // by the provider returning nothing, and the two are not the same
        // thing to debug.
        $this->serviceThatPlaces(strategy: AdapterCapabilities::STRATEGY_RAW_CARRY_FORWARD)
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertNotNull($this->journey->reusableCredential());
        self::assertNull($this->journey->paymentCredential, 'and still no card, whatever was declared');
    }

    public function testAPlacementThatLeavesNoHandleStoresNone(): void
    {
        // A provider that vaults nothing, and a journey resumed after the vault
        // entry is gone, both arrive here. Null is an ordinary answer: it costs
        // the buyer an upsell offer and must never be mistaken for one.
        $this->transport->queue(200, self::approvalWithoutTheHandle());

        $result = $this->service(null)->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced(), 'the order still placed');
        self::assertNull($this->journey->reusableCredential());
        self::assertNull($this->journey->paymentHandle);
    }

    public function testNoCardIsEverWrittenToTheDurableJourneyRow(): void
    {
        // The handle that a placement *does* write there is a pair of provider
        // identifiers, useless without the provider and refused by it unless
        // both halves agree. A card in the same column would be a card at rest
        // in the database, which `[15.8]` forbids outright — so this reads the
        // column back rather than the object that wrote it.
        $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $this->journeys->flush();

        $row = (string) $this->pdo->query('SELECT journey_state FROM sessions')->fetchColumn();

        self::assertStringNotContainsString(self::CARD, $row);
        self::assertStringContainsString('16764', $row, 'the handle really is what was written');
    }

    public function testTheAttemptRowIsWrittenWithOneOwnershipTokenAndNotTheClockEachTime(): void
    {
        // The absence a mutation cannot find, because the two readings agree
        // almost always. `markSent()` and `release()` are both conditional on
        // the `created_at` the claim wrote, so a second read of the clock
        // produces the wrong token on exactly the requests that straddle a
        // second boundary — `markSent()` reads that as a takeover and tells the
        // buyer their payment is being confirmed for a charge that never
        // happened, and `release()` silently matches nothing and leaves the row
        // standing. A clock that moves on every call turns the always-case into
        // the never-case.
        //
        // The same invariant is asserted on the upsell path, where this exact
        // bug was found and fixed during the build. The checkout had it right
        // and had nothing holding it there.
        $tick = 0;
        // A full closure with a reference, not an arrow function: an arrow
        // function captures by value, so `$tick++` would increment a copy and
        // the clock would never actually tick.
        $clock = static function () use (&$tick): string {
            return gmdate('c', 1_760_000_000 + $tick++);
        };

        $result = $this->serviceThatPlaces(clock: $clock)->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced(), 'the order is placed, not read as a takeover');
        self::assertSame([], $this->log->eventsNamed('checkout.attempt_taken_over'));
        self::assertSame(1, $tick, 'the clock is read once and the reading is carried');
        self::assertSame([], $this->log->eventsNamed('checkout.attempt_taken_over'));
    }
    public function testNoCardNumberEverReachesTheOperatorLog(): void
    {
        // Asserted against the real logger's own file, because a recording
        // double would never run the redaction pass at all.
        //
        // The security code is asserted through a value that appears nowhere
        // else in this fixture, and that matters more than it looks: a
        // three-digit needle is a substring of half the identifiers a log line
        // carries — the buyer's own phone number among them — so an assertion
        // built on one passes because nothing happened to collide with it
        // rather than because anything was withheld.
        //
        // **What this proves is absence, not scrubbing.** The recorded decline
        // does not echo the card back, so on this path there is nothing for the
        // scrub to find and the assertion would hold even with no scrub at all.
        // The scrub itself is proven where a response *does* carry a number:
        // {@see \AsterMD\Storefront\Tests\Payment\Vrio\VrioAdapterTest} injects
        // one into the gateway's own request echo, and
        // {@see \AsterMD\Storefront\Tests\Support\OperatorLogTest} and
        // {@see \AsterMD\Storefront\Tests\Support\CardScrubberTest} cover the
        // sink and the pass. This case is here for the complement: that the
        // ordinary path writes nothing to look for.
        $this->serviceThatDeclines()->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $log = $this->log->contents();

        self::assertStringNotContainsString(self::CARD, $log);
        self::assertStringNotContainsString(self::SECURITY_CODE, $log, 'nor the security code');
    }

    public function testAnUngrantedBlockingConsentStopsTheOrderBeforeTheProviderIsCalled(): void
    {
        // [26.5].
        $result = $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), []);

        self::assertFalse($result->outcome->isPlaced());
        self::assertArrayHasKey('terms', $result->errors);
        self::assertSame([], $this->transport->requests);
    }

    public function testANonBlockingConsentThatWasDeclinedIsRecordedRatherThanOmitted(): void
    {
        // [26.10]: a record listing only the ticked boxes cannot tell a
        // decline from a question that was never asked.
        $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        $granted = [];
        foreach ($this->journey->consents as $consent) {
            $granted[$consent['key']] = $consent['granted'];
        }

        self::assertTrue($granted['terms']);
        self::assertArrayHasKey('marketing', $granted);
        self::assertFalse($granted['marketing']);
    }

    public function testAnInvalidBuyerStopsTheOrderBeforeTheProviderIsCalled(): void
    {
        // [13.5]: the shape checks are the server's, and a buyer who cannot be
        // delivered to must never reach the payment provider.
        $result = $this->serviceThatPlaces()
            ->submit($this->buyer(postalCode: '1234'), $this->card(), ['terms' => 'on']);

        self::assertArrayHasKey('postal_code', $result->errors);
        self::assertSame([], $this->transport->requests);
    }

    public function testTheGeoGateIsReRunAgainstTheSubmittedTerritory(): void
    {
        // [13.6]: re-run against the freshly submitted territory across every cart
        // line, not against whatever was known earlier. [13.7]: the message names
        // the blocked products and the cart is left intact.
        $result = $this->serviceThatPlaces()->submit($this->buyer(territory: 'NY'), $this->card(), ['terms' => 'on']);

        self::assertStringContainsString('Blocked Thing', (string) $result->notice);
        self::assertFalse($this->cartStore->cart()->isEmpty());
        self::assertSame([], $this->transport->requests);
    }

    public function testAPricedLineTheProviderHasNeverHeardOfStopsCheckoutRatherThanUnderchargingForIt(): void
    {
        // A free attachment with no mapping is dropped from the charge and
        // logged. A priced one cannot be: dropping it would undercharge, so
        // the only honest answer is to stop ([13.19]).
        $cart = new Cart();
        $cart->put(new CartLine('unmapped', 'Unmapped Thing', 'otc', null, null, 1, 4900, null));
        $this->cartStore->save($cart);

        $result = $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($result->outcome->isPlaced());
        self::assertSame(CheckoutService::UNAVAILABLE_LINE, $result->notice);
        self::assertSame([], $this->transport->requests);
    }

    public function testARefusalBeforeTheChargeGivesBackOnlyAKeyItStillOwns(): void
    {
        // The release is conditional on the token this request claimed with,
        // and this is the case that distinguishes the two. An unconditional
        // delete here was the root cause of a double charge: a row that moved
        // on between one request's read and its delete was removed while its
        // owner was mid-charge, and the taker then charged the same card again.
        // A trigger stands in for the taker, because what has to be reproduced
        // is a write landing between the claim and the release, and that gap is
        // inside one call to submit().
        $this->pdo->exec(
            "CREATE TRIGGER steal_the_claim AFTER INSERT ON checkout_attempts
             BEGIN
               UPDATE checkout_attempts
                  SET session_key = 'taker', created_at = '2026-08-24T00:10:00+00:00'
                WHERE id = NEW.id;
             END",
        );

        $cart = new Cart();
        $cart->put(new CartLine('unmapped', 'Unmapped Thing', 'otc', null, null, 1, 4900, null));
        $this->cartStore->save($cart);
        $key = $this->keyForSubmit();

        $result = $this->serviceThatPlaces(clock: static fn (): string => '2026-08-24T00:00:00+00:00')
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame(CheckoutService::UNAVAILABLE_LINE, $result->notice);
        self::assertSame([], $this->transport->requests, 'the provider was never reached');
        self::assertSame(
            'taker',
            $this->attempts()->outcomeFor($key)['session_key'] ?? null,
            "the taker's row was left alone rather than deleted by the request that had lost it",
        );
    }

    public function testADuplicateSubmitReturnsTheFirstOutcomeWithoutCallingTheProviderAgain(): void
    {
        // [13.37]. The provider charges an identical payload twice -- verified
        // against the live sandbox -- so this guard is the only one there is.
        $service = $this->serviceThatPlaces();

        $first = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $second = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame($first->outcome->reference, $second->outcome->reference);
        self::assertCount(1, $this->transport->requests);
    }

    public function testAProviderConfirmedDeclineGivesTheKeyBackSoAnotherCardCanBeTried(): void
    {
        // The key is derived from the cart and the buyer and deliberately
        // excludes the card (`[15.8]`), so a buyer retrying with a different
        // card derives the *same* key. Holding the first decline against it
        // would replay that decline forever and the second card would never
        // reach the provider -- a refused card, or a two-second network blip,
        // would end the sale permanently.
        $service = $this->serviceThatDeclines();
        $this->transport->queueFixture('vrio-order-approved.json');

        $first = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $second = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($first->outcome->isPlaced());
        self::assertTrue($second->outcome->isPlaced(), 'the retry reached the provider');
        self::assertCount(2, $this->transport->requests);
        self::assertNull($this->attempts()->outcomeFor($this->keyForSubmit()), 'nothing was left holding the key');
    }

    public function testATransportFailureNeverGivesTheKeyBackBecauseTheChargeCannotBeRuledOut(): void
    {
        // The other half of the rule above. A provider that never answered may
        // still have charged, so this attempt keeps the key and the duplicate
        // is told the payment is being confirmed -- never charged again, and
        // never told it failed when nobody knows that.
        $service = $this->serviceThatTimesOut();

        $first = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $second = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($first->outcome->isPlaced());
        self::assertSame(CheckoutService::CONFIRMING_NOTICE, $second->notice);
        self::assertCount(1, $this->transport->requests, 'the card was never presented a second time');
    }

    public function testAnAttemptTheProviderHasAlreadyBeenSentIsNeverTakenOverHoweverStaleItIs(): void
    {
        // Reconstructed live: a request that died mid-charge leaves a row that
        // may correspond to a real order, and re-posting an identical payload
        // to a gateway with no idempotency of its own creates and charges a
        // second one. Only a claim that never reached the provider is takeable.
        $this->attempts()->claim($key = $this->keyForSubmit(), (string) session_id(), '2026-08-24T00:00:00+00:00');
        $this->attempts()->markSent($key, '2026-08-24T00:00:00+00:00');

        $result = $this->serviceThatPlaces(clock: static fn (): string => '2026-08-24T01:00:00+00:00')
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame([], $this->transport->requests, 'no second order was created');
        self::assertSame(CheckoutService::CONFIRMING_NOTICE, $result->notice);
        self::assertSame([], $this->log->eventsNamed('checkout.stale_attempt_reclaimed'));
    }

    public function testAStaleClaimThatNeverReachedTheProviderIsStillTakenOver(): void
    {
        // The case the staleness window exists for, and the reason the rule
        // above is about the *state* rather than about the age: a request that
        // died before the provider was contacted demonstrably charged nothing.
        $this->attempts()->claim($this->keyForSubmit(), (string) session_id(), '2026-08-24T00:00:00+00:00');

        $result = $this->serviceThatPlaces(clock: static fn (): string => '2026-08-24T01:00:00+00:00')
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced());
        self::assertNotSame([], $this->log->eventsNamed('checkout.stale_attempt_reclaimed'));
    }

    public function testTheUnresolvedAttemptLineCarriesEnoughToReconcileTheCharge(): void
    {
        // The only log line on the one path where money may have moved with no
        // local record. A sweep reading it has to be able to find the row that
        // was left `sent`, the session it belongs to and the amount at risk --
        // and `session_key` cannot serve, because it holds a browser cookie
        // value that is stored in no table.
        $key = $this->keyForSubmit();

        $this->serviceThatTimesOut()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        $line = $this->log->eventsNamed('checkout.attempt_unresolved')[0] ?? null;

        self::assertNotNull($line, 'the unresolved-charge line is the only record of this case');
        self::assertSame($key, $line['context']['idempotency_key'] ?? null);
        self::assertSame(self::SESSION_UUID, $line['context']['session_uuid'] ?? null);
        self::assertSame(12000, $line['context']['amount_cents'] ?? null);
        self::assertArrayHasKey('reference', $line['context']);
        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $line['context']['attempt_state'] ?? null);
    }

    public function testTheDuplicateOfAnUnresolvedAttemptIsLoggedAgainstTheSameRow(): void
    {
        // A sweep that reaches this line and not the one above is how a charge
        // whose own request never returned gets noticed at all, so it names the
        // same row rather than carrying an empty context.
        $key = $this->keyForSubmit();
        $service = $this->serviceThatTimesOut();

        $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        $line = $this->log->eventsNamed('checkout.duplicate_confirming')[0] ?? null;

        self::assertNotNull($line);
        self::assertSame($key, $line['context']['idempotency_key'] ?? null);
        self::assertSame(self::SESSION_UUID, $line['context']['session_uuid'] ?? null);
        self::assertSame(12000, $line['context']['amount_cents'] ?? null);
        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $line['context']['attempt_state'] ?? null);
    }

    public function testAnAttemptLogLineNeverCarriesTheCardOrTheBuyer(): void
    {
        // The whole reason these lines may carry an identifier at all: the
        // idempotency key is a digest that excludes the card by design, and
        // nothing else added to them is buyer-identifying.
        $this->serviceThatTimesOut()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertStringNotContainsString(self::CARD, $this->log->contents());
        self::assertStringNotContainsString('ada@example.com', $this->log->contents());
    }

    public function testTwoRequestsTakingOverOneStaleClaimPresentTheCardOnce(): void
    {
        // The interleave is the ordinary one: the second request reads the
        // attempt row before the first request's writes land, so its snapshot
        // still says "claimed, and old". While the takeover was a delete
        // followed by a fresh insert, the delete removed the row the unique
        // index would have serialised against and both requests went on to
        // charge -- two provider orders against one cart, one surviving row,
        // and nothing anywhere recording the first charge.
        $key = $this->keyForSubmit();
        $cart = $this->cartStore->cart();

        $this->attempts()->claim($key, 'dead-request', '2026-08-24T00:00:00+00:00');
        $stale = $this->rawAttempt($key);

        $clock = static fn (): string => '2026-08-24T00:10:00+00:00';

        $first = $this->serviceThatPlaces(clock: $clock)->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        // The second request was reading the cart before the first emptied it,
        // which is what "simultaneous" means.
        $this->cartStore->save($cart);

        $behind = FrozenAttemptReadPdo::alongside($this->pdo, $stale);
        $frozen = new CheckoutAttemptRepository(static fn (): \PDO => $behind, 'sqlite');
        $second = $this->serviceThatPlaces(attempts: $frozen, clock: $clock)
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($first->outcome->isPlaced());
        self::assertCount(1, $this->transport->requests, 'the card reached the provider exactly once');
        self::assertFalse($second->outcome->isPlaced(), 'the second submit placed no second order');
    }

    public function testASubmitWhoseClaimWasTakenOverRefusesBeforePresentingTheCard(): void
    {
        // The slow-owner case, which is the other way two requests end up
        // mid-charge against one key: this request's claim was not abandoned,
        // only late, and a taker rewrote it. Marking the attempt sent is the
        // last write before the charge, so discovering it there costs nobody
        // any money -- and the row is emphatically not this request's to give
        // back, because deleting it would strand the taker mid-charge.
        // A trigger stands in for the taker, because what has to be reproduced
        // is a write landing in the gap between the claim and the sent marker,
        // and that gap is inside one call to submit().
        $this->pdo->exec(
            "CREATE TRIGGER steal_the_claim AFTER INSERT ON checkout_attempts
             BEGIN
               UPDATE checkout_attempts
                  SET session_key = 'taker', created_at = '2026-08-24T00:10:00+00:00'
                WHERE id = NEW.id;
             END",
        );

        $key = $this->keyForSubmit();
        $result = $this->serviceThatPlaces(clock: static fn (): string => '2026-08-24T00:00:00+00:00')
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame([], $this->transport->requests, 'the card was never presented');
        self::assertFalse($result->outcome->isPlaced());
        self::assertNotSame([], $this->log->eventsNamed('checkout.attempt_taken_over'));
        self::assertSame(
            'taker',
            $this->attempts()->outcomeFor($key)['session_key'],
            "the taker's claim was left alone rather than released by the request that lost it",
        );
    }

    public function testAChargedOrderIsNeverLostWhenThePostChargeWriteCannotBeMade(): void
    {
        // The provider took the money and the database went away before the
        // attempt could be completed. Everything after the charge has to fail
        // safe: the buyer holds a charged card, so an error page in front of
        // them is the one outcome that leaves nobody with a record of the order.
        $result = $this->serviceThatPlaces(attempts: $this->attemptsThatCannotComplete())
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced(), 'the charge stands');
        self::assertSame('/upsell/', $result->redirectTo, 'and the buyer still reaches their receipt');
        self::assertSame(['34660'], $this->journey->placedOrders, 'the order is recorded as far as it can be');
        self::assertTrue($this->cartStore->cart()->isEmpty());

        $lost = $this->log->eventsNamed('checkout.post_charge_write_failed');
        self::assertCount(1, $lost, 'and the write nobody could make is a reconciliation item, not a silence');
        self::assertSame('error', $lost[0]['level'] ?? null);
    }

    public function testASubmitAfterTheOrderWasPlacedAnswersWithThatOrderRatherThanADecline(): void
    {
        // `[13.32]` clears the cart on success, so the key a second submit
        // derives cannot match the one the first claimed and the duplicate
        // guard cannot see it. Without this branch a buyer who has just paid
        // meets "payment declined", and the funnel guard passes a request
        // through unguarded whenever the journey stores cannot be resolved.
        $service = $this->serviceThatPlaces();
        $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        $again = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($again->outcome->isPlaced());
        self::assertSame('34660', $again->outcome->reference);
        self::assertSame('/upsell/', $again->redirectTo);
        self::assertCount(1, $this->transport->requests);
        self::assertSame(
            ['reference' => '34660'],
            $this->log->eventsNamed('checkout.already_placed')[0]['context'] ?? null,
        );
    }

    public function testTheFunnelVisitEventIsOpenedOncePerJourneyRatherThanOnEveryRender(): void
    {
        // The EMR keeps one checkout record per session and `create()`
        // overwrites its `event` field, so a second create after a decline
        // erases the decline. Every sub-action's 303 -> GET fires one too, so
        // the funnel reported zero declines, ever.
        $reporter = new class implements CheckoutEventReporter {
            public int $visits = 0;

            public function checkoutVisited(?string $sessionUuid, Totals $totals): void
            {
                ++$this->visits;
            }

            public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
            {
            }

            public function orderDeclined(?string $sessionUuid, Totals $totals, string $paymentMethod, ?string $reference, string $reason): void
            {
            }

            /** @param list<string> $orderReferences */
            public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
            {
            }

            public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
            {
            }

            public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
            {
            }

            public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
            {
            }

            public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
            {
            }

            /** @param list<string> $orderReferences */
            public function journeyCompleted(
                ?string $sessionUuid,
                bool $anyOrderPlaced,
                int $orderValueCents,
                int $paidTotalCents,
                ?string $paymentMethod,
                array $orderReferences,
                ?string $opportunityId,
            ): void {
            }
        };

        $service = $this->serviceThatPlaces(events: $reporter);
        $service->view();
        $service->view();
        $service->view($this->buyer(), [], 'declined');

        self::assertSame(1, $reporter->visits);
        self::assertTrue($this->journey->hasEmrEvent('checkout_visited'));
    }

    public function testAPendingActionOutcomeIsSurfacedHonestlyRatherThanAsASuccess(): void
    {
        // The resume round trip is not built, so a challenge must
        // not be reported as a placed order. The cart stays intact and the
        // buyer is told plainly.
        $result = $this->serviceThatReturnsPendingAction()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($result->outcome->isPlaced());
        self::assertFalse($this->cartStore->cart()->isEmpty());
        self::assertStringContainsString('could not be completed', strtolower((string) $result->notice));
    }

    public function testAPendingActionNeverReleasesTheKeySoASecondSubmitCannotCreateASecondOrder(): void
    {
        // The provider has already created an order by the time it asks for a
        // challenge, and it has no idempotency of its own: releasing the key
        // would let the retry place a second one.
        $service = $this->serviceThatReturnsPendingAction();

        $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $second = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame(PlacementOutcome::PENDING_ACTION, $second->outcome->state);
        self::assertSame('34999', $second->outcome->reference);
        self::assertCount(1, $this->transport->requests);

        // The replay above is only possible because the challenge was stored
        // as the attempt's outcome. Settled the way a call that never came back
        // is settled, the row would stay `sent` with nothing written on it and
        // the second submit would be told its payment was being confirmed
        // rather than being handed the answer the provider has already given.
        $attempt = $this->attempts()->outcomeFor($this->keyForSubmit());
        self::assertSame(CheckoutAttemptRepository::STATE_COMPLETE, $attempt['state']);

        // And no money-may-be-missing alert. The provider answered, and named
        // the order it created while answering.
        self::assertSame([], $this->log->eventsNamed('checkout.attempt_unresolved'));
    }

    public function testAnOrderPlacesWithNoAnalyticsSessionAtAll(): void
    {
        // [20.1]: analytics being off must not stop a purchase,
        // and a synthetic identifier is forbidden ([20.8]).
        $result = $this->serviceThatPlaces(sessionUuid: null)->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced());
        self::assertSame('checkout.placed_without_session', $this->log->lastInfo()['event'] ?? null);
    }

    public function testRateLimitingRefusesBeforeTheProviderIsCalled(): void
    {
        // [13.8].
        $service = $this->serviceThatPlaces(rateLimit: 0);

        $result = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($result->outcome->isPlaced());
        self::assertSame([], $this->transport->requests);
        self::assertSame(CheckoutResult::REFUSED_RATE_LIMITED, $result->refusedBecause);
    }

    public function testAnAttemptThatCannotBeClaimedRefusesRatherThanChargingUnrecorded(): void
    {
        // The deliberate exception to [20.1]: a charge nobody can reconcile to
        // a local record is worse for the buyer than a checkout that refuses.
        $this->pdo->exec('DROP TABLE checkout_attempts');

        $result = $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame(CheckoutResult::REFUSED_UNRECORDABLE, $result->refusedBecause);
        self::assertSame([], $this->transport->requests);
        self::assertSame('checkout.attempt_unrecordable', $this->log->lastError()['event'] ?? null);
    }

    public function testADatabaseThatDiesBeforeTheSentMarkerRefusesRatherThanChargingUnrecorded(): void
    {
        // The sent marker is the last write before the money can move, and it
        // has two entirely different failure modes. "This row is no longer
        // yours" arrives as a `\DomainException` and is answered as a
        // duplicate, because releasing would strand whoever took the row over.
        // A driver that fell over arrives as a `\PDOException` -- a
        // `\RuntimeException`, so neither a `\DomainException` nor a
        // `\LogicException` -- and must be answered by refusing: the card has
        // not been presented yet, so nobody has paid anything, and a checkout
        // that asks the buyer to try again is the cheapest outcome available.
        // Narrowing the catch to either exception class turns that into a 500.
        $key = $this->keyForSubmit();

        $result = $this->serviceThatPlaces(attempts: $this->attemptsThatCannotMarkSent())
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame(CheckoutResult::REFUSED_UNRECORDABLE, $result->refusedBecause);
        self::assertSame([], $this->transport->requests, 'the card was never presented');
        self::assertSame('checkout.attempt_unrecordable', $this->log->lastError()['event'] ?? null);
        self::assertNull(
            $this->attempts()->outcomeFor($key),
            'and the key goes back, because a request that never reached the provider owes the buyer a retry',
        );
    }

    public function testTheCatalogKindOfEveryLineReachesTheRecorderRatherThanTheUnrestrictedDefault(): void
    {
        // `OrderLine::$kind` is defaulted, so a caller that passes none records
        // every line as the unrestricted case -- including a prescription. The
        // local record is what a support call and a reconciliation sweep read,
        // and one that cannot tell an Rx line from a mask is a record of the
        // wrong order. The cart here is deliberately mixed, so the assertion is
        // about the value travelling and not about the parameter existing.
        $recorder = new class implements OrderRecorder {
            /** @var array<string, string> */
            public array $kinds = [];

            /** @param list<ConsentRecord> $consents */
            public function record(
                OrderEnvelope $order,
                PlacementOutcome $outcome,
                PaymentCredential $credential,
                array $consents,
                string $providerCategory,
                bool $isUpsell = false,
            ): ?int {
                foreach ($order->lines as $line) {
                    $this->kinds[$line->slug] = $line->kind;
                }

                return 1;
            }
        };

        $this->serviceThatPlaces(orders: $recorder)->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame('rx', $recorder->kinds['tirzepatide'] ?? null, 'the prescription is recorded as one');
        self::assertSame('free-addon', $recorder->kinds['blocked-thing'] ?? null);
    }

    public function testASuccessRecordsTheOrderOnTheJourneyAndRoutesOnward(): void
    {
        // The cart is cleared the moment an order is placed, so the recorded
        // reference is the only surviving proof this journey bought anything.
        $result = $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame(['34660'], $this->journey->placedOrders);
        self::assertSame('/upsell/', $result->redirectTo);
    }

    public function testAPlacedOrderIsWhatMarksCheckoutAsTheFurthestStepThisJourneyCompleted(): void
    {
        // `[21.7]`: the field is the furthest step *completed*, and checkout is
        // completed by paying for it. Before this, nothing past the
        // questionnaires ever advanced it, so a buyer who paid was still
        // described to the retargeting platform as mid-intake (`[21.11]`).
        $this->journey->furthestStep = 'intake';

        $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame('checkout', $this->journey->furthestStep);
    }

    public function testMerelyVisitingCheckoutDoesNotCountAsHavingCompletedIt(): void
    {
        // The distinction `[21.7]` draws and the abandonment signal relies on:
        // the visit is carried by the signal's own state
        // (`checkout_abandoned`), while this field says what was finished.
        // Advancing it on a render would tell the platform a buyer completed a
        // step they walked away from.
        $this->serviceThatPlaces()->view();

        self::assertNull($this->journey->furthestStep);
    }

    public function testAPlacedOrderDoesNotDragTheFurthestStepBackFromALaterOne(): void
    {
        // A journey that already reached the receipt and buys again -- a
        // reminted session carries `furthest_step` across (`[21.5]`) -- must
        // not be reported as having got only as far as checkout.
        $this->journey->furthestStep = 'receipt';

        $this->serviceThatPlaces()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame('receipt', $this->journey->furthestStep);
    }

    public function testADeclinedPaymentIsRecordedOnTheJourneyAndNotOnlyReportedAway(): void
    {
        // `[21.10]`'s "payment abandoned" rung had to be inferred from the
        // presence of consents, because the decline was reported to the EMR and
        // stamped nowhere locally. Recorded here it is a fact rather than a
        // guess.
        $this->serviceThatDeclines()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($this->journey->hasEmrEvent('order_declined'));
    }

    public function testRecordingTheDeclineLocallyDoesNotReportASecondOneToTheEmr(): void
    {
        // `[21.13]`: the local mark is bookkeeping, not a second report. One
        // provider answer is one report, whatever is written alongside it.
        $reporter = new CountingCheckoutEventReporter();

        $this->service('vrio-order-declined.json', events: $reporter)
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertCount(1, $reporter->declines);
    }

    public function testASecondDeclineDoesNotAppendASecondLocalRecord(): void
    {
        // The buyer retries with another card and is refused again. The local
        // ledger is a set of names, so it still holds exactly one -- which is
        // what stops a resumed journey re-firing on it (`[4.13]`, `[21.13]`).
        $service = $this->serviceThatDeclines();
        $this->transport->queueFixture('vrio-order-declined.json');

        $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);
        $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertSame(
            ['order_declined'],
            array_values(array_filter(
                $this->journey->emrEvents,
                static fn (string $name): bool => $name === 'order_declined',
            )),
        );
    }

    public function testAProviderChallengeIsRecordedTheSameWayItIsReported(): void
    {
        // A challenge is reported as `order_declined`, because that is
        // what it is state-wise until the resume round trip exists. The local
        // record follows the report rather than inventing a second answer to
        // the same question.
        $this->serviceThatReturnsPendingAction()->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($this->journey->hasEmrEvent('order_declined'));
    }

    public function testAnOrderStillPlacesWhenThereIsNoJourneyToRecordProgressOn(): void
    {
        // `[20.1]`: progress recording is bookkeeping on a path that handles
        // money. A journey the server never saw start (`[20.8]`) is nowhere to
        // write, and must not be a reason the charge fails.
        $result = $this->serviceThatPlaces(sessionUuid: null)->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced());
        self::assertNull($this->journey);
    }

    public function testADeclineStillReachesTheBuyerWhenThereIsNoJourneyToRecordItOn(): void
    {
        $result = $this->service('vrio-order-declined.json', sessionUuid: null)
            ->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($result->outcome->isPlaced());
        self::assertSame('Failed test transaction', $result->notice);
    }

    public function testTheViewRefillsTheFormFromWhatWasJustSubmittedRatherThanFromStoredAnswers(): void
    {
        // [13.30]: the form comes back carrying what was typed. Prefill's own
        // precedence puts stored answers first, which is right for a first
        // render and wrong for the retry after a decline.
        $service = $this->serviceThatDeclines();
        $buyer = $this->buyer(email: 'corrected@example.com');

        $service->submit($buyer, $this->card(), ['terms' => 'on']);
        $view = $service->view($buyer, ['email' => 'nope']);

        self::assertSame('corrected@example.com', $view->prefill['email']);
        self::assertSame('nope', $view->errors['email']);
    }

    public function testTheViewNeverCarriesTheCardIntoTheRenderedFields(): void
    {
        // The buyer's contact details are preserved so the form re-fills; the
        // card is not a contact detail ([15.8]).
        $view = $this->serviceThatPlaces()->view($this->buyer());

        self::assertStringNotContainsString(self::CARD, (string) json_encode($view->prefill));
    }

    public function testTheViewHidesThePromoControlWhenTheAdapterDeclaresNoPromotionSupport(): void
    {
        // [13.18], [14.4]: the storefront adapts its UI rather than the
        // capability failing at runtime.
        $view = $this->serviceThatPlaces(promotions: false)->view();

        self::assertFalse($view->capabilities->supportsPromotions);
    }

    public function testTheViewSurfacesTheRxLinesPlansWithTheChosenOneNamed(): void
    {
        $view = $this->serviceThatPlaces()->view();

        self::assertSame('tirzepatide', $view->plans['slug']);
        self::assertSame('t-3m', $view->plans['chosen']);
        self::assertCount(2, $view->plans['options']);
    }

    public function testTheViewPrefillsFromTheIntakeAnswersTheVisitorHasAlreadyGiven(): void
    {
        // [13.5b]: the intake's own db_fields map is read backwards, so a
        // question answered two steps ago fills the box that wants the same
        // record path. [13.5c]: a stored answer outranks working state.
        $this->journey->storeAnswers('tf-medical', ['email_address' => 'from-intake@example.com']);
        $this->journey->storeBuyer(['email' => 'from-working-state@example.com', 'city' => 'San Francisco']);

        $view = $this->serviceThatPlaces(teleformId: 'tf-medical')->view();

        self::assertSame('from-intake@example.com', $view->prefill['email']);
        self::assertSame('San Francisco', $view->prefill['city'], 'working state still fills the gaps');
    }

    public function testTheTotalsHonourAnAppliedPromotion(): void
    {
        // [13.12]: subtotal minus discount, clamped, in integer cents.
        $service = $this->service(null);
        $this->queueDiscount('12.00');

        self::assertTrue($service->applyPromotion('SAVE10')->accepted);

        $view = $service->view();

        self::assertSame(12000, $view->totals->subtotalCents);
        self::assertSame(1200, $view->totals->discountCents);
        self::assertSame(10800, $view->totals->totalCents);
    }

    public function testAnUnchangedCartIsNotRequotedOnEveryRender(): void
    {
        // The quote is stored with a digest of the cart it priced, so the
        // common case -- nothing moved since the code was applied -- costs no
        // provider call at all.
        $service = $this->service(null);
        $this->queueDiscount('12.00');
        $service->applyPromotion('SAVE10');

        $service->view();
        $service->view();

        self::assertCount(1, $this->transport->requests, 'only the apply itself asked the provider');
    }

    public function testAPlanSwitchRepricesTheStoredDiscountRatherThanShowingIt(): void
    {
        // The provider owns the discount arithmetic and recomputes it from the
        // lines it is sent, so a figure quoted against the 3-month plan is not
        // a figure about the 1-month one. Nothing used to re-quote, and
        // switching plan on the checkout page left the old discount sitting on
        // the summary.
        $service = $this->service(null);
        $this->queueDiscount('12.00');
        $service->applyPromotion('SAVE10');

        $service->choosePlan('tirzepatide', 't-1m');
        $this->queueDiscount('14.00');

        $view = $service->view();

        self::assertSame(14000, $view->totals->subtotalCents);
        self::assertSame(1400, $view->totals->discountCents, 'priced against the plan actually in the cart');
        self::assertSame(12600, $view->totals->totalCents);
        self::assertSame(CheckoutService::PROMOTION_REPRICED_NOTICE, $view->notice);
    }

    public function testTheDisplayedDiscountAndTheChargedLinesComeFromOneQuote(): void
    {
        // The defect in one assertion: the summary said $27.00 off while the
        // payload carried the $30.00 line and the code, and the provider
        // recomputed against the cart it was actually sent. Both sides have to
        // derive from the same quote against the same cart.
        $service = $this->service(null);
        $this->queueDiscount('12.00');
        $service->applyPromotion('SAVE10');

        $service->choosePlan('tirzepatide', 't-1m');
        $this->queueDiscount('14.00');
        $displayed = $service->view()->totals;

        $this->transport->queueFixture('vrio-order-approved.json');
        $result = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertTrue($result->outcome->isPlaced());
        $charged = $this->transport->body(count($this->transport->requests) - 1);

        self::assertSame(1400, $displayed->discountCents);
        self::assertSame('140.00', $charged['offers'][0]['order_offer_price']);
        self::assertSame('SAVE10', $charged['offers'][0]['discount_code']);
    }

    public function testASubmitWhoseDiscountHasMovedStopsBeforePresentingTheCard(): void
    {
        // The cart can move between the render and the submit -- another tab,
        // the cart page, a back button. Charging a total the buyer never saw is
        // the one outcome this may not have, so it re-renders instead.
        $service = $this->service(null);
        $this->queueDiscount('12.00');
        $service->applyPromotion('SAVE10');

        $service->choosePlan('tirzepatide', 't-1m');
        $this->queueDiscount('14.00');

        $result = $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertFalse($result->outcome->isPlaced());
        self::assertSame(CheckoutService::PROMOTION_REPRICED_NOTICE, $result->notice);
        self::assertSame(1400, $result->totals->discountCents);
        self::assertCount(2, $this->transport->requests, 'the two quotes, and no order');
    }

    public function testACodeThatNoLongerAppliesToTheChangedCartIsDroppedAndSaidSo(): void
    {
        $service = $this->service(null);
        $this->queueDiscount('12.00');
        $service->applyPromotion('SAVE10');

        $service->choosePlan('tirzepatide', 't-1m');
        $this->transport->queue(200, (string) json_encode([
            'offers' => [['offer_id' => 337, 'offer_total_discount' => '0.00', 'discount_details' => ['discount_code_valid' => false]]],
        ]));

        $view = $service->view();

        self::assertSame(0, $view->totals->discountCents);
        self::assertSame(14000, $view->totals->totalCents);
        self::assertSame(CheckoutService::PROMOTION_DROPPED_NOTICE, $view->notice);
        self::assertNull($this->journey->promotion, 'nothing is left to be re-quoted next render');
    }

    public function testADiscountLargerThanTheCartIsRefusedRatherThanRenderedAsPayNothing(): void
    {
        // The clamp in Totals guards the display and cannot reach the charge,
        // because the provider payload carries no order total: a $130 quote
        // against a $120 cart rendered "Pay $0.00" while $120 of real line
        // prices went out with the code attached. It is also the signature of
        // the two sides pricing different carts, which fails silently.
        $service = $this->service(null);
        $this->queueDiscount('130.00');

        $outcome = $service->applyPromotion('TOOBIG');

        self::assertFalse($outcome->accepted);
        self::assertNull($this->journey->promotion);
        self::assertSame(12000, $service->view()->totals->totalCents, 'the full price, which is what will be charged');
        self::assertNotSame([], $this->log->eventsNamed('checkout.promotion_exceeds_cart'));
    }

    public function testAStoredQuoteWithNoCartBehindItIsRequotedRatherThanTrusted(): void
    {
        // Journey state survives a deploy, so a promotion written before the
        // cart digest existed is a real possibility -- and a figure with no
        // cart attached to it is exactly what must never be shown.
        $this->journey->promotion = ['code' => 'SAVE10', 'discount_cents' => 9900];
        $this->queueDiscount('12.00');

        $view = $this->service(null)->view();

        self::assertSame(1200, $view->totals->discountCents);
    }

    public function testAPlacedOrderLeavesNoQuoteBehindForTheNextCode(): void
    {
        // `[13.17]`: the promotion is dropped, and so is the digest of the cart
        // it priced -- a digest left behind would make the next code entered
        // look like one that had already been quoted.
        $service = $this->service(null);
        $this->queueDiscount('12.00');
        $service->applyPromotion('SAVE10');
        $this->transport->queueFixture('vrio-order-approved.json');

        $service->submit($this->buyer(), $this->card(), ['terms' => 'on']);

        self::assertNull($this->journey->promotion);
        self::assertNull($this->journey->promotionQuotedAgainst);
    }

    // ---------------------------------------------------------------- fixtures

    /** The whole service, with the recorded approval queued on the transport. */
    private function serviceThatPlaces(
        ?string $sessionUuid = self::SESSION_UUID,
        ?string $strategy = null,
        int $rateLimit = 8,
        bool $promotions = true,
        ?string $teleformId = null,
        ?CheckoutAttemptRepository $attempts = null,
        ?\Closure $clock = null,
        ?CheckoutEventReporter $events = null,
        ?OrderRecorder $orders = null,
        array $upsells = self::UPSELLS,
    ): CheckoutService {
        return $this->service(
            'vrio-order-approved.json',
            $sessionUuid,
            $strategy,
            $rateLimit,
            $promotions,
            $teleformId,
            $attempts,
            $clock,
            $events,
            $orders,
            $upsells,
        );
    }

    private function serviceThatDeclines(): CheckoutService
    {
        return $this->service('vrio-order-declined.json');
    }

    private function serviceThatReturnsPendingAction(): CheckoutService
    {
        return $this->service('vrio-order-pending.json');
    }

    /**
     * A service whose provider call never completes.
     *
     * The provider's client reports a network failure as a key on the decoded
     * envelope rather than by throwing, which is the shape `VrioOutcome` reads,
     * so the transport queues that rather than a fixture.
     */
    private function serviceThatTimesOut(): CheckoutService
    {
        $this->transport->queue(0, '', 'Operation timed out after 30000 milliseconds');

        return $this->service(null);
    }

    /** The key this cart and this buyer derive, so a case can arrange the attempt row a submit will find. */
    private function keyForSubmit(): string
    {
        return IdempotencyKey::forSubmit(
            $this->journeys->sessionUuid(),
            (string) session_id(),
            $this->cartStore->cart(),
            $this->buyer()->toArray(),
        );
    }

    /**
     * One `calculateDiscount` answer: the code valid on the single chargeable
     * line, worth $amount.
     */
    private function queueDiscount(string $amount): void
    {
        $this->transport->queue(200, (string) json_encode([
            'offers' => [[
                'offer_id' => 337,
                'offer_total_discount' => $amount,
                'discount_details' => ['discount_code_valid' => true],
            ]],
        ]));
    }

    /**
     * The recorded approval with both halves of the vault handle removed.
     *
     * Derived from the fixture rather than hand-written, so it cannot drift
     * from the shape the provider really sends and cannot accidentally stop
     * being a *placed* response — which is the whole point of the case. Only
     * the `data` node goes on the wire, for the reason
     * {@see FakeVrioTransport::queueFixture()} gives: the client rebuilds its
     * own envelope around what the provider's raw body carried.
     */
    private static function approvalWithoutTheHandle(): string
    {
        /** @var array<string, mixed> $envelope */
        $envelope = (array) json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/vrio-order-approved.json'),
            true,
        );

        /** @var array<string, mixed> $data */
        $data = (array) ($envelope['data'] ?? []);
        /** @var array<string, mixed> $order */
        $order = (array) ($data['order'] ?? []);
        unset($data['customer_id'], $order['customer_id'], $order['customer_card_id'], $order['customer_card']);
        $data['order'] = $order;

        return (string) json_encode($data);
    }

    private function attempts(): CheckoutAttemptRepository
    {
        return new CheckoutAttemptRepository(fn (): \PDO => $this->pdo, 'sqlite');
    }

    /**
     * The attempt row exactly as the table holds it, for a case that has to
     * hand a stale snapshot of it to a second request.
     *
     * @return array<string, mixed>
     */
    private function rawAttempt(string $key): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE idempotency_key = ?');
        $statement->execute([$key]);

        return (array) $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * The attempts table with only its post-charge write broken.
     *
     * Everything before the charge -- the claim, and the marker that says the
     * provider is about to be contacted -- still succeeds, so the case really
     * is "the charge happened and then the database went away" rather than the
     * refusal that a wholly unreachable database already produces.
     */
    private function attemptsThatCannotComplete(): CheckoutAttemptRepository
    {
        $pdo = DeadAfterChargePdo::alongside($this->pdo);

        return new CheckoutAttemptRepository(static fn (): \PDO => $pdo, 'sqlite');
    }

    /**
     * The attempts table with only the sent marker broken.
     *
     * The claim still succeeds and the release still succeeds, so the case is
     * narrowly "the driver fell over in the one-statement gap between claiming
     * the key and telling the row the provider is about to be contacted".
     * Taking the whole connection down instead fails at the claim, which is a
     * refusal that already has its own coverage and proves nothing about this
     * gap.
     *
     * `SET state = ? WHERE` is written by that one statement alone — the
     * completing write sets `outcome` alongside it and the takeover sets
     * `session_key` — so the shadow cannot catch anything else.
     */
    private function attemptsThatCannotMarkSent(): CheckoutAttemptRepository
    {
        $row = $this->pdo->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertIsString($row['file'] ?? null);

        $shadow = new class ('sqlite:' . $row['file'], null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]) extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'SET state = ? WHERE')) {
                    throw new \PDOException('SQLSTATE[HY000]: General error: the database went away before the charge');
                }

                return parent::prepare($query, $options);
            }
        };

        return new CheckoutAttemptRepository(static fn (): \PDO => $shadow, 'sqlite');
    }

    /**
     * @param string|null              $fixture the provider response to queue, or null when the case has already queued its own
     * @param array<string, mixed>     $upsells `config/upsells.php`'s own `upsells` map
     */
    private function service(
        ?string $fixture,
        ?string $sessionUuid = self::SESSION_UUID,
        ?string $strategy = null,
        int $rateLimit = 8,
        bool $promotions = true,
        ?string $teleformId = null,
        ?CheckoutAttemptRepository $attempts = null,
        ?\Closure $clock = null,
        ?CheckoutEventReporter $events = null,
        ?OrderRecorder $orders = null,
        array $upsells = self::UPSELLS,
    ): CheckoutService {
        if ($sessionUuid !== self::SESSION_UUID) {
            $this->boot($sessionUuid);
        }

        if ($fixture !== null) {
            $this->transport->queueFixture($fixture);
        }

        $catalog = self::catalog($teleformId);
        $config = Config::load(dirname(__DIR__, 2) . '/config', $_ENV);

        $adapter = new VrioAdapter(
            new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
            new VrioApiFactory($this->transport),
            $this->log->log,
            shippingProfileId: 1,
        );

        return new CheckoutService(
            carts: $this->cartStore,
            journeys: $this->journeys,
            rules: new CartRules($catalog),
            catalog: $catalog,
            adapter: $strategy === null && $promotions
                ? $adapter
                : new RedeclaredAdapter($adapter, $strategy, $promotions),
            consents: Consents::fromConfig((array) require dirname(__DIR__, 2) . '/config/consent.php'),
            bumps: OrderBumps::fromConfig(['max_on_page' => 3, 'bumps' => []], $catalog, $this->log->log),
            verification: new NullVerificationGateway(),
            attempts: $attempts ?? new CheckoutAttemptRepository(fn (): \PDO => $this->pdo, 'sqlite'),
            limiter: new DatabaseRateLimiter(
                new RateLimitRepository(fn (): \PDO => $this->pdo, 'sqlite'),
                ['checkout.submit' => ['limit' => $rateLimit, 'window_seconds' => 300]],
                $this->log->log,
            ),
            orders: $orders ?? new NullOrderRecorder(),
            events: $events ?? new NullCheckoutEventReporter(),
            forms: new TeleformSource(
                $teleformId === null ? new NullTeleformGateway() : self::teleformGateway(),
                new DefinitionCache($this->cacheDir, 3600),
                $this->log->log,
            ),
            flow: FlowDefinition::fromConfig($config),
            config: $config,
            log: $this->log->log,
            postCharge: new PostChargeGuard($this->log->log),
            upsells: Upsells::fromConfig(['upsells' => $upsells], $catalog, $this->log->log),
            clock: $clock,
        );
    }

    /**
     * A throwaway database, fresh stores, and a cart worth 12000 cents.
     *
     * The second line is priced at zero and blocked in New York, so a cart
     * that is worth exactly 12000 either way is also a cart the geo gate has
     * something to refuse.
     */
    private function boot(?string $sessionUuid): void
    {
        $_SESSION = [];
        $this->pdo = $this->tempPdo();
        $this->journeys = new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo));
        $this->journey = $sessionUuid === null ? null : $this->journeys->load($sessionUuid);
        $this->cartStore = new CartStore($this->journeys);

        $cart = new Cart();
        $cart->put(new CartLine('tirzepatide', 'Tirzepatide', 'rx', null, null, 1, 12000, 't-3m'));
        $cart->put(new CartLine('blocked-thing', 'Blocked Thing', 'free-addon', null, 'tirzepatide', 1, 0, null));
        $this->cartStore->save($cart);
    }

    private function buyer(
        string $email = 'ada@example.com',
        string $territory = 'CA',
        string $postalCode = '94105',
    ): BuyerDetails {
        return BuyerDetails::fromSubmitted([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $email,
            'phone' => '(212) 555-1234',
            'address_line' => '350 5th Avenue',
            'city' => 'San Francisco',
            'territory' => $territory,
            'postal_code' => $postalCode,
        ]);
    }

    private function card(): PaymentCredential
    {
        return PaymentCredential::card(self::CARD, '12', '2030', self::SECURITY_CODE);
    }

    /**
     * A gateway serving one form's metadata and nothing else.
     *
     * Only the db_fields map matters here: prefill reads it backwards, asking
     * which answer ends up at `opportunity.email` rather than where the email
     * answer goes. The definition is never fetched, because prefill wants the
     * map and not the questions.
     */
    private static function teleformGateway(): TeleformGateway
    {
        return new class implements TeleformGateway {
            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'acct/org/form_' . $teleformId . '.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: ['email_address' => 'opportunity.email'],
                );
            }

            /** @return array<string, mixed>|null */
            public function definition(TeleformMetadata $metadata): ?array
            {
                return null;
            }
        };
    }

    private static function catalog(?string $teleformId = null): ProductCatalog
    {
        return new FakeCatalog([
            'tirzepatide' => [
                'slug' => 'tirzepatide',
                'name' => 'Tirzepatide',
                'kind' => 'rx',
                'teleform_id' => $teleformId,
                'image' => '/assets/img/t1.png',
                'variants' => [
                    ['id' => 't-1m', 'name' => '1 Month', 'price_cents' => 14000, 'provider' => ['offer_id' => '337', 'product_id' => '3414']],
                    ['id' => 't-3m', 'name' => '3 Months', 'price_cents' => 12000, 'provider' => ['offer_id' => '337', 'product_id' => '3415']],
                ],
            ],
            // Free, mapped to nothing, and refused in New York -- the two
            // things a summary line can be that a charge cannot.
            'blocked-thing' => [
                'slug' => 'blocked-thing',
                'name' => 'Blocked Thing',
                'kind' => 'free-addon',
                'geo_blocks' => ['NY'],
                'variants' => [],
            ],
            'unmapped' => [
                'slug' => 'unmapped',
                'name' => 'Unmapped Thing',
                'kind' => 'otc',
                'price_cents' => 4900,
                'variants' => [['id' => 'u-1', 'name' => 'One', 'price_cents' => 4900, 'provider' => null]],
            ],
        ]);
    }
}

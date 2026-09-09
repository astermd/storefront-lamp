<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Upsell;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\ConsentRecord;
use AsterMD\Storefront\Checkout\IdempotencyKey;
use AsterMD\Storefront\Checkout\OrderRecorder;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Payment\Vrio\VrioOutcome;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Upsell\UpsellQueue;
use AsterMD\Storefront\Upsell\UpsellService;
use AsterMD\Storefront\Upsell\Upsells;
use PHPUnit\Framework\TestCase;

/**
 * The post-purchase upsell step, offer by offer (§16).
 *
 * The service is assembled by hand rather than through the container, because
 * the binding is the wiring step's; the HTTP surface has its own cases in
 * {@see \AsterMD\Storefront\Tests\Http\UpsellControllerTest}.
 *
 * **The adapter is the real one over {@see FakeVrioTransport}**, so every
 * money-path case here exercises the whole payload assembly and the whole
 * response mapping with no packet leaving the process. That matters more here
 * than anywhere else in this suite, because a card-on-file charge **cannot be
 * made to decline against the live sandbox** — a vault charge submits no PAN
 * and the test gateway decides on the PAN. So `[16.11]`'s branch is only ever
 * reachable with the response canned, and a live walk expecting a declined
 * upsell would pass for the wrong reason.
 *
 * The canned responses are built *from* the recorded fixtures rather than by
 * hand wherever the shape matters, so an assertion here cannot drift from what
 * the provider actually sent.
 *
 * The logger is the real {@see \AsterMD\Storefront\Support\OperatorLog} writing
 * to a temp file: a recording double would skip the redaction pass, which is
 * the thing the "nothing leaked" assertions are about.
 */
final class UpsellServiceTest extends TestCase
{
    use TempDatabase;

    private const string SESSION_UUID = 'e2b8c9d1-4a5f-4c7b-8e2a-1f3d5b7c9e11';

    /** The reference of the prescription order this journey already placed. */
    private const string CHECKOUT_REFERENCE = '34788';

    private \PDO $pdo;

    private JourneyStore $journeys;

    private JourneyState $journey;

    private FakeVrioTransport $transport;

    private CapturedLog $log;

    private RecordingUpsellReporter $reporter;

    private RecordingUpsellRecorder $recorder;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->log = new CapturedLog();
        $this->transport = new FakeVrioTransport();
        $this->reporter = new RecordingUpsellReporter();
        $this->recorder = new RecordingUpsellRecorder();
        $this->pdo = $this->tempPdo();
        $this->journeys = new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo));
        $this->journey = $this->journeys->load(self::SESSION_UUID);

        $this->journey->storeBuyer([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'buyer@example.com',
            'phone' => '2125551234',
            'address_line' => '350 5th Avenue',
            'city' => 'New York',
            'territory' => 'ny',
            'postal_code' => '10001',
        ]);
        $this->journey->recordPlacedOrder(self::CHECKOUT_REFERENCE);
        $this->journey->upsellQueue = ['wellness-pack', 'sleep-kit'];
        $this->journey->upsellCursor = 0;
        $this->journey->storeReusableCredential(
            PaymentCredential::stored(['customer_id' => '13996', 'customer_card_id' => '16815']),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ---------------------------------------------------------------- view()

    public function testTheOfferedEventFiresOncePerUpsellHoweverOftenThePageIsRendered(): void
    {
        $service = $this->service();

        $service->view();
        $service->view();
        $service->view();

        self::assertSame(
            [['wellness-pack', 'Wellness Pack']],
            $this->reporter->offered,
            '[16.7] is once per upsell, and a refresh is not a second offer',
        );
    }

    public function testAnEmptyQueueRendersNothingSoTheControllerCanSendThemToTheReceipt(): void
    {
        $this->journey->upsellQueue = [];

        self::assertNull($this->service()->view());
        self::assertSame([], $this->reporter->offered, 'nothing was offered, so nothing is reported');
    }

    public function testANullJourneyRendersNothingRatherThanErroring(): void
    {
        self::assertNull($this->serviceWithoutAJourney()->view());
    }

    public function testTheViewModelCarriesTheOfferCopyThePriceAndThePosition(): void
    {
        $view = $this->service()->view();

        self::assertNotNull($view);
        self::assertSame('wellness-pack', $view->key);
        self::assertSame('Wait! Your Exclusive Offer!', $view->eyebrow);
        self::assertSame('Enhance Your Wellness Journey', $view->headline);
        self::assertSame(['Immune Support', 'Energy Boost'], $view->bullets);
        self::assertSame('Wellness Pack', $view->productName);
        self::assertSame(899, $view->priceCents, 'the decided figure, which is what the charge will use');
        self::assertSame('USD', $view->currency);
        self::assertSame('Add to Order', $view->acceptLabel);
        self::assertSame(UpsellService::ACCEPT_PATH, $view->acceptPath);
        self::assertSame(UpsellService::DECLINE_PATH, $view->declinePath);
        self::assertSame(1, $view->position);
        self::assertSame(2, $view->total);
    }

    public function testTheSecondOfferIsRenderedAfterTheFirstIsAnswered(): void
    {
        // `[16.3]`: one per step, and the step really does move.
        $service = $this->service();
        $service->decline();

        $view = $service->view();

        self::assertNotNull($view);
        self::assertSame('sleep-kit', $view->key);
        self::assertSame(2, $view->position);
    }

    // -------------------------------------------------------------- accept()

    public function testAnAcceptedUpsellPlacesASingleLineOrderOnTheCredentialHandle(): void
    {
        $this->queuePlacement('34790');

        $result = $this->offered()->accept();

        $body = $this->transport->body(0);

        // `[16.8]`: one line, at the figure the page showed.
        self::assertCount(1, $body['offers'] ?? []);
        self::assertSame('412', $body['offers'][0]['offer_id'] ?? null);
        self::assertSame('3600', $body['offers'][0]['item_id'] ?? null);
        self::assertSame('8.99', $body['offers'][0]['order_offer_price'] ?? null);
        self::assertSame('1', $body['offers'][0]['order_offer_quantity'] ?? null);
        self::assertSame(
            '',
            $body['offers'][0]['discount_code'] ?? null,
            '[13.17]: the promotion does not bleed into the upsell flow',
        );

        // [16.8]: the buyer's contact details are reused, and the whole
        // shipping block goes with them -- the provider does not resolve a
        // country from the customer, and omitting it is refused with an empty
        // country in the message.
        self::assertSame('buyer@example.com', $body['email'] ?? null);
        self::assertSame('US', $body['ship_country'] ?? null);
        self::assertSame('10001', $body['ship_zipcode'] ?? null);
        self::assertSame('NY', $body['ship_state'] ?? null);
        self::assertSame('350 5th Avenue', $body['ship_address1'] ?? null);
        self::assertSame('New York', $body['ship_city'] ?? null);

        // [15.13]: whatever the handle is, it is submitted and never inspected
        // -- and no card field rides along with it.
        self::assertSame('13996', (string) ($body['customer_id'] ?? ''));
        self::assertSame('16815', (string) ($body['customer_card_id'] ?? ''));
        foreach (['card_number', 'card_cvv', 'card_exp_month', 'card_exp_year', 'card_type_id'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $body);
        }

        self::assertSame('/upsell/', $result->redirectTo, 'one more offer is queued');
    }

    public function testAPlacedUpsellIsAppendedToTheJourneysPlacedOrdersAndRecordedLocally(): void
    {
        $this->queuePlacement('34790');

        $this->offered()->accept();

        self::assertSame([self::CHECKOUT_REFERENCE, '34790'], $this->journey->placedOrders);
        self::assertSame(JourneyState::UPSELL_ACCEPTED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame([['wellness-pack', 'Wellness Pack']], $this->reporter->accepted);

        $recorded = $this->recorder->last();
        self::assertNotNull($recorded);
        self::assertSame('34790', $recorded['reference']);
        self::assertSame(PlacementOutcome::PLACED, $recorded['status']);
        self::assertSame(899, $recorded['amount_cents']);
        self::assertSame(0, $recorded['discount_cents']);
        self::assertNull($recorded['promotion_code']);
        self::assertSame('wellness-pack', $recorded['anchor_slug']);
        self::assertTrue($recorded['is_upsell'], '[16.12]: the row says which kind of order this was');
        self::assertSame([], $recorded['consents'], 'the consents were taken once, at checkout');
        self::assertSame('otc', $recorded['lines'][0]->kind, "the catalog's own kind reaches the line");
    }

    public function testADeclinedUpsellChargeAdvancesTheQueueInsteadOfStoppingTheFlow(): void
    {
        // [16.11]. The buyer has already successfully purchased; trapping them
        // on an optional add-on is worse than skipping it.
        $this->transport->queueFixture('vrio-order-declined.json');

        $result = $this->offered()->accept();

        self::assertSame(JourneyState::UPSELL_CHARGE_DECLINED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame(1, $this->journey->upsellCursor, 'the queue advanced');
        self::assertSame('/upsell/', $result->redirectTo, 'forward, to the next offer');
        self::assertNull($result->notice, 'a declined add-on is not an error the buyer has to act on');

        // [16.12]: recorded locally with its declined status, and NOT counted
        // as an order this journey placed.
        $recorded = $this->recorder->last();
        self::assertNotNull($recorded);
        self::assertSame(PlacementOutcome::DECLINED, $recorded['status']);
        self::assertTrue($recorded['is_upsell']);
        self::assertSame([self::CHECKOUT_REFERENCE], $this->journey->placedOrders);
        self::assertSame([['wellness-pack', 'Wellness Pack']], $this->reporter->declined);
    }

    public function testADeclinedUpsellOnTheLastOfferSendsTheBuyerToTheReceipt(): void
    {
        // The same rule at the end of the queue: a decline is still forward.
        $this->journey->upsellQueue = ['wellness-pack'];
        $this->transport->queueFixture('vrio-order-declined.json');

        $result = $this->offered()->accept();

        self::assertSame('/thank-you/', $result->redirectTo);
    }

    public function testARefusedCredentialHandleAdvancesTheQueueAndKeepsTheKeyRetryable(): void
    {
        // The reachable card-on-file failure, per the recordings: success
        // false, no order id, `customer_card_invalid`, and no transaction node
        // at all. It charged nothing, so the attempt key goes back and no
        // money-may-be-missing alert fires.
        $this->queueRefusal();
        $key = $this->keyFor('wellness-pack', 'wellness-pack');

        $result = $this->offered()->accept();

        self::assertSame(JourneyState::UPSELL_CHARGE_DECLINED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertNull($this->attempts()->outcomeFor($key), 'the key was released');
        self::assertSame([], $this->log->eventsNamed('upsell.attempt_unresolved'));
        self::assertSame('/upsell/', $result->redirectTo);

        // `[13.28]`'s show-it-verbatim rule has one deliberate exception, and
        // this is it: the provider's own text names the buyer's card id.
        self::assertSame(VrioOutcome::STORED_CREDENTIAL_DECLINE, $result->outcome?->reason);
    }

    public function testAJourneyWithNoCredentialHandleSkipsTheOfferWithoutCallingTheProvider(): void
    {
        $this->journey->storeReusableCredential(null);

        $result = $this->offered()->accept();

        self::assertSame([], $this->transport->requests, 'nothing reached the provider');
        self::assertSame(JourneyState::UPSELL_SKIPPED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame(1, $this->journey->upsellCursor);
        self::assertNull($result->notice, 'the buyer is not told about a capability their provider lacks');
        self::assertNotSame([], $this->log->eventsNamed('upsell.no_credential'));
    }

    public function testASecondSubmitOfTheSameAcceptDoesNotChargeTwice(): void
    {
        $this->queuePlacement('34790');
        $service = $this->service();
        $service->view();

        $service->accept();
        // The cursor moved, so a replayed POST finds a different current offer
        // -- but the idempotency key is the belt to that braces, so the second
        // acceptance is arranged to land on the *same* offer. The re-render is
        // what a browser does on the way back to this page, and it is also what
        // re-freezes the quote the second acceptance is checked against.
        $this->journey->upsellCursor = 0;
        $service->view();
        $service->accept();

        self::assertCount(1, $this->transport->requests, 'the provider was contacted once');
        self::assertSame([self::CHECKOUT_REFERENCE, '34790'], $this->journey->placedOrders);
        self::assertSame(JourneyState::UPSELL_ACCEPTED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame(1, $this->journey->upsellCursor, 'the replay still moved the buyer on');
    }

    public function testTheAttemptRowIsWrittenWithOneOwnershipTokenAndNotTheClockEachTime(): void
    {
        // The absence a mutation cannot find, because the two readings agree
        // almost always: `markSent()` and `release()` are conditional on the
        // `created_at` the claim wrote, so a second read of the clock produces
        // the wrong token on exactly the requests that straddle a second
        // boundary. `markSent()` reads that as a takeover and tells the buyer
        // their add-on is being confirmed; `release()` silently matches nothing
        // and leaves the row standing. A clock that moves on every call is what
        // makes the always-case into the never-case.
        $tick = 0;
        $clock = static function () use (&$tick): string {
            return gmdate('c', 1_760_000_000 + $tick++);
        };

        $this->queuePlacement('34790');

        $result = $this->offered(clock: $clock)->accept();

        self::assertSame(JourneyState::UPSELL_ACCEPTED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame('/upsell/', $result->redirectTo);
        self::assertSame([], $this->log->eventsNamed('upsell.attempt_taken_over'));

        // A placement keeps its key forever, so the settled row is still there
        // and it is `complete` rather than stuck at `sent`.
        $row = $this->attempts()->outcomeFor($this->keyFor('wellness-pack', 'wellness-pack'));
        self::assertNotNull($row);
        self::assertSame(CheckoutAttemptRepository::STATE_COMPLETE, $row['state']);
    }

    public function testADeclinedChargeGivesTheKeyBackUnderTheTokenItWasClaimedWith(): void
    {
        // The release half of the same absence: a token one tick out matches no
        // row, and the row it failed to delete is what tells the next
        // acceptance its payment is being confirmed.
        $tick = 0;
        $clock = static function () use (&$tick): string {
            return gmdate('c', 1_760_000_000 + $tick++);
        };

        $this->transport->queueFixture('vrio-order-declined.json');

        $this->offered(clock: $clock)->accept();

        self::assertNull(
            $this->attempts()->outcomeFor($this->keyFor('wellness-pack', 'wellness-pack')),
            'a provider-answered decline takes no money, so the key goes back',
        );
    }

    public function testTheUpsellsOwnResponseRefreshesTheCredentialHandle(): void
    {
        // An upsell placement returns a handle too, and a second upsell in the
        // queue charges against the freshest one.
        $this->queuePlacement('34790', ['customer_id' => '13996', 'customer_card_id' => '16999']);

        $this->offered()->accept();

        self::assertSame('16999', $this->journey->reusableCredential()?->handle['customer_card_id']);
    }

    public function testAnUpsellPlacementWhoseResponseCarriesNoHandleLeavesTheOldOneStanding(): void
    {
        // Never null out a working handle on the strength of a response that
        // simply did not repeat it -- the next offer in the queue would be
        // unchargeable for no reason.
        $this->queuePlacement('34790', null);
        $before = $this->journey->reusableCredential()?->handle;

        $this->offered()->accept();

        self::assertSame($before, $this->journey->reusableCredential()?->handle);
    }

    public function testEveryPostChargeWriteFailingStillAdvancesTheBuyer(): void
    {
        // Everything downstream of the card dies the instant it is handed over.
        // The buyer still moves on, and every failure carries the provider
        // reference so an operator can go and repair the record.
        $this->queuePlacement('34790');

        // Rendered by an ordinary service first: the offer has to reach the
        // screen before it can be accepted, and it is what happens *after* the
        // card is handed over that this case is about.
        $this->service()->view();

        $result = $this->service(
            orders: new ThrowingUpsellRecorder(),
            events: new ThrowingUpsellReporter(),
        )->accept();

        self::assertSame('/upsell/', $result->redirectTo);
        self::assertSame(JourneyState::UPSELL_ACCEPTED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame(1, $this->journey->upsellCursor);

        $failures = $this->log->eventsNamed('checkout.post_charge_write_failed');
        self::assertGreaterThan(0, count($failures));
        foreach ($failures as $failure) {
            self::assertSame('34790', $failure['context']['reference'] ?? null);
        }
    }

    public function testAFloodOfAcceptsIsRefusedBeforeAnythingCostsMoney(): void
    {
        $this->queuePlacement('34790');
        $service = $this->service(rateLimit: 0);

        $result = $service->accept();

        self::assertSame([], $this->transport->requests, 'the guard runs before the charge');
        self::assertSame(UpsellService::RATE_LIMITED_NOTICE, $result->notice);
        self::assertSame('/upsell/', $result->redirectTo, 'the offer is still on screen');
        self::assertSame(0, $this->journey->upsellCursor, 'a refusal answers nothing, so nothing advances');
        self::assertSame([], $this->journey->upsellOutcomes);
    }

    public function testAnAcceptOnAnEmptyQueueGoesToTheReceiptRatherThanCharging(): void
    {
        $this->journey->upsellQueue = [];

        $result = $this->offered()->accept();

        self::assertSame('/thank-you/', $result->redirectTo);
        self::assertSame([], $this->transport->requests);
    }

    public function testAnAcceptWithNoJourneyAtAllGoesToTheReceiptRatherThanCharging(): void
    {
        $result = $this->serviceWithoutAJourney()->accept();

        self::assertSame('/thank-you/', $result->redirectTo);
        self::assertSame([], $this->transport->requests);
    }

    // ------------------------------------------------------------- decline()

    public function testDecliningChargesNothingAndAdvances(): void
    {
        // `[16.9]`: no charge, no provider call, and no attempt row either --
        // there is nothing to be idempotent about.
        $result = $this->service()->decline();

        self::assertSame([], $this->transport->requests);
        self::assertSame(JourneyState::UPSELL_DECLINED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame(1, $this->journey->upsellCursor);
        self::assertSame('/upsell/', $result->redirectTo);
        self::assertSame([['wellness-pack', 'Wellness Pack']], $this->reporter->declined);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM checkout_attempts')->fetchColumn());
    }

    public function testDecliningTheLastOfferGoesToTheReceipt(): void
    {
        $this->journey->upsellQueue = ['wellness-pack'];

        self::assertSame('/thank-you/', $this->service()->decline()->redirectTo);
    }

    // -------------------------------------------------------- furthest step

    public function testAnsweringAnOfferRecordsTheUpsellAsTheFurthestStepReached(): void
    {
        // `[21.7]`. It matters more here than anywhere: the cart is cleared at
        // placement, so a post-purchase journey still reporting `checkout` names
        // a step whose own precondition it can no longer satisfy — a resume
        // link built from it would bounce straight off (`[21.11]`).
        $this->journey->furthestStep = 'checkout';

        $this->service()->decline();

        self::assertSame('upsell', $this->journey->furthestStep);
    }

    public function testAnOfferThatIsOnlyShownDoesNotAdvanceTheFurthestStep(): void
    {
        // `[21.10]`'s upsell rung is exactly "offered, never acted on". If the
        // render advanced the step, a buyer who walked away from the offer
        // would be described as having answered it.
        $this->journey->furthestStep = 'checkout';

        $this->service()->view();

        self::assertSame('checkout', $this->journey->furthestStep);
    }

    public function testAnsweringAnOfferDoesNotDragTheFurthestStepBackFromTheReceipt(): void
    {
        $this->journey->furthestStep = 'receipt';

        $this->service()->decline();

        self::assertSame('receipt', $this->journey->furthestStep);
    }

    public function testAnOfferSkippedForWantOfACredentialStillCountsAsReachingTheStep(): void
    {
        // The buyer answered; the deployment's provider could not act on it.
        // That is still an upsell step this journey passed through, and the
        // queue advanced on it.
        $this->journey->storeReusableCredential(null);

        $this->offered()->accept();

        self::assertSame('upsell', $this->journey->furthestStep);
    }

    public function testAChallengeIsRecordedAsAnOrderRatherThanLostAsADecline(): void
    {
        // `[14.22]`. The provider created the order and is waiting on a
        // challenge, so money may still move against it. Recorded as a decline
        // it would appear on no receipt, in no completion total and in no
        // treatment sync — a charge this application had already been told
        // about and then forgot.
        $this->queuePendingAction('34999');

        $result = $this->offered()->accept();

        self::assertContains('34999', $this->journey->placedOrders, 'the order the provider created is known to us');
        $row = $this->recorder->rows[array_key_last($this->recorder->rows)];
        self::assertSame(PlacementOutcome::PENDING_ACTION, $row['status']);
        self::assertSame('34999', $row['reference']);
        self::assertTrue($row['is_upsell'], 'recorded as the add-on it is');
    }

    public function testAChallengeAdvancesTheQueueAndTellsTheBuyerNothing(): void
    {
        // `[16.11]` still governs the ending: the buyer has already bought what
        // they came for, so an add-on awaiting a challenge this storefront
        // cannot present must not trap them. The checkout stops and quotes the
        // reference because there the challenge *is* the order; here it is not.
        $this->queuePendingAction('34999');

        $result = $this->offered()->accept();

        self::assertSame(1, $this->journey->upsellCursor, 'the queue advanced');
        self::assertNull($result->notice, 'and the buyer is not asked to act on it');
    }

    public function testAChallengeKeepsTheIdempotencyKeySoASecondClickCannotCreateASecondOrder(): void
    {
        // The provider has an order against this key and no idempotency of its
        // own. Releasing it would let the next acceptance create a second one.
        $this->queuePendingAction('34999');

        $this->offered()->accept();

        $attempt = $this->attempts()->outcomeFor($this->keyFor('wellness-pack', 'wellness-pack'));
        self::assertNotNull($attempt, 'the key is held, not given back');

        // Held *with the answer written on it*, which is the difference
        // between a challenge and a call that never came back. Settling a
        // challenge the way an unresolved charge is settled would leave the row
        // `sent` with no outcome, so the buyer's second click would be told its
        // payment was being confirmed instead of being handed the answer the
        // provider has already given.
        self::assertSame(CheckoutAttemptRepository::STATE_COMPLETE, $attempt['state']);
        self::assertSame(PlacementOutcome::PENDING_ACTION, $attempt['outcome']['state'] ?? null);

        // And no money-may-be-missing alert: the provider answered, and it
        // named the order it created while answering. Firing the page-an-
        // operator line here would spend a human on a charge that is exactly
        // where it is supposed to be.
        self::assertSame([], $this->log->eventsNamed('upsell.attempt_unresolved'));
    }

    public function testAReplayedAcceptanceStillPutsTheChargedOrderOnTheJourney(): void
    {
        // The shape the replay branch exists for: the first acceptance charged
        // the card and its request then died before journey state was saved,
        // so the cursor never moved and the buyer is looking at the same offer.
        // The provider has no idempotency of its own, so the only repair that
        // does not charge twice is to take the recorded answer as this
        // request's answer — and taking it means taking the reference with it.
        //
        // Without that, the add-on is charged, the buyer is moved on as though
        // it succeeded, and the order joins no receipt, no completion total and
        // no treatment sync. The money is gone and nothing local names it.
        $this->queuePlacement('34901');
        $this->offered()->accept();

        self::assertContains('34901', $this->journey->placedOrders, 'precondition: the first acceptance charged');

        // The journey write nobody made: everything the first request put on
        // the journey is gone, while its attempt row — written before the
        // charge — stands.
        $this->journey->placedOrders = [self::CHECKOUT_REFERENCE];
        $this->journey->upsellCursor = 0;
        $this->journey->upsellOutcomes = [];

        $result = $this->offered()->accept();

        self::assertCount(1, $this->transport->requests, 'the provider was reached once, not twice');
        self::assertNotSame([], $this->log->eventsNamed('upsell.duplicate_replayed'));
        self::assertContains(
            '34901',
            $this->journey->placedOrders,
            'the replayed answer carries the order it is an answer about',
        );
        self::assertSame(JourneyState::UPSELL_ACCEPTED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame(1, $this->journey->upsellCursor, 'and the queue moves past an offer that is settled');
        self::assertTrue($result->outcome?->isPlaced());
    }

    public function testAReplayedDeclineAdvancesWithoutClaimingAnOrderWasPlaced(): void
    {
        // The same replay, for an answer that took no money. A decline that
        // released its key would be re-charged rather than replayed, so this
        // is specifically the declines the provider itself answered with and
        // that are kept: the stored answer stands, and nothing joins the
        // placed-order list on the strength of it.
        $this->queueRefusal();
        $key = $this->keyFor('wellness-pack', 'wellness-pack');
        $this->attempts()->claim($key, (string) session_id(), '2026-08-25T10:00:00+00:00');
        $this->attempts()->markSent($key, '2026-08-25T10:00:00+00:00');
        $this->attempts()->recordOutcome($key, [
            'state' => PlacementOutcome::DECLINED,
            'reference' => null,
            'reason' => 'Declined',
            'raw_status' => '5',
        ]);

        $result = $this->offered()->accept();

        self::assertSame([], $this->transport->requests, 'the provider was never reached');
        self::assertSame([self::CHECKOUT_REFERENCE], $this->journey->placedOrders);
        self::assertSame(JourneyState::UPSELL_CHARGE_DECLINED, $this->journey->upsellOutcomes['wellness-pack']);
        self::assertSame(1, $this->journey->upsellCursor);
        self::assertFalse($result->outcome?->isPlaced());
    }

    public function testAnAnswerToAnUnquotedOfferSaysNothingAboutThePrice(): void
    {
        // The refusal is right; the sentence has to be. A journey write that
        // failed between the render and the click leaves no quote, and the
        // buyer is looking at a correctly-rendered offer whose price did
        // nothing — telling them it changed is telling them something false
        // about their money, and it fires a drift warning for a drift that did
        // not happen.
        $this->queuePlacement('34790');
        $service = $this->service();

        $result = $service->accept();

        self::assertSame(UpsellService::UNQUOTED_NOTICE, $result->notice);
        self::assertNotSame(UpsellService::PRICE_MOVED_NOTICE, $result->notice);
        self::assertSame([], $this->log->eventsNamed('upsell.price_moved'), 'no drift was reported');
        self::assertNotSame([], $this->log->eventsNamed('upsell.unquoted_answer'));
    }

    public function testARealDriftStillReportsItselfAsOne(): void
    {
        // The other half: the branch that does concern money keeps its own
        // wording and its own warning level, so an operator can still tell a
        // repricing deploy from a journey blip.
        $this->queuePlacement('34790');
        $this->service()->view();
        $this->journey->upsellQuotes['wellness-pack'] = 1499;

        $result = $this->service()->accept();

        self::assertSame(UpsellService::PRICE_MOVED_NOTICE, $result->notice);

        $drift = $this->log->eventsNamed('upsell.price_moved');
        self::assertCount(1, $drift);
        // At warning, not info: a repricing deploy landing on a live offer page
        // is something an operator wants to see, and it is how a real drift is
        // told apart from the blip that leaves no quote at all.
        self::assertSame('warning', $drift[0]['level']);
        self::assertSame(1499, $drift[0]['context']['quoted_cents']);
        self::assertSame(899, $drift[0]['context']['live_cents']);
    }
    public function testTheUnresolvedChargeAlertCarriesWhatASweepNeeds(): void
    {
        // The one line on the one path where money may have moved with nothing
        // local recording it. An earlier version of this event elsewhere in the
        // codebase carried a prose reason and no identifiers, so a sweep that
        // found it could not act on it — and deleting the line entirely left
        // the suite green. Both halves are pinned here: that it fires at error,
        // and that it names the row, the session, the money and the reference.
        $this->transport->queue(0, '');

        $this->offered()->accept();

        $lines = $this->log->eventsNamed('upsell.attempt_unresolved');
        self::assertCount(1, $lines, 'fired, at the name a sweep greps for');
        self::assertSame('error', $lines[0]['level'], 'and at the level that pages someone');

        $context = $lines[0]['context'];
        self::assertSame($this->keyFor('wellness-pack', 'wellness-pack'), $context['idempotency_key']);
        self::assertSame(self::SESSION_UUID, $context['session_uuid'], 'the identifier that joins to a table');
        self::assertSame(899, $context['amount_cents'], 'the money at risk decides who looks and when');
        self::assertArrayHasKey('reference', $context);
    }
    public function testTheBuyersOwnDeclineIsNeverRecordedAsAChargeDecline(): void
    {
        // The absence nothing else covers: `UPSELL_DECLINED` and
        // `UPSELL_CHARGE_DECLINED` are two different facts, and an operator
        // reading the trail has to be able to tell a preference from a
        // failure. A single "declined" spelling would make every refused card
        // look like a buyer who said no.
        $service = $this->service();
        $service->view();
        $service->decline();

        // The queue advanced, so the buyer is sent back to this page and the
        // next offer renders — which is what quotes it. Accepting without that
        // hop is a POST answering an offer that was never put on screen.
        $service->view();
        $this->transport->queueFixture('vrio-order-declined.json');
        $service->accept();

        self::assertSame([
            'wellness-pack' => JourneyState::UPSELL_DECLINED,
            'sleep-kit' => JourneyState::UPSELL_CHARGE_DECLINED,
        ], $this->journey->upsellOutcomes);
    }

    public function testNoHandleAndNoBuyerDetailEverReachesTheOperatorLog(): void
    {
        // The absence a mutation cannot find: the handle is a charge authority,
        // and every line this flow writes goes to a durable file. The buyer's
        // own contact details are `[20.6]` personal data on the same path.
        $this->queuePlacement('34790');
        $service = $this->service();
        $service->view();
        $service->accept();
        $this->transport->queueFixture('vrio-order-declined.json');
        $service->accept();

        $contents = $this->log->contents();
        foreach (['16815', '13996', 'buyer@example.com', '2125551234', '350 5th Avenue'] as $secret) {
            self::assertStringNotContainsString($secret, $contents, $secret . ' reached a durable log file');
        }
    }

    // ---------------------------------------------------------------- setup

    /**
     * A service whose current offer has already been rendered.
     *
     * The realistic precondition for an acceptance, and now a required one:
     * rendering freezes the price the buyer is shown, and the accept path
     * refuses to charge an offer it cannot prove was quoted. A test that
     * accepts without rendering is testing a POST that arrived with no GET
     * before it, which is its own case rather than the ordinary one.
     */
    private function offered(
        int $rateLimit = 8,
        ?OrderRecorder $orders = null,
        ?CheckoutEventReporter $events = null,
        ?CheckoutAttemptRepository $attempts = null,
        ?\Closure $clock = null,
    ): UpsellService {
        $service = $this->service($rateLimit, $orders, $events, $attempts, $clock);
        $service->view();

        return $service;
    }

    private function service(
        int $rateLimit = 8,
        ?OrderRecorder $orders = null,
        ?CheckoutEventReporter $events = null,
        ?CheckoutAttemptRepository $attempts = null,
        ?\Closure $clock = null,
    ): UpsellService {
        return $this->build($this->journeys, $rateLimit, $orders, $events, $attempts, $clock);
    }

    /** A store that never loaded a session, which is what a visitor who typed the URL looks like. */
    private function serviceWithoutAJourney(): UpsellService
    {
        return $this->build(
            new JourneyStore(new SessionRepository(fn (): \PDO => $this->pdo)),
            8,
            null,
            null,
            null,
            null,
        );
    }

    private function build(
        JourneyStore $journeys,
        int $rateLimit,
        ?OrderRecorder $orders,
        ?CheckoutEventReporter $events,
        ?CheckoutAttemptRepository $attempts,
        ?\Closure $clock,
    ): UpsellService {
        $config = Config::load(dirname(__DIR__, 2) . '/config', $_ENV);

        return new UpsellService(
            journeys: $journeys,
            queue: new UpsellQueue($this->upsells(), $this->log->log),
            adapter: new VrioAdapter(
                new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
                new VrioApiFactory($this->transport),
                $this->log->log,
                shippingProfileId: 1,
            ),
            attempts: $attempts ?? $this->attempts(),
            limiter: new DatabaseRateLimiter(
                new RateLimitRepository(fn (): \PDO => $this->pdo, 'sqlite'),
                ['upsell.accept' => ['limit' => $rateLimit, 'window_seconds' => 300]],
                $this->log->log,
            ),
            orders: $orders ?? $this->recorder,
            events: $events ?? $this->reporter,
            guard: new PostChargeGuard($this->log->log),
            flow: FlowDefinition::fromConfig($config),
            config: $config,
            log: $this->log->log,
            clock: $clock,
        );
    }

    private function attempts(): CheckoutAttemptRepository
    {
        return new CheckoutAttemptRepository(fn (): \PDO => $this->pdo, 'sqlite');
    }

    private function keyFor(string $upsellKey, string $slug): string
    {
        return IdempotencyKey::forUpsell(
            self::SESSION_UUID,
            (string) session_id(),
            $upsellKey,
            $slug,
            null,
        );
    }

    private function upsells(): Upsells
    {
        return Upsells::fromConfig(
            ['upsells' => [
                'wellness-pack' => [
                    'slug' => 'wellness-pack',
                    'offer_after' => ['tirzepatide'],
                    'eyebrow' => 'Wait! Your Exclusive Offer!',
                    'headline' => 'Enhance Your Wellness Journey',
                    'body' => 'Optimize your daily routine.',
                    'bullets' => ['Immune Support', 'Energy Boost'],
                    'image' => '/assets/img/upsell.png',
                    'price_cents_override' => 899,
                ],
                'sleep-kit' => ['slug' => 'sleep-kit', 'offer_after' => ['tirzepatide']],
            ]],
            new FakeCatalog([
                'wellness-pack' => [
                    'slug' => 'wellness-pack',
                    'name' => 'Wellness Pack',
                    'kind' => 'otc',
                    'price_cents' => 1299,
                    'variants' => [
                        ['id' => 'wp-1m', 'price_cents' => 1299, 'provider' => ['offer_id' => '412', 'product_id' => '3600']],
                    ],
                ],
                'sleep-kit' => [
                    'slug' => 'sleep-kit',
                    'name' => 'Sleep Kit',
                    'kind' => 'otc',
                    'price_cents' => 2400,
                    'variants' => [
                        ['id' => 'sk-1', 'price_cents' => 2400, 'provider' => ['offer_id' => '413', 'product_id' => '3601']],
                    ],
                ],
            ]),
            $this->log->log,
        );
    }

    /**
     * One approved placement, built from the recorded harvest response so that
     * every field but the three this case cares about is the provider's own.
     *
     * `$handle` of null strips both halves of the pair, which is the shape a
     * response that simply did not repeat it has.
     *
     * @param array{customer_id: string, customer_card_id: string}|null $handle
     */
    private function queuePlacement(string $reference, ?array $handle = ['customer_id' => '13996', 'customer_card_id' => '16815'], int $totalCents = 899): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/vrio-handle-harvest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['response']['data'];

        $data['order_id'] = (int) $reference;
        $data['transaction_total'] = number_format($totalCents / 100, 2, '.', '');

        if ($handle === null) {
            unset($data['customer_id'], $data['order']['customer_id'], $data['order']['customer_card_id'], $data['order']['customer_card']);
        } else {
            $data['customer_id'] = (int) $handle['customer_id'];
            $data['order']['customer_id'] = (int) $handle['customer_id'];
            $data['order']['customer_card_id'] = (int) $handle['customer_card_id'];
            unset($data['order']['customer_card']);
        }

        $this->transport->queue(200, (string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * A challenge response: the provider created the order and wants an extra
     * verification step. Neither placed nor declined (`[14.22]`).
     */
    private function queuePendingAction(string $reference): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/vrio-handle-harvest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['response']['data'];

        $data['order_id'] = (int) $reference;
        $data['response_code'] = 101;
        $data['post_data'] = 'https://challenge.example/3ds/' . $reference;

        $this->transport->queue(200, (string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * The recorded refusal of a vault handle — the reachable card-on-file
     * failure, which creates no order at all.
     *
     * The fixture is a `{request, response}` pair rather than a bare envelope,
     * so it cannot go through {@see FakeVrioTransport::queueFixture()}: the
     * transport is handed the provider's raw body, which is the `response.data`
     * node.
     */
    private function queueRefusal(): void
    {
        /** @var array<string, array{response: array<string, mixed>}> $pairs */
        $pairs = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/vrio-cof-refusal.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $first = reset($pairs);
        self::assertIsArray($first);

        $this->transport->queue(200, (string) json_encode($first['response']['data'], JSON_THROW_ON_ERROR));
    }
}

/**
 * The four upsell-relevant reports, captured.
 *
 * A recording class rather than a mock because `[16.7]`'s requirement is a
 * *count* as much as a payload — once per upsell, however many times the page
 * is rendered — and a count is what an expectation on a mock hides behind a
 * failure message.
 */
final class RecordingUpsellReporter implements CheckoutEventReporter
{
    /** @var list<array{0: string, 1: string}> */
    public array $offered = [];

    /** @var list<array{0: string, 1: string}> */
    public array $accepted = [];

    /** @var list<array{0: string, 1: string}> */
    public array $declined = [];

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
    }

    /** @param list<string> $orderReferences */
    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
    }

    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
    {
    }

    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
    {
        $this->offered[] = [$slug, $name];
    }

    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
    {
        $this->accepted[] = [$slug, $name];
    }

    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
    {
        $this->declined[] = [$slug, $name];
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
}

/** What the local order row would have said, without a table to say it in. */
final class RecordingUpsellRecorder implements OrderRecorder
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @param list<ConsentRecord> $consents */
    public function record(
        OrderEnvelope $order,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
        array $consents,
        string $providerCategory,
        bool $isUpsell = false,
    ): ?int {
        $this->rows[] = [
            'reference' => $outcome->reference,
            'status' => $outcome->state,
            'amount_cents' => $order->totalCents,
            'discount_cents' => $order->discountCents,
            'promotion_code' => $order->promotionCode,
            'anchor_slug' => $order->anchorSlug,
            'payment_method' => $credential->kind,
            'is_upsell' => $isUpsell,
            'consents' => $consents,
            'lines' => $order->lines,
        ];

        return count($this->rows);
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->rows === [] ? null : $this->rows[count($this->rows) - 1];
    }
}

/** The order table gone the instant the card was handed over. */
final class ThrowingUpsellRecorder implements OrderRecorder
{
    /** @param list<ConsentRecord> $consents */
    public function record(
        OrderEnvelope $order,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
        array $consents,
        string $providerCategory,
        bool $isUpsell = false,
    ): ?int {
        throw new \RuntimeException('the orders table went away after the charge');
    }
}

/** Reporting gone with it, which `[20.1]` says must not reach the buyer. */
final class ThrowingUpsellReporter implements CheckoutEventReporter
{
    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
        throw new \RuntimeException('reporting is down');
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
        throw new \RuntimeException('reporting is down');
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
        throw new \RuntimeException('reporting is down');
    }

    /** @param list<string> $orderReferences */
    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
        throw new \RuntimeException('reporting is down');
    }

    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
    {
        throw new \RuntimeException('reporting is down');
    }

    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
    {
        throw new \RuntimeException('reporting is down');
    }

    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
    {
        throw new \RuntimeException('reporting is down');
    }

    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
    {
        throw new \RuntimeException('reporting is down');
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
        throw new \RuntimeException("reporting is down");
    }
}

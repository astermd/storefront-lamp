<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Journey;

use AsterMD\Sdk\Support\QueryParamCipher;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Journey\SessionOptions;
use AsterMD\Storefront\Journey\SessionResolver;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SessionResolverTest extends TestCase
{
    use TempDatabase;

    private const KEY = '00112233445566778899aabbccddeeff';
    private const UUID = 'sess-1234567890abcdef';
    private const OTHER_UUID = 'sess-fedcba0987654321';
    private const STALE = 'sess-stale-1234567890ab';
    private const FRESH = 'sess-fresh-1234567890ab';

    /** @var array<string, mixed> a cart in {@see \AsterMD\Storefront\Domain\Cart}'s serialised shape */
    private const CART = ['lines' => [['sku' => 'tirz-5mg', 'quantity' => 1, 'unit_price' => 24900]], 'territory' => 'CA'];

    /** @var array<string, mixed> a first touch already captured on the stale journey */
    private const ATTRIBUTION = ['params' => ['affiliate_id' => '4412'], 'referrer' => null, 'derived' => []];

    /** @var array<string, array<string, mixed>> a questionnaire already answered on the stale journey */
    private const ANSWERS = ['tf-weight-loss' => ['first_name' => 'Ada', 'bmi_height' => '70', 'bmi_weight' => '120']];

    /** @var array<string, string> checkout details already typed on the stale journey */
    private const BUYER = ['first_name' => 'Ada', 'email' => 'ada@example.com', 'address_line_1' => '12 Analytical Way'];

    /** @var array{code: string, discount_cents: int} a discount code already applied on the stale journey */
    private const PROMOTION = ['code' => 'SAVE10', 'discount_cents' => 1200];

    /** @var list<array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string}> */
    private const CONSENTS = [[
        'key' => 'telehealth',
        'granted' => true,
        'copy_version' => 'v3',
        'copy_shown' => 'I consent to a telehealth consultation.',
        'at' => '2026-08-25T09:58:00+00:00',
    ]];

    /** @var array{status: string, at: string, basis: ?string, check: ?string} */
    private const VERDICT = [
        'status' => 'passed',
        'at' => '2026-08-25T09:59:00+00:00',
        'basis' => 'identity_document',
        'check' => 'chk-77',
    ];

    /** @var array<string, mixed> the retained receipt, in the shape the completion step stores */
    private const RECEIPT = ['reference' => '34788', 'total_cents' => 24500];

    /**
     * A journey carrying a distinctive, non-default value in **every** durable
     * field, so the re-mint's decision about each one is observable.
     *
     * Ordered as {@see JourneyState::toArray()} orders them, which is what
     * lets the enumeration test compare key lists and catch a field added
     * later.
     *
     * @var array<string, mixed>
     */
    private const EVERY_DURABLE_FIELD = [
        'furthest_step' => 'receipt',
        'emr_events' => ['intake_initiated', 'checkout_initiated'],
        'reconciled' => false,
        'read_model_shape' => SessionResolver::READ_MODEL_SHAPE,
        'cart' => self::CART,
        'cart_mirrored' => true,
        'form_answers' => self::ANSWERS,
        'form_status' => ['tf-weight-loss' => 'completed'],
        'disqualified_rule' => 'bmi_low_hard_stop_notice',
        'disqualified_teleform' => 'tf-weight-loss',
        'buyer' => self::BUYER,
        'promotion' => self::PROMOTION,
        'promotion_quoted_against' => 'digest-of-a-cart-that-has-since-changed',
        'accepted_bumps' => ['anti-nausea-kit'],
        'consents' => self::CONSENTS,
        'placed_orders' => ['34788'],
        'payment_handle' => ['kind' => 'stored_instrument', 'handle' => ['customer_id' => '99001', 'customer_card_id' => '44120']],
        'upsell_queue' => ['sleep-support', 'vitamin-b12'],
        'upsell_cursor' => 1,
        'upsell_outcomes' => ['sleep-support' => 'declined'],
        'upsell_quotes' => ['vitamin-b12' => 1999],
        'verification' => self::VERDICT,
        'receipt' => self::RECEIPT,
        'completed_at' => '2026-08-25T10:01:00+00:00',
        'session_retired' => true,
    ];

    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/session-resolver-log-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testMintsASessionOnLandingAndCarriesFirstTouchAttributionIntoTheCreateCall(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::UUID);
        [$resolver, $store, $events] = $this->resolver($gateway);

        $resolution = $resolver->resolve($this->request('/?aff_id=4412&utm_source=partner&utm_medium=cpc', [
            'Referer' => 'https://partner.example/',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148',
            'X-Forwarded-For' => '203.0.113.7',
        ]), mayCreate: true);

        self::assertSame(self::UUID, $resolution->sessionUuid);
        self::assertTrue($resolution->issueCookie);

        self::assertCount(1, $gateway->createCalls);
        self::assertSame('203.0.113.7', $gateway->createCalls[0]['ip']);
        self::assertStringContainsString('iPhone', (string) $gateway->createCalls[0]['ua']);
        self::assertSame('https://partner.example/', $gateway->createCalls[0]['data']['referrer']);
        self::assertSame(['source' => 'partner', 'medium' => 'cpc'], $gateway->createCalls[0]['data']['utm']);

        $state = $store->state();
        self::assertSame('4412', $state?->attribution?->get('affiliate_id'));
        self::assertTrue($state?->reconciled);
        self::assertSame(['session_created'], $events->namesFor(self::UUID));
    }

    public function testAFailedMintDegradesToNoSessionAndNeverInventsOne(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: null);
        [$resolver, $store] = $this->resolver($gateway);

        $resolution = $resolver->resolve($this->request('/'), mayCreate: true);

        self::assertNull($resolution->sessionUuid);
        self::assertFalse($resolution->issueCookie);
        self::assertNull($store->state());
        self::assertStringContainsString('session.create_failed', (string) file_get_contents($this->logFile));
    }

    public function testNoSessionIsMintedWhenCreationIsNotAllowed(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::UUID);
        [$resolver] = $this->resolver($gateway);

        self::assertNull($resolver->resolve($this->request('/'), mayCreate: false)->sessionUuid);
        self::assertSame([], $gateway->createCalls);
    }

    public function testAnExistingCookieIsReusedAndNoSessionIsMinted(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => 'opp-5', 'events' => ['visit_page']]]);
        [$resolver, $store] = $this->resolver($gateway);

        $resolution = $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);

        self::assertSame(self::UUID, $resolution->sessionUuid);
        self::assertFalse($resolution->issueCookie);
        self::assertSame([], $gateway->createCalls);
        self::assertSame('opp-5', $store->state()?->opportunityId);
        self::assertSame(['visit_page'], $store->state()?->emrEvents);
    }

    public function testReconciliationIsASingleReadPerJourney(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => 'opp-5', 'events' => ['visit_page']]]);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        $request = $this->request('/')->withCookieParams(['amd_session' => self::UUID]);

        $resolver->resolve($request, mayCreate: true);
        $store->flush();

        // Fresh store = a genuinely later request, so this proves the read is not
        // repeated per request rather than merely cached within one.
        [$nextResolver] = $this->resolver($gateway, $pdo);
        $nextResolver->resolve($request, mayCreate: true);

        self::assertSame([self::UUID], $gateway->viewCalls);
    }

    public function testAFailedReconciliationReadIsRetriedOnTheNextRequest(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => 'opp-5', 'events' => []]], failingViews: [self::UUID]);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        $request = $this->request('/')->withCookieParams(['amd_session' => self::UUID]);

        $resolver->resolve($request, mayCreate: true);
        self::assertFalse($store->state()?->reconciled);
        $store->flush();

        [$nextResolver] = $this->resolver($gateway, $pdo);
        $nextResolver->resolve($request, mayCreate: true);

        self::assertCount(2, $gateway->viewCalls);
    }

    public function testAResumeLinkIsAuthoritativeOverTheCurrentCookieAndReadsOnlyOnce(): void
    {
        $gateway = new FakeSessionGateway([self::OTHER_UUID => ['opportunity_id' => 'opp-9', 'events' => ['intake_initiated']]]);
        [$resolver, $store, $events] = $this->resolver($gateway);

        $resolution = $resolver->resolve(
            $this->request('/intake/?amd_session=' . self::OTHER_UUID)->withCookieParams(['amd_session' => self::UUID]),
            mayCreate: true,
        );

        self::assertSame(self::OTHER_UUID, $resolution->sessionUuid);
        self::assertTrue($resolution->issueCookie);
        self::assertSame([self::OTHER_UUID], $gateway->viewCalls);
        self::assertSame('opp-9', $store->state()?->opportunityId);
        self::assertTrue($store->state()?->hasEmrEvent('intake_initiated'));
        self::assertContains('session_adopted', $events->namesFor(self::OTHER_UUID));
    }

    public function testAResumeLinkRecoversStateEvenWhenTheLocalRowIsAlreadyReconciled(): void
    {
        // The case a resume link exists for: this storefront minted the session
        // (so its row is already `reconciled`), the journey moved on server-side,
        // and the visitor comes back through a retargeting link. The snapshot the
        // adoption just fetched is the only thing that can recover the linked
        // opportunity and the events already recorded (`[4.12]`, `[4.13]`).
        $gateway = new FakeSessionGateway(
            [self::UUID => ['opportunity_id' => 'opp-9', 'events' => ['intake_initiated']]],
            mintUuid: self::UUID,
        );
        [$resolver, $store, , $pdo] = $this->resolver($gateway);

        $resolver->resolve($this->request('/'), mayCreate: true);
        self::assertTrue($store->state()?->reconciled);
        self::assertNull($store->state()?->opportunityId);
        $store->flush();

        [$resumeResolver, $resumeStore] = $this->resolver($gateway, $pdo);
        $resumeResolver->resolve($this->request('/intake/?amd_session=' . self::UUID), mayCreate: true);

        self::assertSame('opp-9', $resumeStore->state()?->opportunityId);
        self::assertSame(['intake_initiated'], $resumeStore->state()?->emrEvents);
        self::assertCount(1, $gateway->viewCalls);
    }

    public function testTheSameResumeUrlResolvedTwiceReadsTheEmrOnlyOnce(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => 'opp-9', 'events' => ['intake_initiated']]]);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        $request = $this->request('/intake/?amd_session=' . self::UUID);

        $resolver->resolve($request, mayCreate: true);
        $store->flush();

        // Fresh store = a genuinely later request over the same durable row.
        [$nextResolver, $nextStore] = $this->resolver($gateway, $pdo);
        $nextResolver->resolve($request, mayCreate: true);

        self::assertSame([self::UUID], $gateway->viewCalls);
        self::assertSame('opp-9', $nextStore->state()?->opportunityId);
    }

    public function testACookieTheEmrDoesNotRecogniseIsDiscardedAndReminted(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);   // knows no sessions at all
        [$resolver, , , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions);

        $resolution = $resolver->resolve(
            $this->request('/')->withCookieParams(['amd_session' => self::STALE]),
            mayCreate: true,
        );

        self::assertSame(self::FRESH, $resolution->sessionUuid);
        self::assertTrue($resolution->issueCookie);

        // Read back from the database rather than from the store: the
        // transplanted opportunity is written by the insert itself, and a
        // state-only assertion would pass even if it were never persisted.
        $row = $sessions->find(self::FRESH);
        self::assertNotNull($row);
        self::assertSame(self::CART, $row['journey_state']['cart']);
        self::assertSame('intake', $row['journey_state']['furthest_step']);
        self::assertSame('4412', $row['attribution']['params']['affiliate_id']);
        self::assertSame('opp-1', $row['opportunity_id']);
    }

    public function testAremintedJourneyStartsWithNoRecordedEmrEventsAndNoMirrorFlag(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);
        [$resolver, , , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions);

        $before = $sessions->find(self::STALE)['journey_state'];
        self::assertSame(['intake_initiated'], $before['emr_events']);
        self::assertTrue($before['cart_mirrored']);
        self::assertSame(SessionResolver::READ_MODEL_SHAPE, $before['read_model_shape']);

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::STALE]), mayCreate: true);

        $row = $sessions->find(self::FRESH);
        self::assertSame([], $row['journey_state']['emr_events']);
        self::assertFalse($row['journey_state']['cart_mirrored']);

        // Nothing was read for the new session, so no generation is stamped
        // against it — the same shape a freshly minted session carries.
        self::assertNull($row['journey_state']['read_model_shape']);
    }

    public function testTheQuestionnaireSurvivesARemintAndCarriesItsVerdictWithIt(): void
    {
        // `[21.5]`: a visitor whose cookie the EMR has stopped recognising has
        // not stopped being the person who answered the form, and asking them
        // to retype a medical questionnaire is the worst thing this path could
        // do to them. The verdict travels with the answers on purpose —
        // keeping what caused a hard stop while leaving the stop behind would
        // silently re-open a funnel the server closed (`[10.44a]`).
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);
        [$resolver, , , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions);

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::STALE]), mayCreate: true);

        // Read back from the database rather than from the store: the
        // transplant is written by the insert itself, so a state-only
        // assertion would pass even if it were never persisted.
        $row = $sessions->find(self::FRESH);
        self::assertNotNull($row);
        self::assertSame(self::ANSWERS, $row['journey_state']['form_answers']);
        self::assertSame(['tf-weight-loss' => 'in_progress'], $row['journey_state']['form_status']);
        self::assertSame('bmi_low_hard_stop_notice', $row['journey_state']['disqualified_rule']);
        self::assertSame('tf-weight-loss', $row['journey_state']['disqualified_teleform']);

        // The rule the questionnaire must not drag back in with it:
        // the recorded events describe what the *old* session fired, so
        // carrying them would suppress the "initiated" events on a session
        // where nothing has fired yet (`[4.13]`), and the new session has no
        // remote cart, so its first mirror has to be a create (`[7.10]`).
        self::assertSame([], $row['journey_state']['emr_events']);
        self::assertFalse($row['journey_state']['cart_mirrored']);
    }

    public function testAReMintKeepsTheBuyerDetailsAndTheAppliedPromotion(): void
    {
        // This list has gone stale once before: a visitor would have retyped a
        // 56-question form. The same staleness here would make them retype
        // their address and re-enter their discount code.
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);
        [$resolver, , , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions);

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::STALE]), mayCreate: true);

        // Read back from the database rather than from the store, for the same
        // reason the questionnaire test does.
        $row = $sessions->find(self::FRESH);
        self::assertNotNull($row);
        self::assertSame(self::BUYER, $row['journey_state']['buyer']);
        self::assertSame(self::PROMOTION, $row['journey_state']['promotion']);
        self::assertSame(['anti-nausea-kit'], $row['journey_state']['accepted_bumps']);

        // The card is not in this list and never will be: it lives for one
        // request and is excluded from what gets persisted, so a re-mint has
        // nothing to carry and the `sessions` table has no card to leak. The
        // reference handle that stands in for it is not carried either, which
        // the enumeration below states as a decision rather than leaving to
        // this test to imply.
        self::assertArrayNotHasKey('payment_credential', $row['journey_state']);
    }

    /**
     * Every durable journey field, and the decision the re-mint makes about it.
     *
     * Spelled as one whole-state comparison on purpose. The individual
     * transplant assertions above cover the fields somebody thought to name,
     * and that is exactly how the fire-once completion guard came to be left
     * behind while the placed-order list that lets a journey back onto the
     * receipt travelled: there was no failing assertion, because there was no
     * assertion. A field added to {@see JourneyState} after this test is
     * written cannot join the not-carried side by omission — it fails here
     * until somebody decides.
     */
    public function testEveryDurableFieldHasADeliberateRemintDecision(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);
        [$resolver, , , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $sessions->insert(self::STALE, self::EVERY_DURABLE_FIELD, self::ATTRIBUTION, 'opp-1');

        // The seed is the enumeration's other half: a default value would let a
        // carried field and a dropped one look the same.
        self::assertSame(
            array_keys((new JourneyState())->toArray()),
            array_keys(self::EVERY_DURABLE_FIELD),
            'the seed below must name every durable field, or the comparison cannot tell carried from defaulted',
        );

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::STALE]), mayCreate: true);

        $row = $sessions->find(self::FRESH);
        self::assertNotNull($row);

        self::assertSame(
            [
                // Carried: where the buyer got to, and what they put in a cart.
                'furthest_step' => 'receipt',
                // Not carried: these describe what the *old* session fired, and
                // carrying them would suppress the "initiated" events on a
                // session where nothing has fired yet (`[4.13]`).
                'emr_events' => [],
                // Set, not carried: the mint is proof the EMR knows this one.
                'reconciled' => true,
                // Not carried: nothing was read for the new identifier, so
                // there is no generation to stamp (`[4.12]`).
                'read_model_shape' => null,
                'cart' => self::CART,
                // Not carried: the new session has no remote cart, so its first
                // mirror has to be a create.
                'cart_mirrored' => false,
                // Carried: making a visitor retype a medical questionnaire is
                // the worst thing this path could do to them, and the verdict
                // travels with the answers so a closed funnel stays closed.
                'form_answers' => self::ANSWERS,
                'form_status' => ['tf-weight-loss' => 'completed'],
                'disqualified_rule' => 'bmi_low_hard_stop_notice',
                'disqualified_teleform' => 'tf-weight-loss',
                // Carried: what the buyer typed and chose at checkout.
                'buyer' => self::BUYER,
                'promotion' => self::PROMOTION,
                // Not carried, deliberately: a discount is an answer about one
                // set of priced lines, and a digest that matches nothing forces
                // the checkout to re-quote rather than show a figure with no
                // cart attached to it (`[14.6b]`).
                'promotion_quoted_against' => null,
                'accepted_bumps' => ['anti-nausea-kit'],
                'consents' => self::CONSENTS,
                // Carried: the only surviving proof this journey bought
                // something, and what the `order_placed` precondition reads.
                'placed_orders' => ['34788'],
                // Not carried: no handle means the remaining offers are skipped
                // in silence (`[15.13]`), which costs one optional add-on —
                // where carrying it without the queue position would re-offer
                // something already charged.
                'payment_handle' => null,
                'upsell_queue' => [],
                'upsell_cursor' => 0,
                // Carried: a declined offer leaves no row anywhere else
                // (`[16.11]`).
                'upsell_outcomes' => ['sleep-support' => 'declined'],
                // Not carried: the render rewrites the quote before the next
                // click can be answered, so a lost one costs nothing.
                'upsell_quotes' => [],
                // Carried: the identity verdict is the questionnaire's verdict
                // one rule later (`[22.20]`).
                'verification' => self::VERDICT,
                // Carried: without these three a re-minted journey is admitted
                // to the receipt by `placed_orders` and no longer remembers
                // having been there, so the completion actions fire a second
                // time — under the *new* session, which the EMR keys a
                // treatment record on (`[17.1]`, `[17.2]`, `[4.17]`, `[4.18]`).
                'receipt' => self::RECEIPT,
                'completed_at' => '2026-08-25T10:01:00+00:00',
                'session_retired' => true,
            ],
            $row['journey_state'],
            'a durable field with no decision behind it defaults to lost, which is how the completion guard was left behind',
        );

        // The two durable fields that live in their own columns rather than in
        // the blob, and so cannot be part of the comparison above.
        self::assertSame('4412', $row['attribution']['params']['affiliate_id']);
        self::assertSame('opp-1', $row['opportunity_id']);
    }

    public function testTheRemintIsAudited(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);
        [$resolver, , $events, $pdo] = $this->resolver($gateway);
        $this->stale(new SessionRepository(static fn (): \PDO => $pdo));

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::STALE]), mayCreate: true);

        self::assertContains('session_reminted', $events->namesFor(self::FRESH));
    }

    public function testTheJourneyStoreFollowsTheNewSessionSoTheSaveBackWritesTheRightRow(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        $this->stale(new SessionRepository(static fn (): \PDO => $pdo));

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::STALE]), mayCreate: true);

        self::assertSame(self::FRESH, $store->sessionUuid());
    }

    public function testAPostRequestCannotRemintAndLeavesTheJourneyUnreconciledSoTheNextPageViewRetries(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions);

        $resolution = $resolver->resolve(
            $this->request('/')->withCookieParams(['amd_session' => self::STALE]),
            mayCreate: false,
        );

        self::assertSame(self::STALE, $resolution->sessionUuid);
        self::assertSame([], $gateway->createCalls);

        // The assertion that matters: stamping reconciled here would make the
        // stale cookie permanent, which is the bug this path exists to fix.
        self::assertFalse($store->state()?->reconciled);
        $store->flush();
        self::assertFalse($sessions->find(self::STALE)['journey_state']['reconciled']);
    }

    public function testAFailedRemintLeavesTheVisitorOnTheOldSessionAndRetriesLater(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: null);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions);

        $resolution = $resolver->resolve(
            $this->request('/')->withCookieParams(['amd_session' => self::STALE]),
            mayCreate: true,
        );

        self::assertNull($resolution->sessionUuid);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertFalse($store->state()?->reconciled);
        $store->flush();
        self::assertFalse($sessions->find(self::STALE)['journey_state']['reconciled']);
        self::assertStringContainsString('session.remint_failed', (string) file_get_contents($this->logFile));
    }

    public function testAFailedRemintStillCapturesTheFirstTouchOntoTheSessionTheVisitorKeeps(): void
    {
        // The order this protects (`[5.12]`, `[5.21]`): the affiliate parameter
        // arrives on the same request whose re-mint fails, onto a journey that
        // had captured nothing yet. Dropping it here loses the credit for the
        // whole journey, because a later visit can no longer be the first touch.
        $gateway = new FakeSessionGateway(mintUuid: null);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions, attribution: null);

        $resolver->resolve(
            $this->request('/?aff_id=4412')->withCookieParams(['amd_session' => self::STALE]),
            mayCreate: true,
        );

        self::assertSame('4412', $store->state()?->attribution?->get('affiliate_id'));
        $store->flush();
        self::assertSame('4412', $sessions->find(self::STALE)['attribution']['params']['affiliate_id']);
    }

    public function testAMintThatReturnsTheIdentifierJustRejectedIsTreatedAsAFailure(): void
    {
        // Pathological, but unbounded if unguarded: the insert would no-op on
        // conflict and adopt() would fingerprint the transplant away, so the
        // same cookie would be re-issued over the same unreconciled row and
        // every subsequent page view would mint again.
        $gateway = new FakeSessionGateway(mintUuid: self::STALE);
        [$resolver, $store, $events, $pdo] = $this->resolver($gateway);
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $this->stale($sessions);

        $resolution = $resolver->resolve(
            $this->request('/')->withCookieParams(['amd_session' => self::STALE]),
            mayCreate: true,
        );

        self::assertNull($resolution->sessionUuid);
        self::assertFalse($resolution->issueCookie);
        self::assertNotContains('session_reminted', $events->namesFor(self::STALE));
        self::assertFalse($store->state()?->reconciled);
        $store->flush();
        self::assertFalse($sessions->find(self::STALE)['journey_state']['reconciled']);
        self::assertStringContainsString('session.remint_failed', (string) file_get_contents($this->logFile));
    }

    public function testASessionTheEmrDoesRecogniseIsUntouched(): void
    {
        $gateway = new FakeSessionGateway(
            [self::UUID => ['opportunity_id' => 'opp-5', 'events' => ['visit_page']]],
            mintUuid: self::FRESH,
        );
        [$resolver, $store] = $this->resolver($gateway);

        $resolution = $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);

        self::assertSame(self::UUID, $resolution->sessionUuid);
        self::assertFalse($resolution->issueCookie);
        self::assertSame([], $gateway->createCalls);
        self::assertTrue($store->state()?->reconciled);
        self::assertSame(SessionResolver::READ_MODEL_SHAPE, $store->state()?->readModelShape);
    }

    public function testAFailedReadStillDoesNotRemint(): void
    {
        // A read that failed is not an answer: the EMR being unreachable says
        // nothing about whether it knows the session, so discarding the cookie
        // on it would throw away good journeys during an outage.
        $gateway = new FakeSessionGateway(mintUuid: self::FRESH, failingViews: [self::UUID]);
        [$resolver, $store] = $this->resolver($gateway);

        $resolution = $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);

        self::assertSame(self::UUID, $resolution->sessionUuid);
        self::assertSame([], $gateway->createCalls);
        self::assertFalse($store->state()?->reconciled);
        self::assertSame([self::UUID], $gateway->viewCalls);
    }

    public function testAJourneyReconciledUnderAnOlderReadModelIsReReadOnceAndReStamped(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => 'opp-5', 'events' => ['visit_page']]]);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);
        (new SessionRepository(static fn (): \PDO => $pdo))->insert(self::UUID, [
            'emr_events' => [],
            'reconciled' => true,
            'read_model_shape' => SessionResolver::READ_MODEL_SHAPE - 1,
        ], null);
        $request = $this->request('/')->withCookieParams(['amd_session' => self::UUID]);

        $resolver->resolve($request, mayCreate: true);

        self::assertSame('opp-5', $store->state()?->opportunityId);
        self::assertSame(SessionResolver::READ_MODEL_SHAPE, $store->state()?->readModelShape);
        $store->flush();

        [$nextResolver] = $this->resolver($gateway, $pdo);
        $nextResolver->resolve($request, mayCreate: true);

        self::assertSame([self::UUID], $gateway->viewCalls);
    }

    public function testALocallyRecordedEventSurvivesASnapshotTakenBeforeTheEmrWriteLanded(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => null, 'events' => ['visit_page']]]);
        [$resolver, $store] = $this->resolver($gateway);

        // Intake fires an event and records it locally; the reconciliation
        // snapshot that lands afterwards must not erase it.
        $state = $store->load(self::UUID);
        $state->recordEmrEvent('intake_initiated');

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);

        self::assertSame(['intake_initiated', 'visit_page'], $store->state()?->emrEvents);
    }

    public function testARejectedResumeValueIsRecordedWithTheValueThatWasRejected(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::UUID);
        [$resolver, , $events, $pdo] = $this->resolver($gateway);

        $resolver->resolve($this->request('/?amd_session=' . self::OTHER_UUID), mayCreate: true);

        $statement = $pdo->prepare('SELECT payload FROM events WHERE session_uuid = ? AND name = ?');
        $statement->execute([self::UUID, 'session_resume_rejected']);
        self::assertStringContainsString(self::OTHER_UUID, (string) $statement->fetchColumn());
        self::assertContains('session_resume_rejected', $events->namesFor(self::UUID));
    }

    public function testAResumedSessionKeepsTheAttributionItWasOriginallyCapturedWith(): void
    {
        // The order this proves ([5.21]): a journey that started on an affiliate
        // link and comes back through a resume link with no parameters at all
        // must still place its order attributed to that affiliate.
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => null, 'events' => []]]);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);

        $resolver->resolve($this->request('/?aff_id=4412')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);
        $store->flush();

        // A later request: same database, fresh request-scoped store.
        [$resumeResolver, $resumeStore] = $this->resolver($gateway, $pdo);
        $resumeResolver->resolve($this->request('/intake/?amd_session=' . self::UUID), mayCreate: true);

        self::assertSame('4412', $resumeStore->state()?->attribution?->get('affiliate_id'));
    }

    public function testAMalformedResumeValueIsIgnoredSilentlyAndTheCookieStillWins(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => null, 'events' => []]]);
        [$resolver] = $this->resolver($gateway);

        $resolution = $resolver->resolve(
            $this->request('/?amd_session=../../etc/passwd')->withCookieParams(['amd_session' => self::UUID]),
            mayCreate: true,
        );

        self::assertSame(self::UUID, $resolution->sessionUuid);
        self::assertSame([self::UUID], $gateway->viewCalls);
    }

    public function testAnUnknownResumeSessionLandsOnANewSessionRatherThanErroring(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::UUID);
        [$resolver, , $events] = $this->resolver($gateway);

        $resolution = $resolver->resolve($this->request('/?amd_session=' . self::OTHER_UUID), mayCreate: true);

        self::assertSame(self::UUID, $resolution->sessionUuid);
        self::assertContains('session_resume_rejected', $events->namesFor(self::UUID));
    }

    public function testAMalformedResumeValueWithNoCookieStillMintsWithoutRecordingARejection(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::UUID);
        [$resolver, , $events] = $this->resolver($gateway);

        $resolution = $resolver->resolve($this->request('/?amd_session=../../etc/passwd'), mayCreate: true);

        self::assertSame(self::UUID, $resolution->sessionUuid);
        self::assertContains('session_created', $events->namesFor(self::UUID));
        self::assertNotContains('session_resume_rejected', $events->namesFor(self::UUID));
    }

    public function testAttributionIsCapturedOnceAndALaterVisitNeverOverwritesIt(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => null, 'events' => []]]);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);

        $resolver->resolve($this->request('/?aff_id=first')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);
        $store->flush();

        [$nextResolver, $nextStore] = $this->resolver($gateway, $pdo);
        $nextResolver->resolve($this->request('/?aff_id=second')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);
        $nextStore->flush();

        self::assertSame('first', $nextStore->state()?->attribution?->get('affiliate_id'));
    }

    public function testADirectVisitWithNoParametersCountsAsCapturedSoALaterReferrerCannotOverwriteIt(): void
    {
        $gateway = new FakeSessionGateway([self::UUID => ['opportunity_id' => null, 'events' => []]]);
        [$resolver, $store, , $pdo] = $this->resolver($gateway);

        $resolver->resolve($this->request('/')->withCookieParams(['amd_session' => self::UUID]), mayCreate: true);
        $store->flush();

        [$nextResolver, $nextStore] = $this->resolver($gateway, $pdo);
        $nextResolver->resolve(
            $this->request('/')->withCookieParams(['amd_session' => self::UUID])->withHeader('Referer', 'https://blog.example/'),
            mayCreate: true,
        );

        self::assertNull($nextStore->state()?->attribution?->referrer);
    }

    public function testTheEncryptedPayloadWinsOverPlainParametersAndTheWrapperKeyIsNeverStored(): void
    {
        $token = QueryParamCipher::encrypt([['key' => 'aff_id', 'value' => 'encrypted']], self::KEY);
        $gateway = new FakeSessionGateway(mintUuid: self::UUID);
        [$resolver, $store] = $this->resolver($gateway);

        $resolver->resolve($this->request('/?aff_id=plain&_amd=' . urlencode($token)), mayCreate: true);

        $attribution = $store->state()?->attribution;
        self::assertSame('encrypted', $attribution?->get('affiliate_id'));
        self::assertStringNotContainsString('_amd', (string) json_encode($attribution?->toArray()));
    }

    public function testAnUndecryptablePayloadFallsBackToPlainParametersAndLogsForOperators(): void
    {
        $gateway = new FakeSessionGateway(mintUuid: self::UUID);
        [$resolver, $store] = $this->resolver($gateway);

        $resolver->resolve($this->request('/?aff_id=plain&_amd=tampered-garbage'), mayCreate: true);

        self::assertSame('plain', $store->state()?->attribution?->get('affiliate_id'));
        self::assertStringContainsString('amd_decrypt_failed', (string) file_get_contents($this->logFile));
    }

    /**
     * A journey the EMR will not recognise, carrying every field the transplant
     * has to decide about, each at a provably non-default value: a first touch,
     * a cart, a furthest step, a linked opportunity, an already-fired lifecycle
     * event, a mirrored cart, a stamped read-model generation, and a
     * questionnaire that has been answered, marked and judged. A field left at
     * its default could not distinguish carrying it from dropping it.
     *
     * The attribution is nullable so the failed-re-mint path can be exercised
     * on a journey that has not captured one yet, which is the only shape in
     * which that path's own attribution write is observable.
     *
     * @param array<string, mixed>|null $attribution
     */
    private function stale(SessionRepository $sessions, ?array $attribution = self::ATTRIBUTION): void
    {
        $sessions->insert(
            self::STALE,
            [
                'furthest_step' => 'intake',
                'emr_events' => ['intake_initiated'],
                'reconciled' => false,
                'read_model_shape' => SessionResolver::READ_MODEL_SHAPE,
                'cart' => self::CART,
                'cart_mirrored' => true,
                'form_answers' => self::ANSWERS,
                'form_status' => ['tf-weight-loss' => 'in_progress'],
                'disqualified_rule' => 'bmi_low_hard_stop_notice',
                'disqualified_teleform' => 'tf-weight-loss',
                'buyer' => self::BUYER,
                'promotion' => self::PROMOTION,
                'accepted_bumps' => ['anti-nausea-kit'],
                'consents' => [],
                'placed_orders' => [],
            ],
            $attribution,
            'opp-1',
        );
    }

    /** @return array{0: SessionResolver, 1: JourneyStore, 2: EventRepository, 3: \PDO} */
    private function resolver(FakeSessionGateway $gateway, ?\PDO $pdo = null): array
    {
        $pdo ??= $this->tempPdo();
        $sessions = new SessionRepository(static fn (): \PDO => $pdo);
        $events = new EventRepository(static fn (): \PDO => $pdo);
        $journey = new JourneyStore($sessions);
        $options = new SessionOptions(trackingKeys: [self::KEY]);
        $resolver = new SessionResolver($gateway, $sessions, $journey, $events, new OperatorLog($this->logFile), $options);

        return [$resolver, $journey, $events, $pdo];
    }

    private function request(string $target, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $target);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}

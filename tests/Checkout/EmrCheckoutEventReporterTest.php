<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\EmrCheckoutEventReporter;
use AsterMD\Storefront\Checkout\Promotion;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The reporting boundary, driven over a fake PSR-18 transport rather than a
 * fake gateway.
 *
 * The transport is the seam because the thing worth pinning is the JSON that
 * would have gone on the wire: the EMR stores an integer cents value verbatim
 * where it documents a float, so "was the conversion applied" is a question
 * about the request body and about nothing else. A fake gateway asserting on
 * an array the reporter handed it would pass just as happily with the SDK
 * wired up wrongly underneath.
 */
final class EmrCheckoutEventReporterTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private const array TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private const array OK_ROUTES = [
        '/checkout-events/' => [200, ['success' => true, 'data' => ['_id' => '6a8b39941076171b7c0e2f2c']]],
        '/treatments/sync' => [200, ['success' => true, 'data' => ['reference' => 'trt-1']]],
    ];

    private \PDO $pdo;

    private FakeEmrHttpClient $http;

    private CapturedLog $log;

    private string $configDir;

    private string $rootDir;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->pdo = $this->tempPdo();
        $this->log = new CapturedLog();

        $this->configDir = sys_get_temp_dir() . '/checkout-reporter-' . bin2hex(random_bytes(6));
        $this->rootDir = sys_get_temp_dir() . '/checkout-reporter-root-' . bin2hex(random_bytes(6));
        mkdir($this->configDir);
        mkdir($this->rootDir . '/storage/cache', 0775, true);
        $this->registerTempDirForCleanup($this->rootDir);
        file_put_contents(
            $this->configDir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        foreach (glob($this->configDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->configDir);
    }

    public function testTotalsAreSentAsDollarsAndNotAsCents(): void
    {
        // Recorded: the EMR stores `order_value: 12000` verbatim without
        // complaining. Nothing downstream reports a skipped conversion, so
        // this test is the only guard there is.
        $reporter = $this->reporter();

        $reporter->checkoutVisited(self::SESSION, $this->totals(12000, 12000));
        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        $body = $this->bodyOf('/checkout-events/update/');

        // JSON has no way to keep 120.0 apart from 120, and PHP encodes a
        // whole float without its decimal, so what is pinned here is the only
        // difference that survives the wire — and the only one that matters:
        // dollars, not the cents the EMR would have stored verbatim.
        self::assertSame(120, $body['order_value']);
        self::assertSame(108, $body['order_total']);
        self::assertNotSame(12000, $body['order_value'], 'cents reached the EMR');
    }

    public function testAnAmountThatIsNotAWholeNumberOfDollarsKeepsItsDecimal(): void
    {
        // The companion to the case above: 10850 cents is 108.5 dollars, and a
        // value that still carried its own decimal point cannot be mistaken
        // for cents by anything reading the EMR afterwards.
        $reporter = $this->reporter();

        $reporter->checkoutVisited(self::SESSION, $this->totals(12000, 12000));
        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10850), 'card', ['34660']);

        self::assertSame(108.5, $this->bodyOf('/checkout-events/update/')['order_total']);
    }

    public function testTheVisitEventIsRecordedBeforeAnyOrderEvent(): void
    {
        // Recorded: `checkoutEvents()->update()` throws NotFoundException when
        // no `create()` preceded it, so the funnel is opened on the checkout
        // render rather than on submit.
        $reporter = $this->reporter();

        $reporter->checkoutVisited(self::SESSION, $this->totals(12000, 12000));
        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        self::assertSame(
            [
                '/v1/sales/checkout-events/create',
                '/v1/sales/checkout-events/update/' . self::SESSION,
                '/v1/sales/treatments/sync',
            ],
            $this->calls(),
        );
    }

    public function testADeclineIsRecordedLocallyEvenThoughTheEmrKeepsOnlyTheLatestState(): void
    {
        // Recorded: `checkoutEvents` is one record per session, updated in
        // place — an `order_declined` followed by an `order_placed` leaves
        // only the latter. The audit trail `[18.1]` wants lives locally.
        $reporter = $this->reporter();

        $reporter->checkoutVisited(self::SESSION, $this->totals(12000, 12000));
        $reporter->orderDeclined(self::SESSION, $this->totals(12000, 12000), 'card', '34661', 'Failed test transaction');
        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        self::assertSame(
            ['checkout.visited', 'checkout.order_declined', 'checkout.order_placed', 'checkout.treatments_synced'],
            $this->localEvents(),
        );
    }

    public function testTheDeclineReasonIsKeptOnTheLocalRowSoARetriedFailureStaysAnswerable(): void
    {
        $this->reporter()->orderDeclined(self::SESSION, $this->totals(12000, 12000), 'card', '34661', 'Failed test transaction');

        $payload = $this->lastLocalPayload();

        self::assertSame('Failed test transaction', $payload['reason']);
        self::assertSame('34661', $payload['reference']);
    }

    public function testAFailedEmrCallNeverBreaksACompletedOrder(): void
    {
        // `[20.1]`. The money has already moved.
        $reporter = $this->reporter([
            '/checkout-events/' => [500, ['success' => false, 'message' => 'upstream exploded']],
        ]);

        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        self::assertSame('checkout.event_report_failed', $this->log->lastWarning()['event'] ?? null);
        self::assertSame(
            ['checkout.order_placed', 'checkout.treatments_synced'],
            $this->localEvents(),
            'the local trail is written either way, and the sync that did succeed says so',
        );
    }

    public function testTheProvidersOwnMessageNeverReachesTheLog(): void
    {
        // `[20.6]`: the SDK builds the exception message from the EMR's own
        // `message` field, and `OperatorLog::redact()` is key-based and cannot
        // see inside a string.
        $reporter = $this->reporter([
            '/checkout-events/' => [500, ['success' => false, 'message' => 'ada@example.com is not a known patient']],
        ]);

        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        self::assertStringNotContainsString('ada@example.com', $this->log->contents());
    }

    public function testNothingIsReportedForAJourneyWithNoAnalyticsSession(): void
    {
        // No synthetic identifier (`[20.8]`).
        $reporter = $this->reporter();

        $reporter->checkoutVisited(null, $this->totals(12000, 12000));
        $reporter->orderPlaced(null, $this->totals(12000, 10800), 'card', ['34660']);
        $reporter->orderDeclined(null, $this->totals(12000, 12000), 'card', '34661', 'Failed test transaction');

        self::assertSame([], $this->calls());
    }

    public function testTheLocalTrailIsWrittenEvenWhenThereIsNoAnalyticsSessionToFileItUnder(): void
    {
        // The EMR half is what the analytics flag governs; `[18.1]`'s own
        // record is not analytics. With analytics off there is no session uuid
        // at all, so a guard that skipped the local write on a null session
        // meant no `checkout.*` row was ever written on such a deployment --
        // which is precisely the deployment the binding exists to serve.
        $reporter = $this->reporter(reportToEmr: false);

        $reporter->checkoutVisited(null, $this->totals(12000, 12000));
        $reporter->orderDeclined(null, $this->totals(12000, 12000), 'card', '34661', 'Failed test transaction');
        $reporter->orderPlaced(null, $this->totals(12000, 10800), 'card', ['34660']);

        self::assertSame([], $this->calls(), 'and still nothing was invented for the EMR');
        self::assertSame(
            ['checkout.visited', 'checkout.order_declined', 'checkout.order_placed'],
            (new EventRepository(fn (): \PDO => $this->pdo))->namesFor(''),
        );
    }

    public function testACardNumberInADeclineReasonNeverReachesTheLocalEventRow(): void
    {
        // The reason is the provider's own free text, `events.payload` is
        // durable, and `reason` is not a key any redaction list names -- so the
        // value itself is scrubbed on the way in.
        $reporter = $this->reporter(reportToEmr: false);

        $reporter->orderDeclined(self::SESSION, $this->totals(12000, 12000), 'card', '34661', 'Card 4111111100084444 was declined.');

        $payload = $this->lastLocalPayload();

        self::assertStringNotContainsString('4111111100084444', (string) $payload['reason']);
        self::assertStringContainsString(CardScrubber::MASK, (string) $payload['reason']);
    }

    public function testASuccessfulTreatmentSyncStampsTheLocalOrderRow(): void
    {
        // `[18.1]`: the null column is the reconciliation signal, so a sync
        // that worked has to clear it or a later sweep re-syncs a settled order.
        $this->seedOrder('34660');
        $reporter = $this->reporter();

        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        $row = (new OrderRepository(fn (): \PDO => $this->pdo))->findByReference('34660');

        self::assertSame('34660', $row['treatment_reference'] ?? null);
    }

    public function testAFailedTreatmentSyncLeavesTheOrderRowUnstamped(): void
    {
        $this->seedOrder('34660');
        $reporter = $this->reporter(['/treatments/sync' => [500, ['success' => false, 'message' => 'nope']]]);

        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        $row = (new OrderRepository(fn (): \PDO => $this->pdo))->findByReference('34660');

        self::assertNotNull($row);
        self::assertNull($row['treatment_reference'], 'the null column is what a reconciliation sweep looks for');
        self::assertSame('checkout.treatment_sync_failed', $this->log->lastWarning()['event'] ?? null);
    }

    public function testASuccessfulSyncStampsTheEmrTreatmentIdentifierRatherThanTheProviderReference(): void
    {
        // `[19.2]` says the order record carries the EMR treatment reference
        // and `[19.9]` says it is written back on success. Recorded: the id is
        // in the sync response at `data[0]._id`, and until now the response was
        // discarded and the column held the Vrio order id instead -- leaving
        // the EMR's own identifier recorded nowhere in this codebase.
        $this->seedOrder('34660');
        $reporter = $this->reporter(['/treatments/sync' => [200, ['success' => true, 'data' => [
            ['_id' => '6a8c72619f9b46188233413b', 'external_refs' => ['vrio' => ['34660']]],
        ]]]]);

        $reporter->treatmentsSynced(self::SESSION, ['34660']);

        $row = (new OrderRepository(fn (): \PDO => $this->pdo))->findByReference('34660');

        self::assertSame('6a8c72619f9b46188233413b', $row['treatment_reference'] ?? null);
        self::assertNotSame('34660', $row['treatment_reference'] ?? null, 'the provider reference is not the treatment reference');
    }

    public function testATreatmentIdentifierIsNeverReadFromTheTopLevelOfTheSyncEnvelope(): void
    {
        // Recorded: `sync()`'s `data()` is a LIST of records. An earlier
        // recording summary flattened it and documented the record's fields as
        // top-level paths; reading it that way finds nothing and finds it
        // silently. This pins the read against that shape rather than trusting
        // the prose.
        $this->seedOrder('34660');
        $reporter = $this->reporter(['/treatments/sync' => [200, ['success' => true, 'data' => [
            '_id' => 'flattened-read-model',
        ]]]]);

        $reporter->treatmentsSynced(self::SESSION, ['34660']);

        $row = (new OrderRepository(fn (): \PDO => $this->pdo))->findByReference('34660');

        self::assertNotSame('flattened-read-model', $row['treatment_reference'] ?? null);
        self::assertSame('34660', $row['treatment_reference'] ?? null, 'and it falls back rather than leaving the row unstamped');
    }

    public function testASyncWhoseAnswerCarriesNoTreatmentIdentifierStillClearsTheReconciliationSignal(): void
    {
        // The fallback is not cosmetic. A sync the EMR accepted but whose body
        // could not be parsed is still an order the EMR was told about, and
        // leaving the column null would put it back in `[21.8]`'s backlog
        // forever -- which is the trade the previous docblock was defending.
        $this->seedOrder('34660');
        $reporter = $this->reporter(['/treatments/sync' => [200, ['success' => true, 'data' => [
            ['external_refs' => ['vrio' => ['34660']]],
        ]]]]);

        $reporter->treatmentsSynced(self::SESSION, ['34660']);

        $row = (new OrderRepository(fn (): \PDO => $this->pdo))->findByReference('34660');

        self::assertSame('34660', $row['treatment_reference'] ?? null);
    }

    public function testEveryOrderInOneBatchIsStampedWithTheSameTreatmentBecauseTheEmrKeepsOneRecord(): void
    {
        // Recorded: one session's orders accumulate into a single treatment
        // record, `external_refs.vrio` holding every reference. So the column
        // is not unique per order and must not be treated as though it were.
        $this->seedOrder('34787');
        $this->seedOrder('34734');
        $reporter = $this->reporter(['/treatments/sync' => [200, ['success' => true, 'data' => [
            ['_id' => '6a8c72619f9b46188233413b', 'external_refs' => ['vrio' => ['34787', '34734']]],
        ]]]]);

        $reporter->treatmentsSynced(self::SESSION, ['34787', '34734']);

        $orders = new OrderRepository(fn (): \PDO => $this->pdo);

        self::assertSame('6a8c72619f9b46188233413b', $orders->findByReference('34787')['treatment_reference'] ?? null);
        self::assertSame('6a8c72619f9b46188233413b', $orders->findByReference('34734')['treatment_reference'] ?? null);
    }

    public function testATreatmentIdentifierThatIsNotAUsableStringIsIgnoredRatherThanWritten(): void
    {
        // An `_id` that arrived as a number, an object or an empty string is
        // not an identifier, and casting one into the column would record a
        // value no later lookup could use while reporting success.
        $this->seedOrder('34660');
        $reporter = $this->reporter(['/treatments/sync' => [200, ['success' => true, 'data' => [
            ['_id' => ''],
        ]]]]);

        $reporter->treatmentsSynced(self::SESSION, ['34660']);

        $row = (new OrderRepository(fn (): \PDO => $this->pdo))->findByReference('34660');

        self::assertSame('34660', $row['treatment_reference'] ?? null);
    }

    public function testWithTheEmrHalfSwitchedOffTheLocalTrailIsStillWritten(): void
    {
        // The analytics flag governs the EMR, not `[18.1]`'s own record.
        $reporter = $this->reporter(reportToEmr: false);

        $reporter->checkoutVisited(self::SESSION, $this->totals(12000, 12000));
        $reporter->orderPlaced(self::SESSION, $this->totals(12000, 10800), 'card', ['34660']);

        self::assertSame([], $this->calls(), 'nothing reached the EMR');
        self::assertSame(['checkout.visited', 'checkout.order_placed'], $this->localEvents());
    }

    public function testTheTreatmentSyncIsCallableOnItsOwnSoTheReceiptCanBatchTheWholeJourney(): void
    {
        // `[17.2]`: the checkout order plus every accepted upsell, one call.
        // The seam is public because the completion step -- not the placement
        // -- is the caller `[18.1]`'s taxonomy names.
        $this->seedOrder('34788');
        $this->seedOrder('34790');

        $this->reporter()->treatmentsSynced(self::SESSION, ['34788', '34790']);

        self::assertSame(['/v1/sales/treatments/sync'], $this->calls());
        self::assertSame(['34788', '34790'], $this->bodyOf('/treatments/sync')['order_ids'] ?? null);
        self::assertSame('google', $this->bodyOf('/treatments/sync')['utm_source'] ?? null);
        self::assertContains('checkout.treatments_synced', $this->localEvents());
    }

    public function testAnEmptyBatchIsNotSentToASyncThatAnswersFourHundredForIt(): void
    {
        // Recorded: `sync(session, [])` answers 400, "No suborders found in any
        // of the provided orders". Sending it would be a warning line about
        // nothing.
        $this->reporter()->treatmentsSynced(self::SESSION, []);

        self::assertSame([], $this->calls());
        self::assertSame([], $this->localEvents());
    }

    public function testASyncWithNoAnalyticsSessionReportsNothingAndDoesNotThrow(): void
    {
        // The session is the EMR's key for the treatment record, so there is
        // nothing to file the batch under. `[20.8]` forbids inventing one.
        $this->reporter()->treatmentsSynced(null, ['34788']);

        self::assertSame([], $this->calls());
    }

    public function testASyncThatFailsIsLoggedRatherThanRaisedAtABuyerWhoHasPaid(): void
    {
        $reporter = $this->reporter(['/treatments/sync' => [500, ['success' => false]]]);
        $this->seedOrder('34788');

        $reporter->treatmentsSynced(self::SESSION, ['34788']);

        self::assertSame('checkout.treatment_sync_failed', $this->log->lastWarning()['event'] ?? null);
        self::assertNotContains(
            'checkout.treatments_synced',
            $this->localEvents(),
            'a trail row claiming the EMR was told would be false',
        );
    }

    public function testEachUpsellEventCarriesTheProductIdentifierAndNameToTheEmr(): void
    {
        // `[16.7]`: identifier and name, on all three events. The EMR is the
        // clinical system and is entitled to both; what the *local* trail keeps
        // of them is a separate question, asked below.
        $reporter = $this->reporter();

        $reporter->upsellOffered(self::SESSION, 'wellness-pack', 'Wellness Pack');
        $reporter->upsellAccepted(self::SESSION, 'wellness-pack', 'Wellness Pack');
        $reporter->upsellDeclined(self::SESSION, 'sleep-kit', 'Sleep Kit');

        self::assertSame([
            'checkout.upsell_offered',
            'checkout.upsell_accepted',
            'checkout.upsell_declined',
        ], $this->localEvents());

        $body = $this->bodyOf('/checkout-events/update/');
        self::assertSame('upsell_declined', $body['event'] ?? null);
        self::assertSame('sleep-kit', $body['product_id'] ?? null);
        self::assertSame('Sleep Kit', $body['product_name'] ?? null);
    }

    /**
     * The durable half of the same event, which is bound by `[20.14]` rather
     * than by `[16.7]`.
     *
     * A real catalog slug is the drug and its strength, so the offer a journey
     * was shown is as clinically revealing as the product it was shown for.
     * The row keeps a reference to the product and nothing that spells it.
     */
    public function testTheDurableUpsellRowReferencesTheProductWithoutNamingIt(): void
    {
        $this->reporter()->upsellOffered(self::SESSION, '6a8a85-tirzepatide-10mg-ml', 'Tirzepatide 10mg/ml');

        $payload = $this->lastLocalPayload();

        self::assertArrayNotHasKey('slug', $payload);
        self::assertArrayNotHasKey('name', $payload);
        self::assertNotSame('', (string) ($payload['product_ref'] ?? ''));
        self::assertStringNotContainsStringIgnoringCase('tirzepatide', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testAnUpsellEventWithoutASessionStillLeavesTheLocalTrailRow(): void
    {
        // `[18.1]`'s trail is the storefront's own record, and an
        // analytics-off deployment has no session uuid at all.
        $this->reporter()->upsellDeclined(null, 'sleep-kit', 'Sleep Kit');

        self::assertSame([], $this->calls());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    public function testTheFinalFunnelEventCarriesTheChargedTotalAndTheOpportunityReference(): void
    {
        // `[17.5]`: pre-discount value, paid total, payment method, currency,
        // the successful references, and the opportunity reference.
        $this->reporter()->journeyCompleted(
            self::SESSION,
            anyOrderPlaced: true,
            orderValueCents: 12000,
            paidTotalCents: 27000,
            paymentMethod: 'card',
            orderReferences: ['34788', '34790'],
            opportunityId: 'opp-1',
        );

        $body = $this->bodyOf('/checkout-events/update/');

        self::assertSame('order_placed', $body['event'] ?? null);
        self::assertSame(120, $body['order_value'] ?? null);
        self::assertSame(270, $body['order_total'] ?? null, 'the debit, not the quote');
        self::assertSame('card', $body['payment_method'] ?? null);
        self::assertSame('USD', $body['currency'] ?? null);
        self::assertSame(['34788', '34790'], $body['provider_order_id'] ?? null);
        self::assertSame('opp-1', $body['opportunity_id'] ?? null);
        self::assertSame(['checkout.completed'], $this->localEvents());
    }

    public function testTheFinalFunnelEventIsOrderDeclinedWhenNothingSucceeded(): void
    {
        $this->reporter()->journeyCompleted(
            self::SESSION,
            anyOrderPlaced: false,
            orderValueCents: 12000,
            paidTotalCents: 0,
            paymentMethod: null,
            orderReferences: ['34789'],
            opportunityId: null,
        );

        $body = $this->bodyOf('/checkout-events/update/');

        self::assertSame('order_declined', $body['event'] ?? null);
        self::assertSame(['34789'], $body['provider_order_id'] ?? null);
        self::assertArrayNotHasKey('payment_method', $body, 'a method nobody paid by is not invented');
        self::assertArrayNotHasKey('opportunity_id', $body, 'and neither is an opportunity nobody captured');
    }

    public function testTheFinalFunnelEventLeavesItsLocalTrailRowWithNoSession(): void
    {
        $this->reporter()->journeyCompleted(
            null,
            anyOrderPlaced: true,
            orderValueCents: 12000,
            paidTotalCents: 10800,
            paymentMethod: 'card',
            orderReferences: ['34788'],
            opportunityId: null,
        );

        self::assertSame([], $this->calls());
        self::assertSame('order_placed', $this->lastLocalPayload()['event'] ?? null);
        self::assertSame('34788', $this->lastLocalPayload()['references'] ?? null);
    }

    public function testAnOrderBumpDecisionGoesToTheLocalTrailAndNowhereElse(): void
    {
        // `[27.13]`. The EMR's checkout-event vocabulary has no bump case, and
        // a bump is not a second order -- it is a line on the main charge -- so
        // the only record it needs is `[18.1]`'s own.
        $reporter = $this->reporter();

        $reporter->orderBump(self::SESSION, '6a8a87-nad-1000mg', true);
        $reporter->orderBump(self::SESSION, '6a8a87-nad-1000mg', false);

        self::assertSame([], $this->calls(), 'nothing was invented for an event the EMR does not model');
        self::assertSame(['checkout.bump_accepted', 'checkout.bump_withdrawn'], $this->localEvents());

        // `[27.13]`'s question is "which bumps did buyers withdraw", so the row
        // has to identify the bump — and a real slug names the drug, so it
        // cannot identify it by spelling it out.
        $payload = $this->lastLocalPayload();
        self::assertArrayNotHasKey('slug', $payload);
        self::assertNotSame('', (string) ($payload['product_ref'] ?? ''));
        self::assertStringNotContainsStringIgnoringCase(
            '6a8a87-nad-1000mg',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public function testAnOrderBumpWithNoAnalyticsSessionIsStillOnTheTrail(): void
    {
        $this->reporter()->orderBump(null, 'sleep-support', true);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    // ---------------------------------------------------------------- fixtures

    /** @param array<string, array{0: int, 1: array<string, mixed>|string}> $overrides */
    private function reporter(array $overrides = [], bool $reportToEmr = true): EmrCheckoutEventReporter
    {
        $this->http = new FakeEmrHttpClient(self::TOKEN_ROUTE + $overrides + self::OK_ROUTES);

        return new EmrCheckoutEventReporter(
            new ClientFactory(Config::load($this->configDir), $this->rootDir),
            new EventRepository(fn (): \PDO => $this->pdo),
            new OrderRepository(fn (): \PDO => $this->pdo),
            $this->log->log,
            static fn (): ?string => 'google',
            'USD',
            $reportToEmr,
            $this->http,
            static fn (): ?string => 'Mozilla/5.0 (test)',
        );
    }

    private function totals(int $subtotalCents, int $totalCents): Totals
    {
        return Totals::of(
            $subtotalCents,
            $subtotalCents === $totalCents ? null : new Promotion('SAVE', $subtotalCents - $totalCents),
        );
    }

    /** Every path the reporter actually called, token exchanges excluded, in order. */
    private function calls(): array
    {
        $paths = [];

        foreach ($this->http->requests as $request) {
            $path = $request->getUri()->getPath();
            if (!str_contains($path, '/auth/api-credentials/token')) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return array<string, mixed> the decoded body of the last request whose path contains $needle */
    private function bodyOf(string $needle): array
    {
        $matching = array_values(array_filter(
            $this->http->requests,
            static fn ($request): bool => str_contains($request->getUri()->getPath(), $needle),
        ));

        self::assertNotSame([], $matching, sprintf('no request was made to "%s"', $needle));

        return (array) json_decode((string) $matching[count($matching) - 1]->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function localEvents(): array
    {
        return (new EventRepository(fn (): \PDO => $this->pdo))->namesFor(self::SESSION);
    }

    /** @return array<string, mixed> */
    private function lastLocalPayload(): array
    {
        $statement = $this->pdo->query('SELECT payload FROM events ORDER BY id DESC LIMIT 1');
        self::assertNotFalse($statement);

        return (array) json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The `orders` row carries a foreign key to `sessions`, so the journey has to exist first. */
    private function seedOrder(string $reference): void
    {
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);

        (new OrderRepository(fn (): \PDO => $this->pdo))->insert(
            [
                'session_uuid' => self::SESSION,
                'provider_reference' => $reference,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 10800,
                'currency' => 'USD',
                'status' => 'placed',
            ],
            [],
            [],
        );
    }
}

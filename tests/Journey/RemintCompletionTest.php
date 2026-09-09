<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Journey;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The completion actions fire once per checkout (`[17.1]`), including across a
 * session re-mint.
 *
 * Driven through the whole application rather than through
 * {@see \AsterMD\Storefront\Journey\SessionResolver} alone, because the defect
 * this pins is a disagreement between two subsystems that never meet in a unit
 * test: the re-mint decides what a journey carries, and the completion step
 * decides whether it has already run. Carrying the placed-order list — which is
 * what lets a journey onto the receipt at all — without carrying the fire-once
 * guard produces a journey that is admitted to the receipt and no longer
 * remembers having been there.
 *
 * The second firing is not a duplicate analytics event. It is reported under
 * the *freshly minted* session, and the EMR keys a treatment record on the
 * session, so re-syncing one order reference under a second identifier writes a
 * second clinical record for one paid order.
 */
final class RemintCompletionTest extends TestCase
{
    use TempDatabase;

    /** The cookie the buyer arrives with — an identifier the EMR does not know. */
    private const string STALE = '7c2e5d41-9a3b-4f18-8e6d-1b4a7c9e2f55';

    /** What the gateway mints in its place. */
    private const string FRESH = '0f1e2d3c-4b5a-4968-8778-6a5b4c3d2e1f';

    private const string REFERENCE = '34788';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * The end-to-end path, as two requests a real buyer makes.
     *
     * Request one is the redirect off checkout. The EMR read fails on it — a
     * degraded read must not break the page (`[20.2]`) — so the journey
     * completes with `reconciled` still false. Request two is the buyer
     * refreshing the page they were just shown: `RetiredSessionMiddleware`
     * deliberately leaves the cookie alone on the receipt, so the same cookie
     * comes back. This time the read succeeds and answers "the EMR does not
     * know this session", which is the re-mint trigger.
     */
    public function testARefreshedReceiptAfterAremintDoesNotFireTheCompletionActionsAgain(): void
    {
        $pdo = $this->tempPdo();
        $this->seedPaidJourney($pdo);

        $reporter = new CompletionRecorder();

        $first = $this->receipt($this->app($pdo, $reporter, failingViews: [self::STALE]), self::STALE);
        self::assertSame(200, $first->getStatusCode(), 'the receipt must render on the first visit');
        self::assertCount(1, $reporter->journeyCompleted, 'completion fires once on the first visit');

        $second = $this->receipt($this->app($pdo, $reporter), self::STALE);
        self::assertSame(200, $second->getStatusCode(), 'the receipt must still render after the re-mint');

        $rows = $pdo->query('SELECT session_uuid FROM sessions ORDER BY session_uuid')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains(self::FRESH, $rows, 'precondition: the second request re-minted the session');

        // Spelled as a whole-array comparison rather than a count so a failure
        // names the session the duplicate is reported under, which is the part
        // that decides how bad it is.
        self::assertSame(
            [['session' => self::STALE, 'references' => [self::REFERENCE], 'placed' => true]],
            $reporter->journeyCompleted,
            'the completion actions must fire exactly once per checkout (`[17.1]`)',
        );
        self::assertSame(
            [['session' => self::STALE, 'references' => [self::REFERENCE]]],
            $reporter->treatmentsSynced,
            'the treatment sync is deduplicated per session, so a second sync under the fresh session writes a second clinical record (`[17.2]`)',
        );
    }

    /**
     * The same mechanism with the re-mint provoked directly.
     *
     * The completed state is not hand-seeded: `completed_at` and the still-false
     * `reconciled` flag are both produced by the application on the first
     * request, and asserted as preconditions before the second one runs.
     */
    public function testAcompletedJourneyStillKnowsItHasCompletedAfterAremint(): void
    {
        $pdo = $this->tempPdo();
        $this->seedPaidJourney($pdo);

        $warmUp = new CompletionRecorder();
        $this->receipt($this->app($pdo, $warmUp, failingViews: [self::STALE]), self::STALE);
        self::assertCount(1, $warmUp->journeyCompleted, 'precondition: the journey has completed once');

        $decoded = $this->journeyState($pdo, self::STALE);
        self::assertNotNull($decoded['completed_at'] ?? null, 'precondition: the fire-once guard is durably set');
        self::assertFalse($decoded['reconciled'] ?? true, 'precondition: the journey is still unreconciled');

        $reporter = new CompletionRecorder();
        $this->receipt($this->app($pdo, $reporter), self::STALE);

        $fresh = $this->journeyState($pdo, self::FRESH);
        self::assertNotSame([], $fresh, 'precondition: the session was re-minted');

        self::assertSame(
            [],
            $reporter->journeyCompleted,
            'a journey that has already completed must not complete again after a re-mint',
        );
        self::assertNotNull(
            $fresh['completed_at'] ?? null,
            'the fire-once guard has to travel with the placed-order list that lets the journey back onto the receipt',
        );
    }

    /**
     * The journey as it stands when the buyer has paid: one placed order, a
     * real `orders` row behind it, and a reconciliation that has not landed.
     *
     * `reconciled => false` is not a contrivance.
     * {@see \AsterMD\Storefront\Journey\SessionResolver::resolve()} only stamps
     * it from a snapshot it actually read, and a POST cannot re-mint, so a
     * journey whose EMR read is failing places its order and reaches the
     * receipt with the flag still false.
     */
    private function seedPaidJourney(\PDO $pdo): void
    {
        (new SessionRepository(static fn (): \PDO => $pdo))->insert(
            self::STALE,
            [
                'placed_orders' => [self::REFERENCE],
                'reconciled' => false,
                'buyer' => [
                    'first_name' => 'Dana',
                    'last_name' => 'Reyes',
                    'email' => 'dana.reyes@example.test',
                    'address_line' => '900 Larkin Avenue',
                    'city' => 'Portland',
                    'territory' => 'OR',
                    'postal_code' => '97205',
                ],
            ],
            null,
        );

        (new OrderRepository(static fn (): \PDO => $pdo))->insert(
            [
                'session_uuid' => self::STALE,
                'provider_reference' => self::REFERENCE,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 24500,
                'currency' => 'USD',
                'status' => 'placed',
                'buyer_email' => 'dana.reyes@example.test',
                'buyer_name' => 'Dana Reyes',
                'payment_method' => 'card',
                'card_last_four' => '4242',
            ],
            [['slug' => 'tirzepatide', 'name' => 'Tirzepatide (5mg/mL)', 'kind' => 'rx', 'unit_price_cents' => 24500, 'quantity' => 1]],
            [],
        );
    }

    /**
     * One application instance per request, because the journey store keeps one
     * state per container: a second request has to be a second application over
     * the same database, exactly as a second HTTP request would be.
     *
     * @param list<string> $failingViews uuids whose EMR read fails on this request
     */
    private function app(\PDO $pdo, CompletionRecorder $reporter, array $failingViews = []): \Slim\App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $pdo,
            SessionGateway::class => new FakeSessionGateway(
                sessions: [],
                mintUuid: self::FRESH,
                failingViews: $failingViews,
            ),
            CheckoutEventReporter::class => $reporter,
        ]);
    }

    private function receipt(\Slim\App $app, string $cookie): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/thank-you/')
                ->withCookieParams(['amd_session' => $cookie]),
        );
    }

    /** @return array<string, mixed> */
    private function journeyState(\PDO $pdo, string $uuid): array
    {
        $row = (new SessionRepository(static fn (): \PDO => $pdo))->find($uuid);

        return $row === null ? [] : $row['journey_state'];
    }
}

/**
 * Records what the completion step reports, and nothing else.
 *
 * A local fake rather than a mock: the interface's contract is that nothing it
 * does may throw or be branched on, so a recorder is the whole of what this
 * test needs from it.
 */
final class CompletionRecorder implements CheckoutEventReporter
{
    /** @var list<array{session: ?string, references: list<string>, placed: bool}> */
    public array $journeyCompleted = [];

    /** @var list<array{session: ?string, references: list<string>}> */
    public array $treatmentsSynced = [];

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
    }

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

    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
        $this->treatmentsSynced[] = ['session' => $sessionUuid, 'references' => $orderReferences];
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

    public function journeyCompleted(
        ?string $sessionUuid,
        bool $anyOrderPlaced,
        int $orderValueCents,
        int $paidTotalCents,
        ?string $paymentMethod,
        array $orderReferences,
        ?string $opportunityId,
    ): void {
        $this->journeyCompleted[] = ['session' => $sessionUuid, 'references' => $orderReferences, 'placed' => $anyOrderPlaced];
    }
}

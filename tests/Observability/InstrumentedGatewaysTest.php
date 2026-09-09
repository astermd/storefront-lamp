<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Observability;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Emr\CartGateway;
use AsterMD\Storefront\Emr\CartMirrorResult;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\IntakeGateway;
use AsterMD\Storefront\Forms\LeadGateway;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Observability\BoundaryTimer;
use AsterMD\Storefront\Observability\Instrumentation;
use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

final class InstrumentedGatewaysTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/instrumented-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function instrumentation(): Instrumentation
    {
        return new Instrumentation(new BoundaryTimer(new OperatorLog($this->logFile)));
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        $lines = [];

        foreach (explode("\n", trim((string) file_get_contents($this->logFile))) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            $lines[] = $decoded;
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function onlyLine(): array
    {
        $lines = $this->lines();
        self::assertCount(1, $lines);

        return $lines[0];
    }

    public function testTheSessionBoundaryReportsOutcomeAndLatencyWithoutTheAttributionPayload(): void
    {
        $gateway = $this->instrumentation()->sessionGateway(new class implements SessionGateway {
            public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
            {
                return 'uuid-9';
            }

            public function view(string $uuid): ?array
            {
                return null;
            }
        });

        self::assertSame('uuid-9', $gateway->create(['email' => 'buyer@example.test'], 'agent', '203.0.113.1'));

        $line = $this->onlyLine();
        self::assertSame('external.emr.session', $line['event']);
        self::assertSame('created', $line['context']['outcome']);
        self::assertGreaterThanOrEqual(0, $line['context']['latency_ms']);
        self::assertStringNotContainsString('buyer@example.test', (string) file_get_contents($this->logFile));
        self::assertStringNotContainsString('203.0.113.1', (string) file_get_contents($this->logFile));
    }

    public function testAFailedSessionMintIsAnOutcomeRatherThanASilence(): void
    {
        $gateway = $this->instrumentation()->sessionGateway(new class implements SessionGateway {
            public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
            {
                return null;
            }

            public function view(string $uuid): ?array
            {
                return null;
            }
        });

        $gateway->create([], null, null);

        self::assertSame('failed', $this->onlyLine()['context']['outcome']);
    }

    public function testTheCartBoundaryReportsTheThreeMirrorOutcomesByName(): void
    {
        $gateway = $this->instrumentation()->cartGateway(new class implements CartGateway {
            public function create(string $session, array $items): CartMirrorResult
            {
                return CartMirrorResult::NotFound;
            }

            public function update(string $session, array $items): CartMirrorResult
            {
                return CartMirrorResult::Ok;
            }
        });

        $gateway->create('s-1', [['product_id' => 'p', 'name' => 'n', 'qty' => 2]]);
        $gateway->update('s-1', []);

        $lines = $this->lines();
        self::assertSame('not_found', $lines[0]['context']['outcome']);
        self::assertSame('create', $lines[0]['context']['operation']);
        self::assertSame(1, $lines[0]['context']['items']);
        self::assertSame('ok', $lines[1]['context']['outcome']);
    }

    public function testTheFormDefinitionBoundaryIsInstrumentedInBothHalves(): void
    {
        $metadata = new TeleformMetadata('id-1', 'acct/org/form_v_1.json', 'intake', 'js', []);
        $gateway = $this->instrumentation()->teleformGateway(new class ($metadata) implements TeleformGateway {
            public function __construct(private readonly TeleformMetadata $metadata)
            {
            }

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return $this->metadata;
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return null;
            }
        });

        $gateway->metadata('tf-1');
        $gateway->definition($metadata);

        $lines = $this->lines();
        self::assertSame('external.emr.form_definition', $lines[0]['event']);
        self::assertSame('ok', $lines[0]['context']['outcome']);
        self::assertSame('metadata', $lines[0]['context']['operation']);
        self::assertSame('failed', $lines[1]['context']['outcome']);
        self::assertSame('definition', $lines[1]['context']['operation']);
    }

    public function testTheFormSaveBoundaryCountsAnswersAndNeverRecordsThem(): void
    {
        $gateway = $this->instrumentation()->intakeGateway(new class implements IntakeGateway {
            public function record(string $session, Event $event, string $teleformId, array $data, ?array $progress): bool
            {
                return true;
            }
        });

        $gateway->record('s-1', Event::IntakeInitiated, 'tf-1', [
            ['id' => '1', 'name' => 'diagnosis', 'label' => 'Diagnosis', 'type' => 'text', 'value' => [['v' => 'type 2 diabetes']]],
        ], ['page' => 1, 'total' => 3]);

        $line = $this->onlyLine();
        self::assertSame('external.emr.form_save', $line['event']);
        self::assertSame('accepted', $line['context']['outcome']);
        self::assertSame(1, $line['context']['fields']);
        self::assertStringNotContainsString('type 2 diabetes', (string) file_get_contents($this->logFile));
    }

    public function testTheOpportunityBoundaryReportsWhetherTheWriteLandedAndNotWhatWasInIt(): void
    {
        $gateway = $this->instrumentation()->leadGateway(new class implements LeadGateway {
            public function create(array $payload): ?string
            {
                return 'opp-1';
            }

            public function update(string $opportunityId, array $payload): bool
            {
                return false;
            }

            public function writes(): bool
            {
                return true;
            }
        });

        self::assertSame('opp-1', $gateway->create(['opportunity' => ['email' => 'buyer@example.test']]));
        self::assertFalse($gateway->update('opp-1', ['opportunity' => ['first_name' => 'Ada']]));
        self::assertTrue($gateway->writes());

        $lines = $this->lines();
        self::assertCount(2, $lines, 'writes() is a local question and must not be logged as an external call');
        self::assertSame('created', $lines[0]['context']['outcome']);
        self::assertSame('refused', $lines[1]['context']['outcome']);
        self::assertStringNotContainsString('buyer@example.test', (string) file_get_contents($this->logFile));
        self::assertStringNotContainsString('Ada', (string) file_get_contents($this->logFile));
    }

    public function testTheProviderBoundaryReportsThePlacementStateAndNeverTheDeclineText(): void
    {
        $adapter = $this->instrumentation()->paymentAdapter(new NullPaymentAdapter());
        $envelope = new OrderEnvelope(
            lines: [],
            buyer: new Buyer('Ada', 'Lovelace', 'ada@example.test', '5551234567', '1 Main St', 'Springfield', 'IL', '62701', 'US'),
            subtotalCents: 1000,
            discountCents: 0,
            totalCents: 1000,
            currency: 'USD',
            promotionCode: null,
            attribution: [],
            sessionUuid: 's-1',
            clientIp: null,
            userAgent: null,
            idempotencyKey: 'key-1',
            anchorSlug: 'slug',
        );

        $outcome = $adapter->place($envelope, PaymentCredential::card('4111111111111111', '12', '2030', '123'));

        self::assertSame('declined', $outcome->state);

        $line = $this->onlyLine();
        self::assertSame('external.provider.placement', $line['event']);
        self::assertSame('declined', $line['context']['outcome']);
        self::assertSame('no_provider_configured', $line['context']['raw_status']);

        $written = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString(NullPaymentAdapter::DECLINE_MESSAGE, $written);
        self::assertStringNotContainsString('4111111111111111', $written);
        self::assertStringNotContainsString('ada@example.test', $written);
    }

    public function testTheProviderCapabilitiesAndPingAreNotInstrumentedAsCalls(): void
    {
        $adapter = $this->instrumentation()->paymentAdapter(new NullPaymentAdapter());

        self::assertSame('none', $adapter->capabilities()->providerCategory);
        self::assertFalse($adapter->ping()['ok']);
        self::assertSame([], $this->lines());
    }

    public function testAnUnsupportedOrderSearchIsReportedAsUnsupportedRatherThanEmpty(): void
    {
        $adapter = $this->instrumentation()->paymentAdapter(new NullPaymentAdapter());
        $adapter->searchOrders(OrderSearch::lookback(3600));

        $line = $this->onlyLine();
        self::assertSame('external.provider.order_search', $line['event']);
        self::assertSame('unsupported', $line['context']['outcome']);
    }

    public function testThePromotionBoundaryReportsTheVerdictAndNotTheProvidersReason(): void
    {
        $adapter = $this->instrumentation()->paymentAdapter(new NullPaymentAdapter());
        $envelope = new OrderEnvelope(
            lines: [],
            buyer: new Buyer('Ada', 'Lovelace', 'ada@example.test', '5551234567', '1 Main St', 'Springfield', 'IL', '62701', 'US'),
            subtotalCents: 1000,
            discountCents: 0,
            totalCents: 1000,
            currency: 'USD',
            promotionCode: 'SAVE10',
            attribution: [],
            sessionUuid: 's-1',
            clientIp: null,
            userAgent: null,
            idempotencyKey: 'key-1',
            anchorSlug: 'slug',
        );

        $adapter->quotePromotion($envelope, 'SAVE10');

        $line = $this->onlyLine();
        self::assertSame('external.provider.promotion', $line['event']);
        self::assertSame('rejected', $line['context']['outcome']);
        self::assertSame('SAVE10', $line['context']['code']);
        self::assertArrayNotHasKey('reason', $line['context']);
    }
}

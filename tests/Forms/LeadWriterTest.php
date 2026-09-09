<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\LeadWriter;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeLeadGateway;
use PHPUnit\Framework\TestCase;

final class LeadWriterTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/lead-writer-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    private function writer(\AsterMD\Storefront\Forms\LeadGateway $gateway): LeadWriter
    {
        return new LeadWriter($gateway, new \AsterMD\Storefront\Forms\RecordMapper(new OperatorLog($this->logPath)), new OperatorLog($this->logPath));
    }

    public function testCreatesTheLeadOnceBothFirstNameAndEmailAreKnown(): void
    {
        $gateway = new FakeLeadGateway();
        $state = new JourneyState();

        $outcome = $this->writer($gateway)->capture('sess-1', $state, ['first_name' => 'Dana', 'email' => 'dana@example.test']);

        self::assertTrue($outcome->created());
        self::assertSame('opp-1', $outcome->opportunityId());
        self::assertSame('opp-1', $state->opportunityId, 'the id is remembered for the rest of the journey [9.10]');
        self::assertCount(1, $gateway->creates);
    }

    public function testWritesNothingBelowTheReadinessThreshold(): void
    {
        $gateway = new FakeLeadGateway();

        $outcome = $this->writer($gateway)->capture('sess-1', new JourneyState(), ['first_name' => 'Dana']);

        self::assertFalse($outcome->created());
        self::assertNull($outcome->opportunityId());
        self::assertSame([], $gateway->creates, '[9.9]: below first name plus email, nothing is written at all');
    }

    public function testLinksTheAnalyticsSessionToTheNewOpportunity(): void
    {
        $gateway = new FakeLeadGateway();
        $this->writer($gateway)->capture('sess-1', new JourneyState(), ['first_name' => 'Dana', 'email' => 'dana@example.test']);

        self::assertSame(['sess-1'], $gateway->creates[0]['sessions']);
    }

    public function testUpdatesRatherThanCreatingOnceAnOpportunityIsKnown(): void
    {
        $gateway = new FakeLeadGateway();
        $state = new JourneyState();
        $state->opportunityId = 'opp-existing';

        $outcome = $this->writer($gateway)->capture('sess-1', $state, ['first_name' => 'Dana', 'email' => 'dana@example.test']);

        self::assertFalse($outcome->created(), 'already had one [9.6]');
        self::assertSame('opp-existing', $outcome->opportunityId());
        self::assertSame([], $gateway->creates);
        self::assertCount(1, $gateway->updates);
    }

    public function testAnUpdateIsAllowedBelowTheCreationThresholdBecauseTheRecordAlreadyExists(): void
    {
        $gateway = new FakeLeadGateway();
        $state = new JourneyState();
        $state->opportunityId = 'opp-existing';

        $this->writer($gateway)->capture('sess-1', $state, ['phone' => '5550101234']);

        self::assertCount(1, $gateway->updates, 'the threshold guards creation, not every later write');
    }

    public function testAnEmptyPayloadWritesNothingEvenWithAKnownOpportunity(): void
    {
        $gateway = new FakeLeadGateway();
        $state = new JourneyState();
        $state->opportunityId = 'opp-existing';

        $this->writer($gateway)->capture('sess-1', $state, []);

        self::assertSame([], $gateway->updates);
    }

    public function testAFailedCreateIsSwallowedAndLoggedSoTheFormStillAdvances(): void
    {
        $gateway = new FakeLeadGateway();
        $gateway->failCreate = true;
        $state = new JourneyState();

        $outcome = $this->writer($gateway)->capture('sess-1', $state, ['first_name' => 'Dana', 'email' => 'dana@example.test']);

        self::assertFalse($outcome->created());
        self::assertNull($state->opportunityId);
        self::assertStringContainsString('intake.lead_write_failed', (string) file_get_contents($this->logPath));
    }

    public function testAFailedWriteNeverLogsAnAnswerValue(): void
    {
        $gateway = new FakeLeadGateway();
        $gateway->failCreate = true;

        $this->writer($gateway)->capture('sess-1', new JourneyState(), ['first_name' => 'Dana', 'email' => 'dana@example.test']);

        $log = (string) file_get_contents($this->logPath);
        self::assertStringNotContainsString('dana@example.test', $log);
        self::assertStringNotContainsString('Dana', $log);
    }

    public function testAGatewayThatWritesNowhereReportsNoFailure(): void
    {
        // An analytics-off deployment is configured not to write, which is not
        // an outage -- and a warning on every capture would make the alert that
        // matters unreadable.
        $gateway = new class implements \AsterMD\Storefront\Forms\LeadGateway {
            /** @var list<array<string, mixed>> */
            public array $creates = [];

            public function create(array $payload): ?string
            {
                $this->creates[] = $payload;

                return 'opp-1';
            }

            public function update(string $opportunityId, array $payload): bool
            {
                return true;
            }

            public function writes(): bool
            {
                return false;
            }
        };

        $outcome = $this->writer($gateway)->capture('sess-1', new JourneyState(), ['first_name' => 'Dana', 'email' => 'dana@example.test']);

        self::assertFalse($outcome->created());
        self::assertSame([], $gateway->creates);
        self::assertStringNotContainsString('intake.lead_write_failed', is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '');
    }

    public function testAFailedCreateLeavesTheJourneyAbleToRetryOnTheNextCapture(): void
    {
        $gateway = new FakeLeadGateway();
        $gateway->failCreate = true;
        $state = new JourneyState();
        $writer = $this->writer($gateway);

        $writer->capture('sess-1', $state, ['first_name' => 'Dana', 'email' => 'dana@example.test']);
        $gateway->failCreate = false;
        $outcome = $writer->capture('sess-1', $state, ['first_name' => 'Dana', 'email' => 'dana@example.test']);

        self::assertTrue($outcome->created(), '[9.5]: a network blip must not permanently lose the capture');
    }
}

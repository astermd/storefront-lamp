<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\BuyerDetails;
use AsterMD\Storefront\Checkout\ConsentRecord;
use AsterMD\Storefront\Checkout\Consents;
use AsterMD\Storefront\Checkout\LeadConsentSync;
use AsterMD\Storefront\Forms\LeadGateway;
use AsterMD\Storefront\Forms\LeadWriter;
use AsterMD\Storefront\Forms\NullLeadGateway;
use AsterMD\Storefront\Forms\RecordMapper;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeLeadGateway;
use PHPUnit\Framework\TestCase;

final class LeadConsentSyncTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/lead-consent-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    private static function consents(bool $mapped = true): Consents
    {
        return Consents::fromConfig(['consents' => [
            ['key' => 'terms', 'html' => 'Terms.', 'blocking' => true],
            ['key' => 'marketing', 'html' => 'Offers.'] + ($mapped ? ['emr_field' => 'marketing_consent'] : []),
            ['key' => 'transactional_sms', 'html' => 'Texts.'] + ($mapped ? ['emr_field' => 'communication_consent'] : []),
        ]]);
    }

    /** @return list<ConsentRecord> */
    private static function records(bool $marketing, bool $sms, bool $mapped = true): array
    {
        return self::consents($mapped)->record(array_filter([
            'terms' => 'on',
            'marketing' => $marketing ? 'on' : null,
            'transactional_sms' => $sms ? 'on' : null,
        ]))['records'];
    }

    private function sync(LeadGateway $gateway, bool $mapped = true): LeadConsentSync
    {
        $log = new OperatorLog($this->logPath);

        return new LeadConsentSync(new LeadWriter($gateway, new RecordMapper($log), $log), $gateway, self::consents($mapped), $log);
    }

    private static function buyer(): BuyerDetails
    {
        return new BuyerDetails(firstName: 'Ada', lastName: 'Lovelace', email: 'ada@example.com', phone: '5551234567');
    }

    private function log(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function testAnExistingLeadGetsOnlyTheConsentFields(): void
    {
        $gateway = new FakeLeadGateway();
        $state = new JourneyState();
        $state->opportunityId = 'opp-intake';

        $this->sync($gateway)->sync('sess-1', $state, self::buyer(), self::records(marketing: true, sms: false));

        self::assertSame([], $gateway->creates, 'names the intake collected are never overwritten');
        self::assertSame([['opp-intake', ['marketing_consent' => true, 'communication_consent' => false]]], $gateway->updates);
    }

    public function testADeclineIsSentAsFalseRatherThanLeftOut(): void
    {
        $gateway = new FakeLeadGateway();
        $state = new JourneyState();
        $state->opportunityId = 'opp-intake';

        $this->sync($gateway)->sync('sess-1', $state, self::buyer(), self::records(marketing: false, sms: false));

        self::assertSame(['marketing_consent' => false, 'communication_consent' => false], $gateway->updates[0][1]);
    }

    public function testWithoutALeadOneIsCreatedFromTheBuyerAndTheConsentWrittenAgain(): void
    {
        $gateway = new FakeLeadGateway();
        $gateway->newId = 'opp-checkout';
        $state = new JourneyState();

        $this->sync($gateway)->sync('sess-1', $state, self::buyer(), self::records(marketing: true, sms: true));

        self::assertCount(1, $gateway->creates);
        $created = $gateway->creates[0];
        self::assertSame('Ada', $created['first_name']);
        self::assertSame('Lovelace', $created['last_name']);
        self::assertSame('ada@example.com', $created['email']);
        self::assertTrue($created['marketing_consent']);
        self::assertTrue($created['communication_consent']);
        self::assertSame(['sess-1'], $created['sessions'], 'the lead is linked to the analytics session');

        // A create merged into an existing lead for this email keeps no consent.
        self::assertSame([['opp-checkout', ['marketing_consent' => true, 'communication_consent' => true]]], $gateway->updates);
        self::assertSame('opp-checkout', $state->opportunityId);
    }

    public function testAFailedCreateIsLoggedWithoutPersonalDataAndSendsNoUpdate(): void
    {
        $gateway = new FakeLeadGateway();
        $gateway->failCreate = true;
        $state = new JourneyState();

        $this->sync($gateway)->sync('sess-1', $state, self::buyer(), self::records(marketing: true, sms: false));

        self::assertSame([], $gateway->updates);
        self::assertNull($state->opportunityId);
        self::assertStringContainsString('checkout.lead_consent_failed', $this->log());
        self::assertStringNotContainsString('ada@example.com', $this->log());
    }

    public function testAFailedUpdateIsLoggedAndDoesNotThrow(): void
    {
        $gateway = new FakeLeadGateway();
        $gateway->failUpdate = true;
        $state = new JourneyState();
        $state->opportunityId = 'opp-intake';

        $this->sync($gateway)->sync('sess-1', $state, self::buyer(), self::records(marketing: true, sms: false));

        self::assertStringContainsString('checkout.lead_consent_failed', $this->log());
        self::assertStringContainsString('marketing_consent', $this->log());
    }

    public function testNothingIsSentWhenLeadsAreNotWritten(): void
    {
        $state = new JourneyState();
        $state->opportunityId = 'opp-intake';

        // NullLeadGateway is what analytics-off binds; any call would have to go through it.
        $this->sync(new NullLeadGateway())->sync('sess-1', $state, self::buyer(), self::records(marketing: true, sms: true));

        self::assertSame('', $this->log());
    }

    public function testNothingIsSentWhenNoConsentNamesAnEmrField(): void
    {
        $gateway = new FakeLeadGateway();
        $state = new JourneyState();

        $this->sync($gateway, mapped: false)->sync('sess-1', $state, self::buyer(), self::records(marketing: true, sms: true, mapped: false));

        self::assertSame([], $gateway->creates);
        self::assertSame([], $gateway->updates);
    }
}

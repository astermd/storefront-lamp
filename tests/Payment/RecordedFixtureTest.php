<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shapes the payment provider actually returned.
 *
 * These tests document rather than drive. Every one of them passes the moment
 * the fixtures are in place, and their job is to fail later: the adapter's
 * outcome logic depends on details that look like noise — a decline that still
 * carries an order reference, a null status that means "never charged", a
 * rejected discount arriving inside a successful response — and a well-meant
 * tidy-up of any fixture would otherwise silently retune the adapter's
 * behaviour with nothing to notice.
 */
final class RecordedFixtureTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/' . $name);
        self::assertIsString($raw, $name . ' is unreadable');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testTheApprovedEnvelopeCarriesItsReferenceAtTheSuccessPath(): void
    {
        $envelope = $this->fixture('vrio-order-approved.json');

        self::assertTrue($envelope['success']);
        self::assertSame(34660, $envelope['data']['order_id']);
        self::assertSame(100, $envelope['data']['response_code']);
        self::assertSame(3, $envelope['data']['order']['status_type_id']);
        self::assertNotNull($envelope['data']['order']['date_ordered']);
    }

    public function testTheDeclinedEnvelopeStillCarriesAnOrderReferenceAtADifferentPath(): void
    {
        // [13.26]: the provider creates the order and then the card fails, so
        // the reference exists and must be recorded against the decline.
        // [13.27]: it lives at a different path than on success.
        $envelope = $this->fixture('vrio-order-declined.json');

        self::assertFalse($envelope['success']);
        self::assertArrayNotHasKey('order_id', $envelope['data']);
        self::assertSame(34661, $envelope['data']['error']['transaction']['order_id']);
        self::assertNull($envelope['data']['error']['transaction']['order']['status_type_id']);
        self::assertNull($envelope['data']['error']['transaction']['order']['date_ordered']);
    }

    public function testADeclineDoesNotAnnounceItselfWithADeclineStatusCode(): void
    {
        // Recorded: the provider returned response_code 200 on a declined test
        // card, so response_code alone cannot decide the outcome.
        $envelope = $this->fixture('vrio-order-declined.json');

        self::assertSame(200, $envelope['data']['error']['transaction']['response_code']);
    }

    public function testThePendingEnvelopeCarriesItsRedirectInPostData(): void
    {
        $envelope = $this->fixture('vrio-order-pending.json');

        self::assertSame(101, $envelope['data']['response_code']);
        self::assertStringStartsWith('https://', $envelope['data']['post_data']);
        self::assertArrayHasKey('_note', $envelope, 'the constructed fixture must keep saying it is constructed');
    }

    public function testTheRecordedCredentialBlockCarriesAUrlTheClientWouldReject(): void
    {
        // The client throws on anything that is not a bare hostname, and this
        // is the value the channel supplies verbatim.
        $block = $this->fixture('channel-payment-processor.json');

        self::assertSame('vrio', $block['provider_category']);
        self::assertStringStartsWith('https://', $block['config']['api_endpoint']);
        self::assertSame('147', $block['config']['campaign_id']);
        self::assertSame('1', $block['config']['connection_id']);
    }

    public function testTheCampaignItemFixtureCoversEveryMappedProviderItem(): void
    {
        $items = $this->fixture('vrio-campaign-items.json')['data']['campaign_items'];
        $ids = array_column($items, 'item_id');

        self::assertCount(20, $items);
        // The four provider items the EMR channel actually maps to.
        foreach ([3411, 3412, 3414, 3415] as $mapped) {
            self::assertContains($mapped, $ids);
        }
    }

    public function testACalculatedDiscountIsReportedPerLineWithNoOrderLevelTotal(): void
    {
        // Recorded against NEW10 (10% off offers 337 and 338). The order's
        // discount is the sum of the per-line amounts; there is no total to
        // read, which is why the adapter adds them up.
        $data = $this->fixture('vrio-discount-calculated.json')['data'];

        self::assertArrayNotHasKey('discount_total', $data);
        self::assertCount(2, $data['offers']);
        self::assertSame('12.00', $data['offers'][0]['offer_total_discount']);
        self::assertSame('27.00', $data['offers'][1]['offer_total_discount']);
        self::assertTrue($data['offers'][0]['discount_details']['discount_code_valid']);
    }

    public function testARejectedDiscountArrivesInsideASuccessfulResponse(): void
    {
        // The request succeeded; the code was simply unknown. Reading the
        // envelope's success flag as the answer would accept every bad code
        // for nothing off, so only discount_code_valid decides.
        $envelope = $this->fixture('vrio-discount-rejected.json');

        self::assertTrue($envelope['success']);
        self::assertFalse($envelope['data']['offers'][0]['discount_details']['discount_code_valid']);
        self::assertSame('0.00', $envelope['data']['offers'][0]['offer_total_discount']);
    }

    public function testNoFixtureCarriesALiveCredentialOrARealCardNumber(): void
    {
        // These files are committed. A recorded api key or an unmasked pan in
        // one of them is a leak that survives every later redaction.
        foreach ([
            'vrio-order-approved.json',
            'vrio-order-declined.json',
            'vrio-order-pending.json',
            'vrio-campaign-items.json',
            'channel-payment-processor.json',
            'vrio-discount-calculated.json',
            'vrio-discount-rejected.json',
        ] as $name) {
            $raw = (string) file_get_contents(__DIR__ . '/../fixtures/' . $name);

            self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiIs', $raw, $name . ' carries a live JWT');
            self::assertDoesNotMatchRegularExpression('/"card_number"\s*:\s*"\d{13,19}"/', $raw, $name . ' carries an unmasked card number');
        }
    }
}

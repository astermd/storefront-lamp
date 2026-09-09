<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Vrio;

use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use PHPUnit\Framework\TestCase;

final class VrioOrderSearchTest extends TestCase
{
    private function adapter(FakeVrioTransport $transport, ?CapturedLog $log = null, int $campaignId = 147): VrioAdapter
    {
        return new VrioAdapter(
            new VrioCredentials('api.vrio.app', '', 'test-key', $campaignId, 1),
            new VrioApiFactory($transport),
            ($log ?? new CapturedLog())->log,
            shippingProfileId: 1,
        );
    }

    private function window(): OrderSearch
    {
        return OrderSearch::between(
            new \DateTimeImmutable('2026-08-23 00:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-08-25 23:59:59', new \DateTimeZone('UTC')),
            limit: 200,
        );
    }

    public function testTheAdapterDeclaresOrderSearchSupport(): void
    {
        self::assertTrue($this->adapter(new FakeVrioTransport())->capabilities()->supportsOrderSearch);
    }

    public function testTheWindowAndTheCampaignAreSentToTheProviderRatherThanFilteredHere(): void
    {
        // Recorded with negative controls: a 1990 window returns 0 and
        // campaign 999 returns 0, so both filters are honoured server-side.
        // Reading the whole account and filtering locally would page every
        // campaign on a shared merchant to find our own.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search.json');

        $this->adapter($transport)->searchOrders($this->window());

        $url = $transport->requests[0]->getUrl();

        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertStringContainsString('date_created_from=2026-08-23+00%3A00%3A00', $url);
        self::assertStringContainsString('date_created_to=2026-08-25+23%3A59%3A59', $url);
        self::assertStringContainsString('campaign_id=147', $url);
        self::assertStringContainsString('limit=200', $url);
    }

    public function testARecordedSearchIsMappedOntoProviderNeutralOrders(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search.json');

        $result = $this->adapter($transport)->searchOrders($this->window());

        self::assertTrue($result->ok);
        self::assertCount(15, $result->orders);
        self::assertSame(15, $result->reportedTotal);
        self::assertFalse($result->truncated());

        $first = $result->orders[0];

        self::assertSame('34788', $first->reference);
        self::assertSame('2026-08-24 12:39:15', $first->placedAt);
        self::assertTrue($first->isTest);
        self::assertTrue($first->isCharged);
        self::assertSame('3', $first->rawStatus);
        self::assertSame(0, $first->discountCents);
    }

    public function testANullOrderStatusIsMappedAsNeverCharged(): void
    {
        // `[14.10]` says the opposite -- no status counts as placed -- and the
        // wire says a null `status_type_id` is an order container with no
        // `date_ordered`, no `date_authorized` and no `date_capture`.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search.json');

        $result = $this->adapter($transport)->searchOrders($this->window());

        $byReference = [];
        foreach ($result->orders as $order) {
            $byReference[$order->reference] = $order;
        }

        self::assertFalse($byReference['34661']->isCharged);
        self::assertNull($byReference['34661']->rawStatus);
        self::assertTrue($byReference['34660']->isCharged);
    }

    public function testTheDiscountLeavesTheProviderAsIntegerCents(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search-mixed.json');

        $result = $this->adapter($transport)->searchOrders($this->window());

        self::assertSame('34788', $result->orders[0]->reference);
        self::assertFalse($result->orders[0]->isTest);
        self::assertSame(1250, $result->orders[0]->discountCents);
    }

    public function testAnEmptyWindowIsAnAnsweredSearchAndNotAFailedOne(): void
    {
        // The recorded 1990 negative control, verbatim.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search-empty.json');

        $result = $this->adapter($transport)->searchOrders($this->window());

        self::assertTrue($result->ok);
        self::assertSame([], $result->orders);
        self::assertSame(0, $result->reportedTotal);
        self::assertNull($result->failureReason);
    }

    public function testAnOrderFromAnotherCampaignIsDroppedRatherThanReported(): void
    {
        // The server-side filter is recorded as working, and this is what
        // stands behind it: a projection that leaked another campaign's order
        // would be reported as a charge this storefront lost, on an account
        // this storefront does not own.
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search-foreign-campaign.json');

        $result = $this->adapter($transport, $log)->searchOrders($this->window());

        self::assertCount(1, $result->orders);
        self::assertSame('34788', $result->orders[0]->reference);
        self::assertSame(1, count($log->eventsNamed('payment.order_search_foreign_campaign')));
    }

    public function testATransportFailureIsAFailedResultRatherThanAThrow(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queue(0, '', 'Could not resolve host: api.vrio.app');

        $result = $this->adapter($transport)->searchOrders($this->window());

        self::assertFalse($result->ok);
        self::assertSame([], $result->orders);
        self::assertNotSame(OrderSearchResult::UNSUPPORTED, $result->failureReason);
    }

    public function testABodyThatIsNotJsonIsAFailedResultRatherThanAnEmptyOne(): void
    {
        // An HTML error page from a proxy decodes to null. The client's own
        // `success` flag reads that as a success with no data, which the sweep
        // would read as "no orders were lost".
        $transport = new FakeVrioTransport();
        $transport->queue(200, '<html><body>502 Bad Gateway</body></html>');

        $result = $this->adapter($transport)->searchOrders($this->window());

        self::assertFalse($result->ok);
        self::assertSame([], $result->orders);
    }

    public function testAProviderErrorEnvelopeIsAFailedResult(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queue(401, '{"error":{"code":"unauthorized","message":"Invalid API key"}}');

        $result = $this->adapter($transport)->searchOrders($this->window());

        self::assertFalse($result->ok);
        self::assertSame([], $result->orders);
    }

    public function testAFailedSearchNeverLogsTheProvidersOwnText(): void
    {
        // The wire log already spills verbatim provider traffic; this boundary
        // adds identifiers and counts and nothing else.
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queue(401, '{"error":{"code":"unauthorized","message":"Invalid API key"}}');

        $this->adapter($transport, $log)->searchOrders($this->window());

        self::assertStringNotContainsString('Invalid API key', $log->contents());
    }
}

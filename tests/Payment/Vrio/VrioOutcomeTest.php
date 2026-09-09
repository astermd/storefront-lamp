<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Vrio;

use AsterMD\Storefront\Checkout\CheckoutAttempt;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\Vrio\VrioOutcome;
use PHPUnit\Framework\TestCase;

final class VrioOutcomeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../fixtures/' . $name);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testTheRecordedApprovalIsPlaced(): void
    {
        $outcome = VrioOutcome::from($this->fixture('vrio-order-approved.json'));

        self::assertTrue($outcome->isPlaced());
        self::assertSame('34660', $outcome->reference);
        self::assertNull($outcome->reason);
    }

    public function testTheRecordedDeclineIsDeclinedAndKeepsItsOrderReference(): void
    {
        // [13.26]: the provider created order 34661 and then failed the card.
        // Losing that reference means a retry places a second order nobody can
        // reconcile with the first.
        $outcome = VrioOutcome::from($this->fixture('vrio-order-declined.json'));

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('34661', $outcome->reference);
        self::assertSame('Failed test transaction', $outcome->reason);
    }

    public function testANullOrderStatusIsADeclineRatherThanASuccess(): void
    {
        // [14.10] says "any other status, or no status at all, counts as
        // placed". Across 1,400 recorded orders on this account a null status
        // is the *uncharged* case -- date_ordered, date_authorized and
        // date_capture are all null -- and it is 37% of them. Reading it as
        // placed sends buyers to a receipt for a charge that never happened.
        $envelope = [
            'success' => true,
            'message' => '',
            'data' => ['order_id' => 34651, 'order' => ['order_id' => 34651, 'status_type_id' => null, 'date_ordered' => null]],
        ];

        self::assertSame(PlacementOutcome::DECLINED, VrioOutcome::from($envelope)->state);
    }

    public function testATerminalStatusIsADecline(): void
    {
        foreach ([2, 5, 7, 8] as $status) {
            $envelope = [
                'success' => true,
                'message' => '',
                'data' => ['order_id' => 1, 'order' => ['status_type_id' => $status, 'date_ordered' => '2026-08-23 14:39:14']],
            ];

            self::assertSame(
                PlacementOutcome::DECLINED,
                VrioOutcome::from($envelope)->state,
                sprintf('status_type_id %d must not count as placed', $status),
            );
        }
    }

    public function testAFurtherActionResponseIsNeitherPlacedNorDeclined(): void
    {
        $outcome = VrioOutcome::from($this->fixture('vrio-order-pending.json'));

        self::assertSame(PlacementOutcome::PENDING_ACTION, $outcome->state);
        self::assertSame('34999', $outcome->reference);
        self::assertStringStartsWith('https://', (string) $outcome->actionUrl);
    }

    public function testATransportFailureIsADeclineWithAGenericMessage(): void
    {
        // The client reports a network failure as a curlError key rather than
        // by throwing, and [13.31] treats a thrown error the same as a decline.
        $outcome = VrioOutcome::from(['curlError' => 'Could not resolve host: api.vrio.app']);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertNull($outcome->reference);
        self::assertSame(VrioOutcome::GENERIC_DECLINE, $outcome->reason);
    }

    public function testAnUndecodableBodyIsADeclineEvenThoughTheClientCallsItSuccess(): void
    {
        // The client derives `success` from the absence of an `error` key, so
        // a non-JSON body (an HTML 502 from a proxy) decodes to null and reads
        // as success with no data. Requiring a reference is what catches it.
        $outcome = VrioOutcome::from(['success' => true, 'message' => '', 'data' => null]);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertNull($outcome->reference);
        self::assertSame(VrioOutcome::GENERIC_DECLINE, $outcome->reason);
    }

    public function testAMissingOrderReferenceIsAFailedPlacementRatherThanAnException(): void
    {
        // [13.25].
        $outcome = VrioOutcome::from(['success' => true, 'message' => '', 'data' => ['response_code' => 100]]);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
    }

    public function testTheBuyerSafeReasonPrefersTheProvidersOwnMessage(): void
    {
        // [13.28]: a known list of paths, in order, falling back to a generic
        // buyer-safe message.
        $outcome = VrioOutcome::from([
            'success' => false,
            'message' => 'Shipping Method Required',
            'data' => ['error' => ['code' => 'shipping_profile_required', 'message' => 'Shipping Method Required']],
        ]);

        self::assertSame('Shipping Method Required', $outcome->reason);
    }

    public function testTheGatewayTextIsUsedWhenTheEnvelopeMessageIsEmpty(): void
    {
        $outcome = VrioOutcome::from([
            'success' => false,
            'message' => '',
            'data' => ['error' => ['transaction' => ['order_id' => 9, 'gateway_response_text' => 'Insufficient funds']]],
        ]);

        self::assertSame('Insufficient funds', $outcome->reason);
    }

    public function testAnApprovalIsReconciledAgainstTheTotalTheStorefrontDisplayed(): void
    {
        // The recorded approval charged 120.00. A cart that displayed 108.00
        // agreed to a different number, and until this comparison existed
        // nothing anywhere would have noticed.
        $outcome = VrioOutcome::from($this->fixture('vrio-order-approved.json'), 10800);

        self::assertTrue($outcome->isPlaced(), 'the card was charged; the record must survive');
        self::assertSame('34660', $outcome->reference);
        self::assertNotNull($outcome->chargeDiscrepancy);
        self::assertSame(10800, $outcome->chargeDiscrepancy->expectedCents);
        self::assertSame(12000, $outcome->chargeDiscrepancy->chargedCents);
        self::assertSame(1200, $outcome->chargeDiscrepancy->differenceCents());
        self::assertSame(0, $outcome->chargeDiscrepancy->providerDiscountCents);
    }

    public function testAnApprovalThatMatchesTheDisplayedTotalCarriesNoDiscrepancy(): void
    {
        $outcome = VrioOutcome::from($this->fixture('vrio-order-approved.json'), 12000);

        self::assertTrue($outcome->isPlaced());
        self::assertNull($outcome->chargeDiscrepancy);
    }

    public function testAnUnreconcilableApprovalIsNotReportedAsAMismatch(): void
    {
        // No transaction_total in the response means nothing to compare
        // against. Inventing a mismatch would send an operator hunting for a
        // discrepancy that was never measured.
        $envelope = [
            'success' => true,
            'message' => '',
            'data' => ['order_id' => 1, 'order' => ['status_type_id' => 3]],
        ];

        $outcome = VrioOutcome::from($envelope, 12000);

        self::assertTrue($outcome->isPlaced());
        self::assertNull($outcome->chargeDiscrepancy);
    }

    public function testADeclineIsNotReconciled(): void
    {
        // Nothing was charged, so there is nothing to reconcile; the decline is
        // the signal that matters.
        $outcome = VrioOutcome::from($this->fixture('vrio-order-declined.json'), 999);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertNull($outcome->chargeDiscrepancy);
    }

    public function testAPanInTheGatewaysFreeTextIsScrubbedOutOfTheBuyerFacingReason(): void
    {
        // The reason is rendered to the buyer verbatim and persisted against
        // the order, and every fallback path is gateway free text.
        $outcome = VrioOutcome::from([
            'success' => false,
            'message' => '',
            'data' => ['error' => ['transaction' => ['order_id' => 9, 'gateway_response_text' => 'Declined for 4111-1111-1111-1111']]],
        ]);

        self::assertStringNotContainsString('4111', (string) $outcome->reason);
        self::assertStringContainsString('Declined for', (string) $outcome->reason);
    }

    public function testTheRawStatusIsKeptForTheOperatorLogWithoutBeingShown(): void
    {
        $outcome = VrioOutcome::from($this->fixture('vrio-order-approved.json'));

        self::assertSame('3', $outcome->rawStatus);
    }

    public function testAPlacementYieldsAReusableCredentialFromTheRecordedPaths(): void
    {
        // Built from the recording rather than by hand, so the paths asserted
        // here cannot drift from the ones the provider actually answers with.
        $recorded = $this->recording('vrio-handle-harvest.json');

        $outcome = VrioOutcome::from($recorded['response'], 12000);

        self::assertTrue($outcome->isPlaced());
        self::assertSame('34788', $outcome->reference);
        self::assertNotNull($outcome->reusableCredential);
        self::assertSame(
            ['customer_id' => '13996', 'customer_card_id' => '16815'],
            $outcome->reusableCredential->handle,
        );
    }

    public function testAPlacementMissingEitherHalfOfThePairYieldsNoCredential(): void
    {
        $base = ['success' => true, 'data' => ['order_id' => 34788, 'transaction_total' => '120.00', 'order' => ['status_type_id' => 3]]];

        $noCustomer = $base;
        $noCustomer['data']['order']['customer_card_id'] = 16815;

        $noCard = $base;
        $noCard['data']['customer_id'] = 13996;

        foreach ([$noCustomer, $noCard] as $envelope) {
            $outcome = VrioOutcome::from($envelope, 12000);
            self::assertTrue($outcome->isPlaced());
            self::assertNull($outcome->reusableCredential, 'half a pair is refused by the provider, so it is not a credential');
        }
    }

    public function testATopLevelCustomerCardIdIsNotReadFromAPlacement(): void
    {
        // `data.customer_card_id` is absent from every recorded addOrder
        // response and present on a getOrder read. Reading it here would mint
        // a handle from a shape a placement never produces.
        $envelope = [
            'success' => true,
            'data' => [
                'order_id' => 34788,
                'customer_id' => 13996,
                'customer_card_id' => 99999,
                'transaction_total' => '120.00',
                'order' => ['status_type_id' => 3],
            ],
        ];

        self::assertNull(VrioOutcome::from($envelope, 12000)->reusableCredential);
    }

    public function testARefusedStoredCredentialIsADeclineThatReleasesTheKey(): void
    {
        $recorded = $this->recording('vrio-cof-refusal.json');

        foreach ($recorded as $label => $pair) {
            self::assertIsArray($pair);
            self::assertIsArray($pair['response']);
            $outcome = VrioOutcome::from($pair['response'], 899);

            self::assertFalse($outcome->isPlaced(), (string) $label);
            self::assertNull($outcome->reference, (string) $label);
            self::assertSame(VrioOutcome::CREDENTIAL_REFUSED, $outcome->rawStatus, (string) $label);
            self::assertSame(VrioOutcome::STORED_CREDENTIAL_DECLINE, $outcome->reason, (string) $label);
            self::assertStringNotContainsString('Customer Card ID', (string) $outcome->reason, (string) $label);

            // The provider answered and created nothing, so this is emphatically
            // not a charge that cannot be ruled out.
            self::assertTrue(CheckoutAttempt::releasesKey($outcome), (string) $label);
        }
    }

    /**
     * One of the round-2 recordings, which pair each request with its response.
     *
     * @return array<string, mixed>
     */
    private function recording(string $name): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../fixtures/' . $name);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

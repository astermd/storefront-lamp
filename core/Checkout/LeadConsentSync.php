<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Forms\LeadGateway;
use AsterMD\Storefront\Forms\LeadWriter;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Sends the checkout's email/SMS consent answers to the buyer's lead
 * (opportunity) on the AsterMD platform once an order is placed.
 *
 * The opportunity is the only record the sales API keeps consent on, as two
 * booleans (`marketing_consent`, `communication_consent`); which checkout
 * consent feeds which is `emr_field` in `config/consent.php`
 * ({@see Consents::leadFields()}).
 *
 * - A journey that already has a lead — the intake form created it — gets an
 *   update carrying the consent fields and nothing else, so names the intake
 *   collected are never overwritten by what was typed at checkout.
 * - A journey without one — a product with no intake form — gets a lead
 *   created from the checkout buyer through {@see LeadWriter::capture()}, so
 *   it carries the session, source and affiliate link like any other lead.
 *   The consent is then written again with an update: the sales service merges
 *   a create for an email it already has a lead for into that lead, and the
 *   merge does not carry consent across.
 *
 * Runs after the charge, so it never throws: a failure is logged by field name
 * only — no names, emails or answers — and the order stands.
 */
final class LeadConsentSync
{
    public function __construct(
        private readonly LeadWriter $writer,
        private readonly LeadGateway $gateway,
        private readonly Consents $consents,
        private readonly OperatorLog $log,
    ) {
    }

    /** @param list<ConsentRecord> $records the consents recorded against the order just placed */
    public function sync(string $sessionUuid, JourneyState $state, BuyerDetails $buyer, array $records): void
    {
        $fields = $this->consents->leadFields($records);
        if ($fields === [] || !$this->gateway->writes()) {
            return;
        }

        try {
            $existing = $state->opportunityId;
            if ($existing !== null) {
                $this->update($sessionUuid, $existing, $fields);

                return;
            }

            $outcome = $this->writer->capture($sessionUuid, $state, [
                'first_name' => $buyer->firstName,
                'last_name' => $buyer->lastName,
                'email' => $buyer->email,
            ] + $fields);

            $created = $outcome->opportunityId();
            if ($created === null) {
                // LeadWriter has already logged a failed create; a buyer
                // without a first name or email never reaches one.
                $this->log->warning('checkout.lead_consent_failed', [
                    'session' => $sessionUuid,
                    'operation' => 'create',
                    'fields' => array_keys($fields),
                ]);

                return;
            }

            $this->update($sessionUuid, $created, $fields);
        } catch (\Throwable $e) {
            $this->log->warning('checkout.lead_consent_failed', [
                'session' => $sessionUuid,
                'operation' => 'exception',
                'fields' => array_keys($fields),
                'error' => $e::class,
            ]);
        }
    }

    /** @param array<string, bool> $fields */
    private function update(string $sessionUuid, string $opportunityId, array $fields): void
    {
        if ($this->gateway->update($opportunityId, $fields)) {
            return;
        }

        $this->log->warning('checkout.lead_consent_failed', [
            'session' => $sessionUuid,
            'operation' => 'update',
            'opportunity_id' => $opportunityId,
            'fields' => array_keys($fields),
        ]);
    }
}

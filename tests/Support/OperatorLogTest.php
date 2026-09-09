<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

final class OperatorLogTest extends TestCase
{
    public function testWritesOneJsonLinePerCall(): void
    {
        $file = sys_get_temp_dir() . '/operator-log-' . bin2hex(random_bytes(4)) . '.log';
        $log = new OperatorLog($file);
        $log->warning('session.create_failed', ['path' => '/treatments/']);
        $log->error('journey.save_failed', ['message' => 'db gone']);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        self::assertSame('warning', $first['level']);
        self::assertSame('session.create_failed', $first['event']);
        self::assertSame('/treatments/', $first['context']['path']);
        self::assertNotSame('', (string) $first['ts']);

        unlink($file);
    }

    public function testRedactsPersonalDataRecursivelyAndCaseInsensitively(): void
    {
        $redacted = OperatorLog::redact([
            'Email' => 'buyer@example.com',
            'session' => 'abc-123',
            'buyer' => ['first_name' => 'Ada', 'card_number' => '4111111111111111', 'city' => 'Leeds'],
            'answers' => ['q1' => 'yes'],
        ]);

        self::assertSame('[redacted]', $redacted['Email']);
        self::assertSame('abc-123', $redacted['session']);
        self::assertSame('[redacted]', $redacted['buyer']['first_name']);
        self::assertSame('[redacted]', $redacted['buyer']['card_number']);
        self::assertSame('[redacted]', $redacted['buyer']['city']);
        self::assertSame('[redacted]', $redacted['answers']);
    }

    public function testTheHeldCardCredentialIsRedactedFieldByField(): void
    {
        // The one array in this codebase that holds a live PAN is the held
        // credential `CheckoutService::heldCredential()` builds and parks on
        // `JourneyState::$paymentCredential`. Nothing logs it today; this
        // asserts the backstop is armed for the day a debug line does. The
        // shape below mirrors that method exactly.
        $redacted = OperatorLog::redact(['credential' => [
            'kind' => 'card',
            'number' => '4111111111111111',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'security_code' => '123',
            'reference' => '',
        ]]);

        self::assertSame('[redacted]', $redacted['credential']['number']);
        self::assertSame('[redacted]', $redacted['credential']['security_code']);
        self::assertSame('[redacted]', $redacted['credential']['expiry_month']);
        self::assertSame('[redacted]', $redacted['credential']['expiry_year']);
        // What the credential is remains debuggable; only the card does not.
        self::assertSame('card', $redacted['credential']['kind']);
        self::assertStringNotContainsString('4111', (string) json_encode($redacted));
        self::assertStringNotContainsString('123', (string) json_encode($redacted));
    }

    public function testTheProvidersOwnCardFieldNamesAreRedacted(): void
    {
        // `VrioPayload` names the card fields `card_cvv` / `card_exp_month` /
        // `card_exp_year`, and the provider echoes the last two back inside
        // the response envelope that `payment.not_placed` logs in full.
        $redacted = OperatorLog::redact(['response' => ['customer_card' => [
            'card_number' => '411122XXXXX4444',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvv' => '123',
            'card_type_id' => 2,
        ]]]);

        $card = $redacted['response']['customer_card'];
        self::assertSame('[redacted]', $card['card_number']);
        self::assertSame('[redacted]', $card['card_exp_month']);
        self::assertSame('[redacted]', $card['card_exp_year']);
        self::assertSame('[redacted]', $card['card_cvv']);
        self::assertSame(2, $card['card_type_id']);
    }

    public function testTheOrderRowsBuyerColumnsAreRedactedButItsLastFourIsNot(): void
    {
        // An order row read back through `OrderRepository` carries the buyer
        // under `buyer_email` / `buyer_name`. `card_last_four` is the one card
        // fact that is allowed to persist and is the operator's only handle on
        // which card an order used, so redacting it would cost them the
        // lookup without protecting anything.
        $redacted = OperatorLog::redact(['order' => [
            'buyer_email' => 'buyer@example.com',
            'buyer_name' => 'Ada Lovelace',
            'buyer_territory' => 'CA',
            'card_last_four' => '4444',
            'provider_reference' => '34660',
        ]]);

        self::assertSame('[redacted]', $redacted['order']['buyer_email']);
        self::assertSame('[redacted]', $redacted['order']['buyer_name']);
        self::assertSame('CA', $redacted['order']['buyer_territory']);
        self::assertSame('4444', $redacted['order']['card_last_four']);
        self::assertSame('34660', $redacted['order']['provider_reference']);
    }

    public function testTheProviderRequestBodysBillingAndShippingFieldsAreRedacted(): void
    {
        // `VrioPayload` flattens the buyer into `bill_*` / `ship_*` keys. The
        // request body is never logged today -- but it is one local variable
        // away from a debug line, and these are name and address.
        $redacted = OperatorLog::redact([
            'bill_fname' => 'Ada', 'bill_lname' => 'Lovelace',
            'bill_address1' => '1 Test Way', 'bill_city' => 'Leeds', 'bill_zipcode' => 'LS1 1AA',
            'ship_fname' => 'Ada', 'ship_lname' => 'Lovelace',
            'ship_address1' => '1 Test Way', 'ship_city' => 'Leeds', 'ship_zipcode' => 'LS1 1AA',
            'bill_state' => 'CA', 'bill_country' => 'US', 'campaign_id' => 147,
        ]);

        foreach ([
            'bill_fname', 'bill_lname', 'bill_address1', 'bill_city', 'bill_zipcode',
            'ship_fname', 'ship_lname', 'ship_address1', 'ship_city', 'ship_zipcode',
        ] as $key) {
            self::assertSame('[redacted]', $redacted[$key], $key . ' reached the log');
        }

        // Territory and campaign are what the operator debugs a decline with.
        self::assertSame('CA', $redacted['bill_state']);
        self::assertSame('US', $redacted['bill_country']);
        self::assertSame(147, $redacted['campaign_id']);
    }

    public function testACardNumberInsideAValueNeverReachesDiskWhoeverWroteTheLine(): void
    {
        // The key-based pass cannot see inside `gateway_request_text`, and the
        // value-level pass was opt-in at each call site -- so the tenth caller
        // to forget it leaked a PAN onto disk. Containment lives here now.
        $file = sys_get_temp_dir() . '/operator-log-' . bin2hex(random_bytes(4)) . '.log';
        (new OperatorLog($file))->error('payment.not_placed', [
            'response' => ['transaction' => ['gateway_request_text' => 'REQ 4111111100084444 737 DECLINED']],
            'reason' => 'card 4111.1111.0008.4444 refused, cvv=737',
            'campaign_id' => 147,
        ]);

        $written = (string) file_get_contents($file);
        unlink($file);

        self::assertStringNotContainsString('4111111100084444', $written);
        self::assertStringNotContainsString('4111.1111.0008.4444', $written);
        self::assertStringNotContainsString('737', $written);
        // And the line is still worth reading.
        self::assertStringContainsString('payment.not_placed', $written);
        self::assertStringContainsString('147', $written);
    }

    public function testTheCardFieldNamesTheCheckoutFormUsesAreRedacted(): void
    {
        // `CheckoutController::credentialFrom()` reads `card_cvc` and
        // `card_expiry` out of `$_POST`, so those are the names the datum
        // travels under on the way in -- and the list is exact-match.
        $redacted = OperatorLog::redact([
            'card_number' => '4111111100084444',
            'card_cvc' => '737',
            'card_expiry' => '12 / 30',
            'card_last_four' => '4444',
        ]);

        self::assertSame('[redacted]', $redacted['card_cvc']);
        self::assertSame('[redacted]', $redacted['card_expiry']);
        self::assertSame('[redacted]', $redacted['card_number']);
        self::assertSame('4444', $redacted['card_last_four']);
    }

    public function testALineSurvivesAContextByteThatIsNotValidUtf8(): void
    {
        // A database driver quotes the offending value back inside its own
        // message, so the one log line recording a lost charge is precisely
        // the line most likely to carry a malformed byte. `json_encode`
        // returns false on one, and returning early discarded the whole line.
        $file = sys_get_temp_dir() . '/operator-log-' . bin2hex(random_bytes(4)) . '.log';
        (new OperatorLog($file))->error('checkout.attempt_unresolved', [
            'reason' => "SQLSTATE[HY000]: value \xE9 rejected",
            'idempotency_key' => 'cart-42',
        ]);

        $written = (string) file_get_contents($file);
        unlink($file);

        self::assertNotSame('', $written, 'the whole line was discarded');
        self::assertStringContainsString('checkout.attempt_unresolved', $written);
        self::assertStringContainsString('error', $written);
        self::assertStringContainsString('cart-42', $written);
        self::assertNotFalse(json_decode(trim($written), true), 'the line is no longer valid JSON');
    }

    public function testAContextThatCannotBeEncodedAtAllStillLeavesADegradedLine(): void
    {
        // Substituting bad bytes covers the case that actually happens; a value
        // of a type JSON has no representation for is the residue, and an
        // operator reading "something happened here" beats reading nothing.
        $file = sys_get_temp_dir() . '/operator-log-' . bin2hex(random_bytes(4)) . '.log';
        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        (new OperatorLog($file))->warning('emr.create_failed', ['stream' => $handle]);
        fclose($handle);

        $decoded = json_decode(trim((string) file_get_contents($file)), true);
        unlink($file);

        self::assertIsArray($decoded);
        self::assertSame('warning', $decoded['level']);
        self::assertSame('emr.create_failed', $decoded['event']);
        self::assertSame('context could not be encoded', $decoded['context']['error']);
    }

    public function testTheStoredInstrumentHandleNeverReachesTheLogUnderAnyOfItsNames(): void
    {
        // The pair is not card data and no value-based scrubber can recognise
        // it — two ordinary integers — but together it is authority to charge,
        // and the provider refuses either half alone precisely because the
        // pairing is what stands between a guessed card id and a debit.
        //
        // Every outcome that is not a placement logs the provider's whole
        // response body, and a null order status is not a placement while
        // still being a response that vaults a card. So the halves arrive
        // under the provider's names, nested, and under the storefront's own
        // name at the top level.
        $file = sys_get_temp_dir() . '/operator-log-' . bin2hex(random_bytes(4)) . '.log';

        (new OperatorLog($file))->warning('payment.not_placed', [
            'handle' => ['customer_id' => '13957', 'customer_card_id' => '16764'],
            'response' => ['data' => [
                'customer_id' => 13957,
                'order' => ['customer_card_id' => 16764],
            ]],
        ]);

        $written = (string) file_get_contents($file);
        unlink($file);

        self::assertStringNotContainsString('13957', $written, 'the customer half is the secret one: card ids are sequential');
        self::assertStringNotContainsString('16764', $written);
        self::assertSame(3, substr_count($written, '[redacted]'), 'every name it travels under, not just the outermost');
    }

    public function testTheKeyBasedPassStillRunsAtTheSink(): void
    {
        // The value-based pass and the key-based pass are two different
        // defences and only one of them was pinned here. Removing the key-based
        // call from `write()` left the whole suite green, while it is the half
        // that redacts a buyer's name and email out of a provider envelope —
        // data no card scrubber will ever recognise.
        $file = sys_get_temp_dir() . '/operator-log-' . bin2hex(random_bytes(4)) . '.log';

        (new OperatorLog($file))->warning('payment.not_placed', [
            'response' => ['data' => ['customer' => [
                'first_name' => 'Ada',
                'email' => 'ada@example.com',
                'phone' => '2125551234',
            ]]],
        ]);

        $written = (string) file_get_contents($file);
        unlink($file);

        self::assertStringNotContainsString('ada@example.com', $written);
        self::assertStringNotContainsString('2125551234', $written);
        self::assertStringNotContainsString('Ada', $written);
    }

    public function testAnUnwritableFileNeverThrows(): void
    {
        $log = new OperatorLog('/proc/definitely/not/writable/app.log');
        $log->info('probe', []);
        self::assertTrue(true);
    }
}

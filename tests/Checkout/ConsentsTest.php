<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\Consents;
use PHPUnit\Framework\TestCase;

final class ConsentsTest extends TestCase
{
    /** @param array<int, array<string, mixed>>|null $consents */
    private function consents(?array $consents = null): Consents
    {
        $consents ??= [
            ['key' => 'terms', 'label' => 'Terms', 'html' => 'I agree to the Terms.', 'blocking' => true, 'links' => ['/terms/']],
            ['key' => 'marketing', 'label' => 'Marketing', 'html' => 'Send me offers.', 'blocking' => false, 'links' => []],
            ['key' => 'transactional_sms', 'label' => 'SMS', 'html' => 'Text me about my order.', 'blocking' => false, 'links' => []],
        ];

        return Consents::fromConfig(['consents' => $consents]);
    }

    public function testTheShippedDefaultsAreUsableWithoutCustomisation(): void
    {
        // [26.1]: a client who customises nothing still gets legally-shaped
        // wording rather than a placeholder.
        $config = require __DIR__ . '/../../config/consent.php';
        $consents = Consents::fromConfig($config);

        self::assertCount(3, $consents->definitions());
        self::assertSame(['terms', 'marketing', 'transactional_sms'], array_map(
            static fn ($d): string => $d->key,
            $consents->definitions(),
        ));
    }

    public function testMarketingAndTransactionalMessagingAreSeparateConsents(): void
    {
        // [26.4]: someone who declines marketing must still get their shipping
        // notification. Bundling them is the mistake the rule exists to stop.
        $config = require __DIR__ . '/../../config/consent.php';
        $keys = array_map(static fn ($d): string => $d->key, Consents::fromConfig($config)->definitions());

        self::assertContains('marketing', $keys);
        self::assertContains('transactional_sms', $keys);
    }

    public function testOnlyTheTermsConsentBlocksAnOrder(): void
    {
        // [26.5].
        $config = require __DIR__ . '/../../config/consent.php';
        $blocking = array_values(array_filter(
            Consents::fromConfig($config)->definitions(),
            static fn ($d): bool => $d->blocking,
        ));

        self::assertCount(1, $blocking);
        self::assertSame('terms', $blocking[0]->key);
    }

    public function testAnUngrantedBlockingConsentIsReportedAsMissing(): void
    {
        $result = $this->consents()->record(['marketing' => '1']);

        self::assertSame(['terms'], $result['missing']);
    }

    public function testEveryConsentIsRecordedIncludingTheDeclinedOnes(): void
    {
        // [26.10]: consent sent to the EMR reflects what the visitor did,
        // including false. A record that only lists the boxes someone ticked
        // cannot distinguish a decline from a question never asked.
        $result = $this->consents()->record(['terms' => 'on']);

        self::assertCount(3, $result['records']);
        $byKey = [];
        foreach ($result['records'] as $record) {
            $byKey[$record->key] = $record->granted;
        }

        self::assertSame(['terms' => true, 'marketing' => false, 'transactional_sms' => false], $byKey);
    }

    public function testARecordReproducesTheExactWordingThatWasShown(): void
    {
        // [26.6]: a record that cannot reproduce the wording is not evidence.
        $result = $this->consents()->record(['terms' => 'on']);

        self::assertSame('I agree to the Terms.', $result['records'][0]->copyShown);
    }

    public function testTheVersionChangesWhenTheCopyChanges(): void
    {
        // [26.7]: changing copy produces a new version, and existing records
        // keep pointing at the version actually displayed. Hashing the copy
        // means nobody has to remember to bump a number.
        $before = $this->consents()->version();
        $after = $this->consents([
            ['key' => 'terms', 'label' => 'Terms', 'html' => 'I agree to the Terms and Conditions.', 'blocking' => true, 'links' => []],
        ])->version();

        self::assertNotSame($before, $after);
    }

    public function testTheVersionIsStableForIdenticalCopy(): void
    {
        self::assertSame($this->consents()->version(), $this->consents()->version());
    }

    public function testAConsentAlreadyCollectedInTheIntakeFormIsNotAskedAgain(): void
    {
        // [26.11]: a form may collect a consent as an authored field; checkout
        // must not ask a second time.
        $consents = $this->consents()->except(['marketing']);

        self::assertSame(['terms', 'transactional_sms'], array_map(
            static fn ($d): string => $d->key,
            $consents->definitions(),
        ));
    }

    public function testAnUnknownSubmittedKeyIsIgnoredRatherThanRecorded(): void
    {
        $result = $this->consents()->record(['terms' => 'on', 'sell_my_data' => 'on']);

        self::assertCount(3, $result['records']);
    }

    public function testTheDefaultDocumentLinksAreDeclaredSoTheyCanBeValidated(): void
    {
        // [26.9]: a missing legal document is a deploy-time error, which needs
        // the links to be data rather than buried in an HTML string.
        $config = require __DIR__ . '/../../config/consent.php';
        $terms = Consents::fromConfig($config)->definitions()[0];

        self::assertSame(['/terms/', '/privacy/', '/telehealth-consent/'], $terms->links);
    }
}

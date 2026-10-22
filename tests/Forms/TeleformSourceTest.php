<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Checkout\Prefill;
use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\FormUnavailable;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

final class TeleformSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/teleform-source-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testFetchesOnceAndServesTheSecondCallFromCache(): void
    {
        $gateway = $this->gateway();
        $source = $this->source($gateway);

        $first = $source->definitionFor('tf-1');
        $second = $source->definitionFor('tf-1');

        self::assertSame($first, $second);
        self::assertSame(1, $gateway->definitionCalls);
        self::assertSame(1, $gateway->metadataCalls, 'the metadata read is what yields the cache key, so it happens once per definition fetch, not once per call');
    }

    public function testAFetchFailureWithAWarmCacheServesTheCachedCopy(): void
    {
        $gateway = $this->gateway();
        $this->source($gateway)->definitionFor('tf-1');

        $gateway->definitionFails = true;
        $definition = $this->source($gateway)->definitionFor('tf-1');

        self::assertSame('f-tirzepatide', $definition['formId']);
    }

    public function testAFetchFailureWithNoCacheIsTheFormUnavailableState(): void
    {
        $gateway = $this->gateway();
        $gateway->definitionFails = true;

        $this->expectException(FormUnavailable::class);
        $this->source($gateway)->definitionFor('tf-1');
    }

    public function testAnUnknownTeleformIsTheFormUnavailableStateRatherThanAnEmptyForm(): void
    {
        $gateway = $this->gateway();
        $gateway->metadataFails = true;

        $this->expectException(FormUnavailable::class);
        $this->source($gateway)->definitionFor('tf-1');
    }

    public function testExposesTheMetadataMappingSoCallersNeedOnlyOneRead(): void
    {
        $metadata = $this->source($this->gateway())->metadataFor('tf-1');

        self::assertNotNull($metadata);
        self::assertSame('opportunity.dob', $metadata->dbFields['date_of_birth']);
    }

    public function testTheMappingIsRekeyedFromFieldIdToTheNameAnswersAreStoredUnder(): void
    {
        $gateway = $this->idKeyedGateway();
        $source = $this->source($gateway);

        // The precondition: the id the map arrives with is not the answer name.
        $arrived = $source->metadataFor('tf-1');
        self::assertArrayHasKey('dob-1792650716016-359', $arrived?->dbFields ?? []);

        $metadata = $source->metadataByNameFor('tf-1');

        self::assertSame(
            ['date_of_birth_1792650716' => 'opportunity.dob', 'email_address' => 'opportunity.email'],
            $metadata?->dbFields,
        );

        // And the answer the id-keyed map names is now found by what reads it.
        self::assertSame(
            ['email' => 'ada@example.com'],
            Prefill::from($metadata, AnswerSet::fromArray(['email_address' => 'ada@example.com']), []),
        );
    }

    public function testAMappingIsLeftAsItArrivedWhenTheDefinitionCannotBeHad(): void
    {
        $gateway = $this->idKeyedGateway();
        $gateway->definitionFails = true;

        $metadata = $this->source($gateway)->metadataByNameFor('tf-1');

        self::assertSame(
            ['dob-1792650716016-359' => 'opportunity.dob', 'email-17' => 'opportunity.email'],
            $metadata?->dbFields,
        );
    }

    public function testAMappingAlreadyKeyedByNameIsKeptAsItIs(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'dob', 'name' => 'date_of_birth', 'type' => 'picker-date', 'label' => 'DOB'],
        ]]]]);
        $metadata = new TeleformMetadata('tf-1', 'x.json', 'intake', 'five-column', ['date_of_birth' => 'opportunity.dob']);

        self::assertSame(['date_of_birth' => 'opportunity.dob'], $metadata->keyedByName($definition)->dbFields);
    }

    private function idKeyedGateway(): object
    {
        return new class implements TeleformGateway {
            public bool $definitionFails = false;

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'acct/org/intake_29_1792650716.json',
                    type: 'intake',
                    layout: 'six-column',
                    dbFields: [
                        'dob-1792650716016-359' => 'opportunity.dob',
                        'email-17' => 'opportunity.email',
                    ],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                if ($this->definitionFails) {
                    return null;
                }

                return ['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
                    ['fieldId' => 'email-17', 'name' => 'email_address', 'type' => 'email', 'label' => 'Email'],
                    ['fieldId' => 'dob-1792650716016-359', 'name' => 'date_of_birth_1792650716', 'type' => 'picker-date', 'label' => 'DOB'],
                ]]]];
            }
        };
    }

    private function source(TeleformGateway $gateway): TeleformSource
    {
        return new TeleformSource(
            $gateway,
            new DefinitionCache($this->dir, 3600),
            new OperatorLog($this->dir . '/app.log'),
        );
    }

    private function gateway(): TeleformGateway
    {
        return new class implements TeleformGateway {
            public int $metadataCalls = 0;
            public int $definitionCalls = 0;
            public bool $metadataFails = false;
            public bool $definitionFails = false;

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                $this->metadataCalls++;
                if ($this->metadataFails) {
                    return null;
                }

                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'acct/org/tirzepatide_13_1787491225.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: ['date_of_birth' => 'opportunity.dob'],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                $this->definitionCalls++;

                return $this->definitionFails ? null : ['formId' => 'f-tirzepatide', 'pages' => []];
            }
        };
    }
}

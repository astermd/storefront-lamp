<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\FormsCachePurgeCommand;
use AsterMD\Storefront\Console\FormsRecordCommand;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class FormsCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/forms-commands-' . bin2hex(random_bytes(6));
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

    public function testCachePurgeReportsTheCountAndEmptiesTheDirectory(): void
    {
        $cache = new DefinitionCache($this->dir, 3600);
        $cache->put('acct/org/form_13_1787491225.json', ['formId' => 'f1', 'pages' => []]);
        $cache->put('acct/org/other_2_1787491226.json', ['formId' => 'f2', 'pages' => []]);

        $tester = new CommandTester(new FormsCachePurgeCommand($cache));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 definitions removed', $tester->getDisplay());
        self::assertSame([], glob($this->dir . '/*.json') ?: []);
    }

    public function testRecordWritesTheMetadataAndDefinitionAndReportsWhatItSaw(): void
    {
        $tester = new CommandTester(new FormsRecordCommand($this->gateway(), $this->dir . '/default'));

        self::assertSame(0, $tester->execute(['--teleform' => 'tf-1', '--out' => $this->dir]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('acct/org/tirzepatide_13_1787491225.json', $display);
        self::assertStringContainsString('Pages: 1', $display);
        self::assertStringContainsString('Fields: 2', $display);
        self::assertStringContainsString('db_fields: 1', $display);

        $metadata = json_decode((string) file_get_contents($this->dir . '/teleform-metadata.json'), true);
        self::assertSame('opportunity.dob', $metadata['db_fields']['date_of_birth']);

        $definition = json_decode((string) file_get_contents($this->dir . '/teleform-definition.json'), true);
        self::assertSame('f-tirzepatide', $definition['formId']);
    }

    public function testRecordFailsWithoutWritingWhenTheTeleformCannotBeResolved(): void
    {
        $gateway = $this->gateway();
        $gateway->metadataFails = true;

        $tester = new CommandTester(new FormsRecordCommand($gateway, $this->dir . '/default'));

        self::assertSame(1, $tester->execute(['--teleform' => 'tf-1', '--out' => $this->dir]));
        self::assertFalse(is_file($this->dir . '/teleform-metadata.json'));
        self::assertFalse(is_file($this->dir . '/teleform-definition.json'));
        self::assertSame(0, $gateway->definitionCalls);
    }

    /**
     * The same fake {@see TeleformGateway} the source test uses: the commands
     * must be exercised without an outbound call, and the gateway is the only
     * seam either of them has.
     */
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
                if ($this->definitionFails) {
                    return null;
                }

                return [
                    'formId' => 'f-tirzepatide',
                    'formName' => 'Tirzepatide Weight Loss Intake',
                    'pages' => [
                        [
                            'pageId' => 'p1',
                            'fields' => [
                                ['fieldId' => 'f1', 'name' => 'date_of_birth'],
                                ['fieldId' => 'f2', 'name' => 'sex_at_birth'],
                            ],
                        ],
                    ],
                ];
            }
        };
    }
}

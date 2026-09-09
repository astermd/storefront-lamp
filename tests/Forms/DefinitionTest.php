<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\Definition;
use PHPUnit\Framework\TestCase;

final class DefinitionTest extends TestCase
{
    private static function recorded(): Definition
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/teleform-definition.json'), true);

        return Definition::fromArray((array) $raw);
    }

    public function testParsesTheRecordedFormsPagesInOrder(): void
    {
        $definition = self::recorded();

        self::assertSame(5, $definition->pageCount());
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn ($p) => $p->order, $definition->pages()));
    }

    public function testFindsAFieldByItsAnswerName(): void
    {
        $field = self::recorded()->field('pregnancy_status');

        self::assertNotNull($field);
        self::assertSame('choice-multi', $field->type);
        self::assertTrue($field->sendToProvider);
    }

    public function testFlattensSubfieldsSoACompositesPartsAreIndependentlyAddressable(): void
    {
        $definition = self::recorded();

        self::assertNotNull($definition->field('bmi_measurement'), 'the composite itself');
        self::assertNotNull($definition->field('bmi_height'), 'a subfield [10.7]');
        self::assertNotNull($definition->field('bmi_weight'));
        self::assertNotNull($definition->field('bmi_unit_system'));
        self::assertTrue($definition->field('bmi_unit_system')->hidden, 'the unit system is a hidden subfield [10.28]');
    }

    public function testReadsChoiceOptionsAsValueLabelPairs(): void
    {
        $options = self::recorded()->field('comorbidities_conditions')->options();

        self::assertContains(['value' => 'none-of-the-above', 'label' => 'None of the above'], $options);
    }

    public function testAcceptsAPlainStringOptionAsBothValueAndLabel(): void
    {
        // The BMI unit-system subfield authors its options as bare strings [10.9].
        $options = self::recorded()->field('bmi_unit_system')->options();

        self::assertContains(['value' => 'Metric (kg/cm)', 'label' => 'Metric (kg/cm)'], $options);
    }

    public function testCarriesValidationConstraintsThroughUntouched(): void
    {
        $height = self::recorded()->field('bmi_height');

        self::assertSame(36, $height->validation['minValue']);
        self::assertSame(100, $height->validation['maxValue']);
    }

    public function testReportsNoUnsupportedTypesForTheRecordedForm(): void
    {
        self::assertSame([], self::recorded()->unsupportedTypes());
    }

    public function testReportsAnUnsupportedTypeSoTheRendererCanRefuseTheForm(): void
    {
        $definition = Definition::fromArray([
            'pages' => [[
                'pageId' => 'p1',
                'order' => 0,
                'fields' => [
                    ['fieldId' => 'sig', 'name' => 'signature', 'type' => 'signature', 'label' => 'Sign here'],
                    ['fieldId' => 'up', 'name' => 'labs', 'type' => 'file', 'label' => 'Upload labs'],
                    ['fieldId' => 'ok', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name'],
                ],
            ]],
        ]);

        self::assertSame(['signature', 'file'], $definition->unsupportedTypes());
    }

    public function testReadsTheColumnSpanHintThatDrivesLayout(): void
    {
        self::assertSame(1, self::recorded()->field('pregnancy_hard_stop_notice')->cols());
    }

    public function testSettingsAreReachableWithADefault(): void
    {
        $definition = self::recorded();

        self::assertSame('five-column', $definition->setting('layout', 'five-column'));
        self::assertNull($definition->setting('nothing_here'));
    }
}

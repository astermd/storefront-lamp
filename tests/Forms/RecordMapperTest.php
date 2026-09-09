<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\RecordMapper;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

final class RecordMapperTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        $this->log = sys_get_temp_dir() . '/record-mapper-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->log)) {
            unlink($this->log);
        }
    }

    private function mapper(): RecordMapper
    {
        return new RecordMapper(new OperatorLog($this->log));
    }

    /** @param array<string, string> $dbFields */
    private static function metadata(array $dbFields): TeleformMetadata
    {
        return new TeleformMetadata('tf-1', 'acct/org/f_1_1.json', 'intake', 'five-column', $dbFields);
    }

    private static function definition(): Definition
    {
        return Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name'],
            ['fieldId' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email'],
            ['fieldId' => 'dob', 'name' => 'date_of_birth', 'type' => 'picker-date', 'label' => 'DOB'],
            ['fieldId' => 'gender', 'name' => 'sex_at_birth', 'type' => 'choice-single', 'label' => 'Sex'],
            [
                'fieldId' => 'bmi_measurement', 'name' => 'bmi_measurement', 'type' => 'bmi', 'label' => 'Height & Weight',
                'subfields' => [
                    ['subfieldId' => 'bmi_unit_system', 'name' => 'bmi_unit_system', 'type' => 'dropdown', 'label' => 'Unit', 'hidden' => true],
                    ['subfieldId' => 'bmi_height', 'name' => 'bmi_height', 'type' => 'number', 'label' => 'Height'],
                    ['subfieldId' => 'bmi_weight', 'name' => 'bmi_weight', 'type' => 'number', 'label' => 'Weight'],
                ],
            ],
            ['fieldId' => 'meds', 'name' => 'other_medications_details', 'type' => 'textarea', 'label' => 'Medications'],
        ]]]]);
    }

    public function testExpandsDottedTargetsIntoANestedPayload(): void
    {
        $payload = $this->mapper()->build(
            self::metadata([
                'first_name' => 'opportunity.first_name',
                'other_medications_details' => 'opportunity.clinical.medications',
            ]),
            AnswerSet::fromArray(['first_name' => 'Dana', 'other_medications_details' => 'metformin']),
            self::definition(),
        );

        self::assertSame(['first_name' => 'Dana', 'clinical' => ['medications' => 'metformin']], $payload);
    }

    public function testOmitsFieldsWithNoAnswerOrAnEmptyAnswer(): void
    {
        $payload = $this->mapper()->build(
            self::metadata(['first_name' => 'opportunity.first_name', 'email' => 'opportunity.email']),
            AnswerSet::fromArray(['first_name' => 'Dana', 'email' => '']),
            self::definition(),
        );

        self::assertSame(['first_name' => 'Dana'], $payload, '[11.4]');
    }

    public function testSkipsAndLogsATargetRootItCannotWrite(): void
    {
        $payload = $this->mapper()->build(
            self::metadata(['first_name' => 'opportunity.first_name', 'email' => 'patient.email']),
            AnswerSet::fromArray(['first_name' => 'Dana', 'email' => 'dana@example.test']),
            self::definition(),
        );

        self::assertSame(['first_name' => 'Dana'], $payload);
        self::assertStringContainsString('intake.unmapped_target_root', (string) file_get_contents($this->log), '[11.3]: skipped and logged, never silently dropped');
    }

    public function testDoesNotWriteTheSkippedRootsAnswerIntoTheLog(): void
    {
        $this->mapper()->build(
            self::metadata(['email' => 'patient.email']),
            AnswerSet::fromArray(['email' => 'dana@example.test']),
            self::definition(),
        );

        self::assertStringNotContainsString('dana@example.test', (string) file_get_contents($this->log), 'answers are PHI');
    }

    public function testNormalisesImperialHeightToWholeCentimetres(): void
    {
        // 70 inches -> 177.8 cm -> 178
        $payload = $this->mapper()->build(
            self::metadata(['bmi_height' => 'opportunity.clinical.height.value']),
            AnswerSet::fromArray(['bmi_height' => '70', 'bmi_unit_system' => 'Imperial (lbs/inches)']),
            self::definition(),
        );

        self::assertSame(178, $payload['clinical']['height']['value'], '[11.7]');
    }

    public function testNormalisesImperialWeightToKilogramsToOneDecimal(): void
    {
        // 200 lb -> 90.718... kg -> 90.7
        $payload = $this->mapper()->build(
            self::metadata(['bmi_weight' => 'opportunity.clinical.weight.value']),
            AnswerSet::fromArray(['bmi_weight' => '200', 'bmi_unit_system' => 'Imperial (lbs/inches)']),
            self::definition(),
        );

        self::assertSame(90.7, $payload['clinical']['weight']['value']);
    }

    public function testLeavesMetricHeightAndWeightInTheirOwnUnits(): void
    {
        $payload = $this->mapper()->build(
            self::metadata([
                'bmi_height' => 'opportunity.clinical.height.value',
                'bmi_weight' => 'opportunity.clinical.weight.value',
            ]),
            AnswerSet::fromArray(['bmi_height' => '180', 'bmi_weight' => '80', 'bmi_unit_system' => 'Metric (kg/cm)']),
            self::definition(),
        );

        self::assertSame(180, $payload['clinical']['height']['value']);
        self::assertSame(80.0, $payload['clinical']['weight']['value']);
    }

    public function testMatchesAHeightTargetWithNoTrailingValueSegment(): void
    {
        $payload = $this->mapper()->build(
            self::metadata(['bmi_height' => 'opportunity.clinical.height']),
            AnswerSet::fromArray(['bmi_height' => '70', 'bmi_unit_system' => 'Imperial (lbs/inches)']),
            self::definition(),
        );

        self::assertSame(178, $payload['clinical']['height'], '[11.7]: with or without the trailing value segment');
    }

    public function testPassesANonNumericHeightThroughUntouched(): void
    {
        $payload = $this->mapper()->build(
            self::metadata(['bmi_height' => 'opportunity.clinical.height.value']),
            AnswerSet::fromArray(['bmi_height' => 'five foot ten']),
            self::definition(),
        );

        self::assertSame('five foot ten', $payload['clinical']['height']['value'], '[11.7]');
    }

    public function testSendsAMultiSelectAsAList(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'c', 'name' => 'conditions', 'type' => 'choice-multi', 'label' => 'Conditions'],
        ]]]]);

        $payload = $this->mapper()->build(
            self::metadata(['conditions' => 'opportunity.clinical.conditions']),
            AnswerSet::fromArray(['conditions' => ['a', 'b']]),
            $definition,
        );

        self::assertSame(['a', 'b'], $payload['clinical']['conditions']);
    }

    public function testAFormWithNoMappingAtAllProducesAnEmptyPayloadRatherThanFailing(): void
    {
        // The staging channel really has such a form.
        $payload = $this->mapper()->build(self::metadata([]), AnswerSet::fromArray(['first_name' => 'Dana']), self::definition());

        self::assertSame([], $payload);
    }

    public function testASingleSelectionIsWrittenAsAScalarBecauseTheRecordsFieldsAreScalars(): void
    {
        // Form authors routinely build a one-answer question out of a
        // multi-select control. The EMR refuses a list where it wants a value,
        // and the refusal takes the entire update with it.
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'g', 'name' => 'sex_at_birth', 'type' => 'choice-multi', 'label' => 'Sex at birth'],
        ]]]]);

        $payload = $this->mapper()->build(
            self::metadata(['sex_at_birth' => 'opportunity.gender']),
            AnswerSet::fromArray(['sex_at_birth' => ['female']]),
            $definition,
        );

        self::assertSame('female', $payload['gender']);
    }

    public function testAGenuineMultiSelectionStaysAList(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'c', 'name' => 'conditions', 'type' => 'choice-multi', 'label' => 'Conditions'],
        ]]]]);

        $payload = $this->mapper()->build(
            self::metadata(['conditions' => 'opportunity.clinical.conditions']),
            AnswerSet::fromArray(['conditions' => ['a', 'b']]),
            $definition,
        );

        self::assertSame(['a', 'b'], $payload['clinical']['conditions']);
    }

    public function testReadinessNeedsBothFirstNameAndEmail(): void
    {
        $mapper = $this->mapper();

        self::assertFalse($mapper->ready([]));
        self::assertFalse($mapper->ready(['first_name' => 'Dana']));
        self::assertFalse($mapper->ready(['email' => 'dana@example.test']));
        self::assertTrue($mapper->ready(['first_name' => 'Dana', 'email' => 'dana@example.test']), '[11.6]');
    }
}

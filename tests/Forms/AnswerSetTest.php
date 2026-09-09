<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\Definition;
use PHPUnit\Framework\TestCase;

final class AnswerSetTest extends TestCase
{
    private static function definition(): Definition
    {
        return Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name'],
            ['fieldId' => 'conditions', 'name' => 'conditions', 'type' => 'choice-multi', 'label' => 'Conditions'],
            ['fieldId' => 'consent', 'name' => 'consent', 'type' => 'terms', 'label' => 'Consent'],
            ['fieldId' => 'notice', 'name' => 'notice', 'type' => 'alert', 'label' => 'Notice'],
            ['fieldId' => 'weight_goal', 'name' => 'weight_goal', 'type' => 'number', 'label' => 'Goal'],
        ]]]]);
    }

    public function testMergeAccumulatesRatherThanReplacingWholesale(): void
    {
        $set = AnswerSet::empty()
            ->merge(['first_name' => 'Dana'], self::definition())
            ->merge(['weight_goal' => '20'], self::definition());

        self::assertSame(['first_name' => 'Dana', 'weight_goal' => '20'], $set->all());
    }

    public function testALaterAnswerForTheSameFieldWins(): void
    {
        $set = AnswerSet::empty()
            ->merge(['first_name' => 'Dana'], self::definition())
            ->merge(['first_name' => 'Danielle'], self::definition());

        self::assertSame('Danielle', $set->value('first_name'));
    }

    public function testAMultiValueFieldRoundTripsAsAListEvenWithOneSelection(): void
    {
        $set = AnswerSet::empty()->merge(['conditions' => 'high-cholesterol'], self::definition());

        self::assertSame(['high-cholesterol'], $set->value('conditions'), '[10.36]');
    }

    public function testAMultiValueFieldClearedToNothingBecomesAnEmptyList(): void
    {
        $set = AnswerSet::empty()
            ->merge(['conditions' => ['a', 'b']], self::definition())
            ->merge(['conditions' => []], self::definition());

        self::assertSame([], $set->value('conditions'));
    }

    public function testABooleanFieldIsStoredAsABoolean(): void
    {
        $set = AnswerSet::empty()->merge(['consent' => 'true'], self::definition());

        self::assertTrue($set->value('consent'));
    }

    public function testADisplayOnlyFieldCarriesNoAnswerEvenIfOnePosts(): void
    {
        $set = AnswerSet::empty()->merge(['notice' => 'tampered'], self::definition());

        self::assertNull($set->value('notice'), '[10.12]');
        self::assertSame([], $set->all());
    }

    public function testAFieldTheDefinitionDoesNotDeclareIsDropped(): void
    {
        $set = AnswerSet::empty()->merge(['injected' => 'x'], self::definition());

        self::assertSame([], $set->all(), '[10.22]: no fields present that the definition does not declare');
    }

    public function testMergeReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $first = AnswerSet::empty()->merge(['first_name' => 'Dana'], self::definition());
        $second = $first->merge(['first_name' => 'Danielle'], self::definition());

        self::assertSame('Dana', $first->value('first_name'));
        self::assertSame('Danielle', $second->value('first_name'));
    }

    public function testDerivesTheBmiScoreAndCategoryFromTheCompositesSubfields(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [[
            'fieldId' => 'bmi_measurement', 'name' => 'bmi_measurement', 'type' => 'bmi', 'label' => 'Height & Weight',
            'subfields' => [
                ['subfieldId' => 'bmi_unit_system', 'name' => 'bmi_unit_system', 'type' => 'dropdown', 'label' => 'Unit', 'hidden' => true],
                ['subfieldId' => 'bmi_height', 'name' => 'bmi_height', 'type' => 'number', 'label' => 'Height'],
                ['subfieldId' => 'bmi_weight', 'name' => 'bmi_weight', 'type' => 'number', 'label' => 'Weight'],
            ],
        ]]]]]);

        $set = AnswerSet::empty()
            ->merge(['bmi_height' => '70', 'bmi_weight' => '200'], $definition)
            ->withDerived($definition);

        self::assertSame(28.7, $set->value('bmi_measurement'), 'imperial is the default when the hidden unit subfield is unanswered [10.29]');
        self::assertSame('Overweight', $set->value('bmi_measurement_category'));
    }

    public function testTheDerivedBmiIsRecomputedWhenASubfieldChanges(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [[
            'fieldId' => 'bmi_measurement', 'name' => 'bmi_measurement', 'type' => 'bmi', 'label' => 'Height & Weight',
            'subfields' => [
                ['subfieldId' => 'bmi_unit_system', 'name' => 'bmi_unit_system', 'type' => 'dropdown', 'label' => 'Unit', 'hidden' => true],
                ['subfieldId' => 'bmi_height', 'name' => 'bmi_height', 'type' => 'number', 'label' => 'Height'],
                ['subfieldId' => 'bmi_weight', 'name' => 'bmi_weight', 'type' => 'number', 'label' => 'Weight'],
            ],
        ]]]]]);

        $set = AnswerSet::empty()
            ->merge(['bmi_height' => '70', 'bmi_weight' => '200'], $definition)
            ->withDerived($definition)
            ->merge(['bmi_weight' => '150'], $definition)
            ->withDerived($definition);

        self::assertSame(21.5, $set->value('bmi_measurement'));
    }

    public function testAnIncompleteBmiCompositeDerivesNothingRatherThanAZeroScore(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [[
            'fieldId' => 'bmi_measurement', 'name' => 'bmi_measurement', 'type' => 'bmi', 'label' => 'Height & Weight',
            'subfields' => [
                ['subfieldId' => 'bmi_height', 'name' => 'bmi_height', 'type' => 'number', 'label' => 'Height'],
                ['subfieldId' => 'bmi_weight', 'name' => 'bmi_weight', 'type' => 'number', 'label' => 'Weight'],
            ],
        ]]]]]);

        $set = AnswerSet::empty()->merge(['bmi_height' => '70'], $definition)->withDerived($definition);

        self::assertNull($set->value('bmi_measurement'));
    }

    public function testSurvivesARoundTripThroughStorage(): void
    {
        $set = AnswerSet::empty()->merge(['first_name' => 'Dana', 'conditions' => ['a']], self::definition());

        self::assertSame($set->all(), AnswerSet::fromArray($set->all())->all());
    }
}

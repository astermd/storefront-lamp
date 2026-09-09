<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\FieldTypes;
use AsterMD\Storefront\Forms\FieldViewModel;
use AsterMD\Storefront\Forms\RuleEvaluator;
use PHPUnit\Framework\TestCase;

final class FieldViewModelTest extends TestCase
{
    /** @param array<string, mixed> $raw */
    private static function viewModel(array $raw, mixed $answer = null, ?string $error = null): array
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [$raw]]]]);
        $field = $definition->field($raw['name']);

        return FieldViewModel::for($field, (new RuleEvaluator())->state($field, [], $definition), $answer, $error);
    }

    public function testMapsEachTypeToItsPartial(): void
    {
        self::assertSame('field-text.twig', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A'])['template']);
        self::assertSame('field-text.twig', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'email', 'label' => 'A'])['template']);
        self::assertSame('field-text.twig', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'phone', 'label' => 'A'])['template']);
        self::assertSame('field-date.twig', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'picker-date', 'label' => 'A'])['template']);
        self::assertSame('field-choice-multi.twig', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'choice-multi', 'label' => 'A'])['template']);
        self::assertSame('field-boolean.twig', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'terms', 'label' => 'A'])['template']);
        self::assertSame('field-alert.twig', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'alert', 'label' => 'A'])['template']);
    }

    public function testAnUnsupportedTypeGetsTheUnsupportedPartialRatherThanBeingOmitted(): void
    {
        $model = self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'signature', 'label' => 'Sign']);

        self::assertSame('field-unsupported.twig', $model['template'], '[10.13]: fail loudly, never drop the question');
        self::assertSame('signature', $model['type']);
    }

    public function testCarriesTheInputTypeForTextLikeFieldsSoTheKeyboardMatches(): void
    {
        self::assertSame('email', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'email', 'label' => 'A'])['input_type']);
        self::assertSame('tel', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'phone', 'label' => 'A'])['input_type']);
        self::assertSame('text', self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A'])['input_type']);
    }

    public function testBuildsAStableErrorIdSoTheInputCanPointAtItsMessage(): void
    {
        $model = self::viewModel(['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'A'], null, 'Required.');

        self::assertSame('Required.', $model['error']);
        self::assertSame('intake-error-first_name', $model['error_id'], '[25.4]: errors are programmatically associated with their field');
    }

    public function testHasNoErrorIdWhenThereIsNoError(): void
    {
        self::assertNull(self::viewModel(['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A'])['error_id']);
    }

    public function testMarksTheSelectedOptionsOfAMultiSelect(): void
    {
        $model = self::viewModel([
            'fieldId' => 'c', 'name' => 'c', 'type' => 'choice-multi', 'label' => 'C',
            'properties' => ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
        ], ['b']);

        self::assertFalse($model['options'][0]['selected']);
        self::assertTrue($model['options'][1]['selected']);
    }

    public function testMarksTheSelectedOptionOfASingleChoiceGivenAScalarAnswer(): void
    {
        $model = self::viewModel([
            'fieldId' => 'c', 'name' => 'c', 'type' => 'choice-single', 'label' => 'C',
            'properties' => ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
        ], 'a');

        self::assertTrue($model['options'][0]['selected']);
        self::assertFalse($model['options'][1]['selected']);
    }

    public function testAHiddenStateIsCarriedSoTheMarkupCanBeRenderedAndConcealed(): void
    {
        $model = self::viewModel([
            'fieldId' => 'd', 'name' => 'd', 'type' => 'text', 'label' => 'D',
            'conditions' => [['conditionId' => 'c1', 'action' => 'show', 'logic' => 'and', 'rules' => [['field' => 'x', 'operator' => 'equals', 'value' => 'yes']]]],
        ]);

        self::assertTrue($model['hidden'], 'the field is rendered and concealed, never omitted [10.6a]');
        self::assertFalse($model['required'], '[10.19]');
    }

    public function testExposesTheBmiSubfieldsInAuthoredOrder(): void
    {
        $model = self::viewModel([
            'fieldId' => 'bmi', 'name' => 'bmi_measurement', 'type' => 'bmi', 'label' => 'Height & Weight',
            'subfields' => [
                ['subfieldId' => 'bmi_unit_system', 'name' => 'bmi_unit_system', 'type' => 'dropdown', 'label' => 'Unit', 'hidden' => true, 'order' => 12],
                ['subfieldId' => 'bmi_height', 'name' => 'bmi_height', 'type' => 'number', 'label' => 'Height', 'order' => 13],
                ['subfieldId' => 'bmi_weight', 'name' => 'bmi_weight', 'type' => 'number', 'label' => 'Weight', 'order' => 14],
            ],
        ]);

        self::assertSame(['bmi_unit_system', 'bmi_height', 'bmi_weight'], array_column($model['subfields'], 'name'));
        self::assertTrue($model['subfields'][0]['hidden']);
    }

    public function testCarriesTheAlertTypeAndTextForANotice(): void
    {
        $model = self::viewModel([
            'fieldId' => 'n', 'name' => 'n', 'type' => 'alert', 'label' => 'Notice',
            'properties' => ['alertType' => 'danger', 'alertText' => 'Not eligible.'],
        ]);

        self::assertSame('danger', $model['alert_type']);
        self::assertSame('Not eligible.', $model['alert_text']);
    }

    public function testFallsBackToASafeAlertTypeWhenNoneIsAuthored(): void
    {
        $model = self::viewModel(['fieldId' => 'n', 'name' => 'n', 'type' => 'alert', 'label' => 'Notice']);

        self::assertSame('info', $model['alert_type']);
    }

    /**
     * A `template` naming a file that is not on disk is a fatal Twig error at
     * render time, on a page a visitor is part-way through, so the mapping is
     * checked against the filesystem for the whole vocabulary rather than for
     * the handful of types the cases above happen to name.
     */
    public function testEveryTypeInTheVocabularyNamesAPartialThatExists(): void
    {
        $directory = dirname(__DIR__, 2) . '/theme/templates/partials/intake/';
        $types = [...FieldTypes::VALUE_TYPES, ...FieldTypes::DISPLAY_TYPES, 'signature'];

        foreach ($types as $type) {
            $template = self::viewModel(['fieldId' => 'x', 'name' => 'x', 'type' => $type, 'label' => 'X'])['template'];

            self::assertFileIsReadable($directory . $template, "{$type} maps to a missing partial: {$template}");
        }
    }
}

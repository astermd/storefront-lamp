<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\AnswerValidator;
use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\RuleEvaluator;
use PHPUnit\Framework\TestCase;

final class AnswerValidatorTest extends TestCase
{
    private static function validator(): AnswerValidator
    {
        return new AnswerValidator(new RuleEvaluator());
    }

    /** @param list<array<string, mixed>> $fields */
    private static function definition(array $fields, bool $twoPages = false): Definition
    {
        $pages = [['pageId' => 'p1', 'order' => 0, 'fields' => $fields]];
        if ($twoPages) {
            $pages[] = ['pageId' => 'p2', 'order' => 1, 'fields' => [
                ['fieldId' => 'later', 'name' => 'later', 'type' => 'text', 'label' => 'Later question', 'required' => true],
            ]];
        }

        return Definition::fromArray(['pages' => $pages]);
    }

    public function testAMissingRequiredAnswerIsRejected(): void
    {
        $definition = self::definition([
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
        ]);

        $errors = self::validator()->validate($definition, AnswerSet::empty(), null);

        self::assertArrayHasKey('first_name', $errors);
        self::assertStringContainsString('First name', $errors['first_name'], '[25.6]: say what is wrong, naming the field');
    }

    public function testAWhitespaceOnlyAnswerDoesNotSatisfyRequired(): void
    {
        $definition = self::definition([
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
        ]);

        $errors = self::validator()->validate($definition, AnswerSet::fromArray(['first_name' => '   ']), null);

        self::assertArrayHasKey('first_name', $errors);
    }

    public function testARequiredFieldHiddenByAConditionIsNotDemanded(): void
    {
        $definition = self::definition([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'detail', 'name' => 'detail', 'type' => 'text', 'label' => 'Detail', 'required' => true,
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);

        self::assertSame([], self::validator()->validate($definition, AnswerSet::fromArray(['trigger' => 'no']), null), '[10.19]');
    }

    public function testARequiredFieldRevealedByAConditionIsDemanded(): void
    {
        $definition = self::definition([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'detail', 'name' => 'detail', 'type' => 'text', 'label' => 'Detail', 'required' => true,
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);

        self::assertArrayHasKey('detail', self::validator()->validate($definition, AnswerSet::fromArray(['trigger' => 'yes']), null));
    }

    public function testEnforcesLengthPatternAndRangeConstraints(): void
    {
        $definition = self::definition([
            ['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A', 'validation' => ['maxLength' => 3]],
            ['fieldId' => 'b', 'name' => 'b', 'type' => 'text', 'label' => 'B', 'validation' => ['minLength' => 5]],
            ['fieldId' => 'c', 'name' => 'c', 'type' => 'number', 'label' => 'C', 'validation' => ['minValue' => 36, 'maxValue' => 100]],
            ['fieldId' => 'd', 'name' => 'd', 'type' => 'text', 'label' => 'D', 'validation' => ['pattern' => '^[0-9]+$']],
        ]);

        $errors = self::validator()->validate($definition, AnswerSet::fromArray([
            'a' => 'abcd', 'b' => 'xy', 'c' => '120', 'd' => 'nope',
        ]), null);

        self::assertSame(['a', 'b', 'c', 'd'], array_keys($errors));
    }

    public function testUsesTheAuthoredErrorMessageWhenTheFormSuppliesOne(): void
    {
        $definition = self::definition([[
            'fieldId' => 'c', 'name' => 'c', 'type' => 'number', 'label' => 'Height',
            'validation' => ['minValue' => 36, 'errorMessages' => ['min' => 'Please enter a height in inches.']],
        ]]);

        $errors = self::validator()->validate($definition, AnswerSet::fromArray(['c' => '2']), null);

        self::assertSame('Please enter a height in inches.', $errors['c']);
    }

    public function testRejectsAMalformedEmail(): void
    {
        $definition = self::definition([
            ['fieldId' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email'],
        ]);

        self::assertArrayHasKey('email', self::validator()->validate($definition, AnswerSet::fromArray(['email' => 'not-an-email']), null));
    }

    public function testAnUnansweredOptionalFieldPassesEveryConstraint(): void
    {
        $definition = self::definition([
            ['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A', 'validation' => ['minLength' => 5]],
        ]);

        self::assertSame([], self::validator()->validate($definition, AnswerSet::empty(), null));
    }

    public function testARequiredConsentMustBeAffirmativeNotMerelyPresent(): void
    {
        $definition = self::definition([
            ['fieldId' => 'consent', 'name' => 'consent', 'type' => 'terms', 'label' => 'Consent', 'required' => true],
        ]);

        self::assertArrayHasKey('consent', self::validator()->validate($definition, AnswerSet::fromArray(['consent' => false]), null));
        self::assertSame([], self::validator()->validate($definition, AnswerSet::fromArray(['consent' => true]), null));
    }

    public function testARequiredMultiSelectNeedsAtLeastOneSelection(): void
    {
        $definition = self::definition([
            ['fieldId' => 'c', 'name' => 'c', 'type' => 'choice-multi', 'label' => 'Conditions', 'required' => true],
        ]);

        self::assertArrayHasKey('c', self::validator()->validate($definition, AnswerSet::fromArray(['c' => []]), null));
    }

    public function testAStepAdvanceValidatesOnlyThatPage(): void
    {
        $definition = self::definition([
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
        ], twoPages: true);

        $errors = self::validator()->validate($definition, AnswerSet::fromArray(['first_name' => 'Dana']), 0);

        self::assertSame([], $errors, '[10.20]: advancing validates the visible required fields of that step only');
    }

    public function testAFinalSubmitValidatesEveryPage(): void
    {
        $definition = self::definition([
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
        ], twoPages: true);

        $errors = self::validator()->validate($definition, AnswerSet::fromArray(['first_name' => 'Dana']), null);

        self::assertArrayHasKey('later', $errors);
    }

    public function testDisplayOnlyFieldsAreNeverValidated(): void
    {
        $definition = self::definition([
            ['fieldId' => 'notice', 'name' => 'notice', 'type' => 'alert', 'label' => 'Notice', 'required' => true],
            ['fieldId' => 'go', 'name' => 'go', 'type' => 'button', 'label' => 'Next', 'required' => true],
        ]);

        self::assertSame([], self::validator()->validate($definition, AnswerSet::empty(), null), '[10.12]');
    }
}

<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\RuleEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleEvaluatorTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>, bool}> */
    public static function operatorCases(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/rule-cases.json'), true);
        foreach ((array) $cases as $case) {
            yield $case['name'] => [$case['rule'], $case['answers'], $case['field'] ?? ['type' => 'text'], $case['expected']];
        }
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $answers
     * @param array<string, mixed> $field
     */
    #[DataProvider('operatorCases')]
    public function testOperator(array $rule, array $answers, array $field, bool $expected): void
    {
        $definition = self::definitionWith([
            ['fieldId' => 'q', 'name' => 'q', 'type' => $field['type'], 'label' => 'Q'],
        ]);

        self::assertSame($expected, (new RuleEvaluator())->rule($rule, $answers, $definition->field('q')));
    }

    public function testACombinatorArrayJoinsRuleIWithTheAccumulatedResultAtIndexIMinusOne(): void
    {
        // The recorded form authors `logic: ["and"]` for two rules -- one
        // combinator per join, not one per rule.
        $condition = [
            'logic' => ['and'],
            'rules' => [
                ['field' => 'bmi', 'operator' => 'greater_than_or_equal', 'value' => '27'],
                ['field' => 'bmi', 'operator' => 'less_than', 'value' => '30'],
            ],
        ];
        $definition = self::definitionWith([['fieldId' => 'bmi', 'name' => 'bmi', 'type' => 'number', 'label' => 'BMI']]);
        $evaluator = new RuleEvaluator();

        self::assertTrue($evaluator->group($condition, ['bmi' => '28'], $definition));
        self::assertFalse($evaluator->group($condition, ['bmi' => '31'], $definition));
        self::assertFalse($evaluator->group($condition, ['bmi' => '26'], $definition));
    }

    public function testAStringCombinatorAppliesToEveryJoin(): void
    {
        $condition = [
            'logic' => 'or',
            'rules' => [
                ['field' => 'a', 'operator' => 'equals', 'value' => 'yes'],
                ['field' => 'b', 'operator' => 'equals', 'value' => 'yes'],
            ],
        ];
        $definition = self::definitionWith([
            ['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A'],
            ['fieldId' => 'b', 'name' => 'b', 'type' => 'text', 'label' => 'B'],
        ]);

        self::assertTrue((new RuleEvaluator())->group($condition, ['a' => 'no', 'b' => 'yes'], $definition));
    }

    public function testAnAbsentCombinatorDefaultsToAnd(): void
    {
        $condition = [
            'rules' => [
                ['field' => 'a', 'operator' => 'equals', 'value' => 'yes'],
                ['field' => 'b', 'operator' => 'equals', 'value' => 'yes'],
            ],
        ];
        $definition = self::definitionWith([
            ['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A'],
            ['fieldId' => 'b', 'name' => 'b', 'type' => 'text', 'label' => 'B'],
        ]);

        self::assertFalse((new RuleEvaluator())->group($condition, ['a' => 'yes', 'b' => 'no'], $definition));
    }

    public function testAnEmptyRuleListIsNotSatisfied(): void
    {
        $definition = self::definitionWith([['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A']]);

        self::assertFalse((new RuleEvaluator())->group(['rules' => [], 'logic' => 'and'], [], $definition));
    }

    public function testAFieldCarryingAShowConditionStartsHiddenUntilItMatches(): void
    {
        $definition = self::definitionWith([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'detail', 'name' => 'detail', 'type' => 'text', 'label' => 'D', 'required' => true,
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);
        $evaluator = new RuleEvaluator();
        $detail = $definition->field('detail');

        self::assertFalse($evaluator->state($detail, [], $definition)->visible);
        self::assertTrue($evaluator->state($detail, ['trigger' => 'yes'], $definition)->visible);
    }

    public function testAHiddenFieldIsNotRequiredSoNativeValidationCannotBlockOnAnInvisibleControl(): void
    {
        $definition = self::definitionWith([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'detail', 'name' => 'detail', 'type' => 'text', 'label' => 'D', 'required' => true,
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);
        $state = (new RuleEvaluator())->state($definition->field('detail'), [], $definition);

        self::assertFalse($state->visible);
        self::assertFalse($state->required, '[10.19]: the required marker must drop while the control is hidden');
    }

    public function testAFieldCarryingAnEnableConditionStartsDisabled(): void
    {
        $definition = self::definitionWith([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'target', 'name' => 'target', 'type' => 'text', 'label' => 'X',
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'enable', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);
        $evaluator = new RuleEvaluator();
        $target = $definition->field('target');

        self::assertTrue($evaluator->state($target, [], $definition)->disabled);
        self::assertFalse($evaluator->state($target, ['trigger' => 'yes'], $definition)->disabled);
    }

    public function testADisabledFieldStaysVisibleSoTheVisitorSeesTheConsequence(): void
    {
        $definition = self::definitionWith([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'target', 'name' => 'target', 'type' => 'text', 'label' => 'X', 'required' => true,
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'disable', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);
        $state = (new RuleEvaluator())->state($definition->field('target'), ['trigger' => 'yes'], $definition);

        self::assertTrue($state->visible, '[10.38]: hide reduces noise, disable explains a consequence');
        self::assertTrue($state->disabled);
        self::assertFalse($state->required, '[10.39]: a disabled field is excluded from validation exactly as a hidden one is');
    }

    public function testTheRequireAndOptionalActionsMoveTheRequiredFlag(): void
    {
        $definition = self::definitionWith([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A',
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'require', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
            [
                'fieldId' => 'b', 'name' => 'b', 'type' => 'text', 'label' => 'B', 'required' => true,
                'conditions' => [[
                    'conditionId' => 'c2', 'action' => 'make_optional', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);
        $evaluator = new RuleEvaluator();

        self::assertTrue($evaluator->state($definition->field('a'), ['trigger' => 'yes'], $definition)->required);
        self::assertFalse($evaluator->state($definition->field('b'), ['trigger' => 'yes'], $definition)->required);
    }

    public function testAPresentationOnlyActionLeavesTheStateAloneRatherThanThrowing(): void
    {
        $definition = self::definitionWith([
            ['fieldId' => 'trigger', 'name' => 'trigger', 'type' => 'choice-single', 'label' => 'T'],
            [
                'fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A',
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'apply_class_name', 'value' => '.highlight', 'logic' => 'and',
                    'rules' => [['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);
        $state = (new RuleEvaluator())->state($definition->field('a'), ['trigger' => 'yes'], $definition);

        self::assertTrue($state->visible);
        self::assertFalse($state->disabled);
    }

    public function testAConditionReadingAFieldTheFormDoesNotDeclareEvaluatesFalse(): void
    {
        $definition = self::definitionWith([
            [
                'fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'A',
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'renamed_away', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]);

        self::assertFalse((new RuleEvaluator())->state($definition->field('a'), ['renamed_away' => 'yes'], $definition)->visible);
    }

    /** @param list<array<string, mixed>> $fields */
    private static function definitionWith(array $fields): Definition
    {
        return Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => $fields]]]);
    }
}

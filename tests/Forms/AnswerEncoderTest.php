<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\AnswerEncoder;
use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\Definition;
use PHPUnit\Framework\TestCase;

final class AnswerEncoderTest extends TestCase
{
    /** @param list<array<string, mixed>> $fields */
    private static function definition(array $fields): Definition
    {
        return Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => $fields]]]);
    }

    public function testAScalarAnswerEncodesAsOneSelfDescribingEntry(): void
    {
        $definition = self::definition([
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name'],
        ]);

        $encoded = AnswerEncoder::encode($definition, AnswerSet::fromArray(['first_name' => 'Dana']));

        self::assertSame([[
            'id' => 'first_name',
            'name' => 'first_name',
            'label' => 'First name',
            'type' => 'text',
            'value' => [['value' => 'Dana']],
        ]], $encoded);
    }

    public function testAMultiSelectEncodesOneEntryPerSelectionCarryingItsOptionLabel(): void
    {
        $definition = self::definition([[
            'fieldId' => 'conditions', 'name' => 'conditions', 'type' => 'choice-multi', 'label' => 'Conditions',
            'properties' => ['options' => [
                ['value' => 'a', 'label' => 'Option A'],
                ['value' => 'b', 'label' => 'Option B'],
                ['value' => 'c', 'label' => 'Option C'],
            ]],
        ]]);

        $encoded = AnswerEncoder::encode($definition, AnswerSet::fromArray(['conditions' => ['a', 'b']]));

        self::assertSame(
            [['value' => 'a', 'label' => 'Option A'], ['value' => 'b', 'label' => 'Option B']],
            $encoded[0]['value'],
            'the record has to be readable by a clinician, so the option label travels with its value',
        );
    }

    public function testExcludesDisplayOnlyFieldsAndFieldsTheFormWithholdsFromTheProvider(): void
    {
        $definition = self::definition([
            ['fieldId' => 'notice', 'name' => 'notice', 'type' => 'alert', 'label' => 'Notice'],
            ['fieldId' => 'utm', 'name' => 'utm', 'type' => 'hidden', 'label' => 'Campaign', 'sendToProvider' => false],
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name'],
        ]);

        $encoded = AnswerEncoder::encode($definition, AnswerSet::fromArray([
            'notice' => 'shown', 'utm' => 'spring', 'first_name' => 'Dana',
        ]));

        self::assertSame(['first_name'], array_column($encoded, 'name'), '[10.12]');
    }

    public function testExcludesUnansweredFieldsAndEncodesAnEmptySetAsAnEmptyList(): void
    {
        $definition = self::definition([
            ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name'],
            ['fieldId' => 'last_name', 'name' => 'last_name', 'type' => 'text', 'label' => 'Last name'],
        ]);

        self::assertSame(
            ['first_name'],
            array_column(AnswerEncoder::encode($definition, AnswerSet::fromArray(['first_name' => 'Dana', 'last_name' => ''])), 'name'),
        );
        self::assertSame([], AnswerEncoder::encode($definition, AnswerSet::empty()));
    }
}

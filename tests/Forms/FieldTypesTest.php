<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\FieldTypes;
use PHPUnit\Framework\TestCase;

final class FieldTypesTest extends TestCase
{
    public function testTheMedicalIntakeSetIsSupported(): void
    {
        foreach ([
            'text', 'textarea', 'email', 'phone', 'number', 'password', 'picker-date',
            'choice-single', 'choice-multi', 'dropdown', 'checkbox', 'toggle',
            'terms', 'agreement', 'bmi', 'hidden',
            'heading', 'form-header', 'paragraph', 'divider', 'alert', 'image', 'form-progress', 'button',
        ] as $type) {
            self::assertTrue(FieldTypes::supported($type), $type . ' should be supported');
        }
    }

    public function testTypesTheRendererDoesNotImplementAreRejectedRatherThanDroppedSilently(): void
    {
        foreach (['file', 'signature', 'video', 'captcha', 'rating', 'slider', 'input-table', 'ranking', 'modal'] as $type) {
            self::assertFalse(FieldTypes::supported($type), $type . ' should be unsupported');
        }
    }

    public function testAnUnknownTypeIsUnsupportedByDefaultRatherThanAssumedRenderable(): void
    {
        self::assertFalse(FieldTypes::supported('some-future-picker'));
        self::assertFalse(FieldTypes::supported(''));
    }

    public function testDisplayOnlyTypesCarryNoSubmittedValue(): void
    {
        self::assertTrue(FieldTypes::isDisplayOnly('alert'));
        self::assertTrue(FieldTypes::isDisplayOnly('button'));
        self::assertFalse(FieldTypes::isDisplayOnly('text'));
        self::assertFalse(FieldTypes::isDisplayOnly('hidden'), 'a hidden field is not displayed but does carry a value [10.11]');
    }

    public function testMultiValueTypesRoundTripAsLists(): void
    {
        self::assertTrue(FieldTypes::isMultiValue('choice-multi'));
        self::assertFalse(FieldTypes::isMultiValue('choice-single'));
    }
}

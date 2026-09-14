<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\ProgressSteps;
use PHPUnit\Framework\TestCase;

/**
 * The stepper above a questionnaire describes the questionnaire, not the
 * funnel.
 *
 * It used to be four hardcoded labels — Eligibility, Contact, Medical, Verify
 * & Review — above a form with any number of pages, so a five-page intake
 * showed four steps, three of which never moved and none of which named
 * anything the visitor was actually being asked.
 */
final class ProgressStepsTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $pages */
    private static function definition(array $pages): Definition
    {
        return Definition::fromArray(['pages' => $pages]);
    }

    /** @param array<string, mixed> $properties */
    private static function page(int $order, ?string $title, array $fields = []): array
    {
        return ['pageId' => 'p' . $order, 'order' => $order, 'title' => $title, 'fields' => $fields];
    }

    public function testOnePageOfTheFormIsOneStep(): void
    {
        $steps = ProgressSteps::from(self::definition([
            self::page(0, 'About You'),
            self::page(1, 'Your Goals'),
            self::page(2, 'Consent'),
        ]));

        self::assertCount(3, $steps);
        self::assertSame(['About You', 'Your Goals', 'Consent'], array_column($steps, 'label'));
    }

    /**
     * The EMR's form builder defaults every page's title to "Page N", which
     * names nothing. The page's own heading field is what the visitor
     * actually reads at the top of it, so that is the better label — and on
     * the shipped tirzepatide form it is the only one that says anything.
     */
    public function testABarePageNumberFallsBackToThePagesOwnHeading(): void
    {
        $steps = ProgressSteps::from(self::definition([
            self::page(0, 'Page 1', [
                ['fieldId' => 'h', 'name' => 'h', 'type' => 'heading', 'label' => 'Biometrics & Demographics'],
                ['fieldId' => 'a', 'name' => 'a', 'type' => 'text', 'label' => 'First name'],
            ]),
        ]));

        self::assertSame('Biometrics & Demographics', $steps[0]['label']);
    }

    /** A page with neither a real title nor a heading still has to be namable. */
    public function testAPageWithNothingToNameItFallsBackToItsPosition(): void
    {
        $steps = ProgressSteps::from(self::definition([self::page(0, null), self::page(1, '')]));

        self::assertSame(['Step 1', 'Step 2'], array_column($steps, 'label'));
    }

    /**
     * A form may carry a `form-progress` field, and when it does the author
     * has named the steps themselves — which outranks anything derived from
     * page titles, including a good one.
     */
    public function testAnAuthoredProgressFieldNamesTheStepsInstead(): void
    {
        $steps = ProgressSteps::from(self::definition([
            self::page(0, 'Page 1', [[
                'fieldId' => 'form-progress-1', 'name' => 'form_progress_1', 'type' => 'form-progress', 'label' => 'Form Progress',
                'properties' => ['progressStepItems' => [
                    ['page' => 1, 'text' => 'About You'],
                    ['page' => 2, 'text' => 'Goals'],
                    ['page' => 3, 'text' => 'Medical History'],
                    ['page' => 4, 'text' => 'Medications'],
                    ['page' => 5, 'text' => 'Final Step'],
                ]],
            ]]),
            self::page(1, 'Page 2'),
        ]));

        self::assertSame(
            ['About You', 'Goals', 'Medical History', 'Medications', 'Final Step'],
            array_column($steps, 'label'),
            'the authored steps describe the whole form, not only the page the field sits on',
        );
    }

    /** Authored steps are ordered by the page they name, not by the order they were written in. */
    public function testAuthoredStepsAreOrderedByThePageTheyName(): void
    {
        $steps = ProgressSteps::from(self::definition([
            self::page(0, 'Page 1', [[
                'fieldId' => 'fp', 'name' => 'fp', 'type' => 'form-progress', 'label' => 'P',
                'properties' => ['progressStepItems' => [
                    ['page' => 3, 'text' => 'Third'],
                    ['page' => 1, 'text' => 'First'],
                    ['page' => 2, 'text' => 'Second'],
                ]],
            ]]),
        ]));

        self::assertSame(['First', 'Second', 'Third'], array_column($steps, 'label'));
    }

    /**
     * An authored progress field with no usable items is not an instruction
     * to show an empty stepper. Falling back to the pages is the only answer
     * that still describes the form.
     */
    public function testAnEmptyAuthoredProgressFieldFallsBackToThePages(): void
    {
        $steps = ProgressSteps::from(self::definition([
            self::page(0, 'About You', [[
                'fieldId' => 'fp', 'name' => 'fp', 'type' => 'form-progress', 'label' => 'P',
                'properties' => ['progressStepItems' => []],
            ]]),
        ]));

        self::assertSame(['About You'], array_column($steps, 'label'));
    }

    /** A form with no pages has no steps, and must say so rather than fabricate one. */
    public function testAFormWithNoPagesHasNoSteps(): void
    {
        self::assertSame([], ProgressSteps::from(self::definition([])));
    }
}

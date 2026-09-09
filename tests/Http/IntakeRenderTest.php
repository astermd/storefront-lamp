<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\FieldViewModel;
use AsterMD\Storefront\Forms\RuleEvaluator;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * The mode C field renderer, exercised through the real templates.
 *
 * The view-model test next door proves the array is right; this one proves the
 * markup is, and the two are not the same claim. A perfectly correct view model
 * still fails a visitor if the partial drops `required`, marks a concealed
 * field required anyway, or renders a choice as something that carries no value
 * without scripts — none of which the array can show.
 */
final class IntakeRenderTest extends TestCase
{
    /**
     * The same Twig construction {@see \AsterMD\Storefront\Bootstrap\AppFactory}
     * uses, so escaping and lexer settings are the ones production renders
     * with. The intake partials call no custom function or filter, so the
     * storefront's Twig extension is deliberately not registered: what is under
     * test is the partials, not the extension.
     */
    private static function twig(): Twig
    {
        return Twig::create(dirname(__DIR__, 2) . '/theme/templates', ['cache' => false]);
    }

    /** @param array<string, mixed> $raw */
    private static function render(array $raw, mixed $answer = null, ?string $error = null): string
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [$raw]]]]);
        $field = $definition->field((string) $raw['name']);
        $state = (new RuleEvaluator())->state($field, [], $definition);

        return self::twig()->fetch('partials/intake/field.twig', [
            'field' => FieldViewModel::for($field, $state, $answer, $error),
        ]);
    }

    public function testARequiredTextFieldIsMarkedRequiredVisuallyAndProgrammatically(): void
    {
        $html = self::render([
            'fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text',
            'label' => 'First name', 'required' => true,
        ]);

        self::assertStringContainsString('required aria-required="true"', $html, '[25.8]');
        self::assertStringContainsString('data-intake-required="1"', $html);
    }

    public function testAConditionallyHiddenFieldIsConcealedAndNotMarkedRequired(): void
    {
        $html = self::render([
            'fieldId' => 'allergy_detail', 'name' => 'allergy_detail', 'type' => 'text',
            'label' => 'Which allergy?', 'required' => true,
            'conditions' => [[
                'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                'rules' => [['field' => 'allergies', 'operator' => 'equals', 'value' => 'yes']],
            ]],
        ]);

        self::assertMatchesRegularExpression(
            '/data-intake-required="0"[^>]*\shidden>/',
            $html,
            'the field is rendered and concealed, never omitted [10.6a]',
        );
        self::assertStringContainsString('data-intake-conditions=', $html, 'the stepper re-evaluates the field from its own conditions');
        self::assertStringNotContainsString('aria-required', $html, '[10.19]: a concealed field cannot block a submission');
        self::assertStringContainsString('id="intake-allergy_detail"', $html);
    }

    public function testAFieldWithAnErrorPointsAtTheElementCarryingTheMessage(): void
    {
        $html = self::render(
            ['fieldId' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email'],
            null,
            'Enter a valid email address.',
        );

        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertSame(1, preg_match('/aria-describedby="([^"]+)"/', $html, $match));
        self::assertStringContainsString('id="' . $match[1] . '"', $html, '[25.4]');
        self::assertStringContainsString('Enter a valid email address.', $html);
    }

    public function testAnUnsupportedTypeRendersAStatedErrorNamingTheQuestion(): void
    {
        $html = self::render([
            'fieldId' => 'sig', 'name' => 'sig', 'type' => 'signature', 'label' => 'Sign here',
        ]);

        self::assertStringContainsString('data-intake-unsupported="signature"', $html, '[10.13]');
        self::assertStringContainsString('Sign here', $html);
        self::assertStringContainsString('cannot be submitted', $html);
    }

    public function testAMultiSelectChecksTheStoredSelection(): void
    {
        $html = self::render([
            'fieldId' => 'conditions', 'name' => 'conditions', 'type' => 'choice-multi', 'label' => 'Conditions',
            'properties' => ['options' => [
                ['value' => 'diabetes', 'label' => 'Diabetes'],
                ['value' => 'thyroid', 'label' => 'Thyroid disorder'],
            ]],
        ], ['thyroid']);

        self::assertSame(1, preg_match_all('/\schecked\s/', $html), 'only the stored selection is checked');
        self::assertMatchesRegularExpression('/value="thyroid"[^>]*\schecked/', $html);
    }

    public function testADangerAlertShowsItsAuthoredText(): void
    {
        $html = self::render([
            'fieldId' => 'stop', 'name' => 'stop', 'type' => 'alert', 'label' => 'Notice',
            'properties' => ['alertType' => 'danger', 'alertText' => 'A BMI under 27 is not eligible.'],
        ]);

        self::assertStringContainsString('data-intake-alert="danger"', $html);
        self::assertStringContainsString('A BMI under 27 is not eligible.', $html);
        self::assertStringContainsString('role="alert"', $html, '[25.14]: a hard stop is announced, not only coloured');
    }

    /**
     * Pins the ruling that mode C uses the medical page's real-input choice
     * rows rather than the eligibility page's `<button aria-pressed>` rows. A
     * later tidy-up back to buttons would render a control that carries no
     * value when scripts fail, which is the whole point of server-rendered
     * markup (`[10.6a]`), and it would fail here.
     */
    public function testAChoiceGroupNamesItselfFromAQuestionElementThatActuallyExists(): void
    {
        $html = self::render([
            'fieldId' => 'sex', 'name' => 'sex_at_birth', 'type' => 'choice-single', 'label' => 'Sex at birth',
            'properties' => ['options' => [['value' => 'male', 'label' => 'Male'], ['value' => 'female', 'label' => 'Female']]],
        ]);

        self::assertStringContainsString('role="radiogroup"', $html);
        self::assertStringContainsString('aria-labelledby="intake-sex_at_birth-label"', $html);
        self::assertStringContainsString('id="intake-sex_at_birth-label"', $html, 'the group must name itself from an element that exists');
        self::assertStringNotContainsString('<label for="intake-sex_at_birth"', $html, 'one label cannot point at a set of radios');
    }

    public function testARequiredMultiSelectMarksTheGroupRequiredRatherThanEveryOption(): void
    {
        $html = self::render([
            'fieldId' => 'c', 'name' => 'conditions', 'type' => 'choice-multi', 'label' => 'Conditions', 'required' => true,
            'properties' => ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
        ]);

        self::assertStringContainsString('aria-required="true"', $html);
        self::assertSame(
            0,
            preg_match_all('/<input[^>]*type="checkbox"[^>]*\brequired\b/', $html),
            'a required checkbox per option would demand every option be ticked',
        );
    }

    public function testEveryChoiceControlIsARealInputRatherThanAToggleButton(): void
    {
        foreach (['choice-single', 'choice-multi'] as $type) {
            $html = self::render([
                'fieldId' => 'c', 'name' => 'c', 'type' => $type, 'label' => 'C',
                'properties' => ['options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
            ]);

            self::assertStringNotContainsString('aria-pressed', $html, "{$type} must not render toggle buttons");
            self::assertSame(2, preg_match_all('/<input\s/', $html), "{$type} renders one real input per option");
        }
    }
}

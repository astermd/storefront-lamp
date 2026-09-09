<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\Definition;
use AsterMD\Storefront\Forms\Disqualification;
use AsterMD\Storefront\Forms\RuleEvaluator;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

final class DisqualificationTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/disqualification-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    private static function recorded(): Definition
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/teleform-definition.json'), true);

        return Definition::fromArray((array) $raw);
    }

    /** @param array<string, mixed> $configValues */
    private function subject(array $configValues = []): Disqualification
    {
        $dir = sys_get_temp_dir() . '/disq-config-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/intake.php', '<?php return ' . var_export($configValues, true) . ';');

        $subject = new Disqualification(
            new RuleEvaluator(),
            Config::load($dir),
            new OperatorLog($this->logPath),
        );

        unlink($dir . '/intake.php');
        rmdir($dir);

        return $subject;
    }

    public function testReadsEveryDangerAlertFromTheRecordedFormAsAHardStop(): void
    {
        $rules = $this->subject()->rulesFor('tf-1', self::recorded());
        $hard = array_values(array_filter($rules, static fn ($r) => $r->mode === 'hard'));

        self::assertCount(6, $hard);
        self::assertContains('pregnancy_hard_stop_notice', array_map(static fn ($r) => $r->id, $hard));
        self::assertContains('bmi_low_hard_stop_notice', array_map(static fn ($r) => $r->id, $hard));
    }

    public function testReadsWarningAlertsAsAdvisoriesThatDoNotStopTheJourney(): void
    {
        $rules = $this->subject()->rulesFor('tf-1', self::recorded());
        $advisory = array_values(array_filter($rules, static fn ($r) => $r->mode === 'advisory'));

        self::assertCount(7, $advisory);
        self::assertContains('mtc_md_review_notice', array_map(static fn ($r) => $r->id, $advisory));
    }

    public function testCarriesTheAuthoredAlertTextAsTheMessageShownToTheVisitor(): void
    {
        $rules = $this->subject()->rulesFor('tf-1', self::recorded());
        $pregnancy = null;
        foreach ($rules as $rule) {
            if ($rule->id === 'pregnancy_hard_stop_notice') {
                $pregnancy = $rule;
            }
        }

        self::assertNotNull($pregnancy);
        self::assertStringContainsString('unable to prescribe', $pregnancy->message);
    }

    public function testAnAlertWithNoConditionIsNotATerminationRule(): void
    {
        // An unconditional alert is page copy, not a disqualifier.
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            [
                'fieldId' => 'intro', 'name' => 'intro', 'type' => 'alert', 'label' => 'Intro',
                'conditions' => [], 'properties' => ['alertType' => 'danger', 'alertText' => 'Read this'],
            ],
        ]]]]);

        self::assertSame([], $this->subject()->rulesFor('tf-1', $definition));
    }

    public function testAHardStopFiresWhenItsConditionMatches(): void
    {
        $definition = self::definitionWithPregnancyStop();

        $outcome = $this->subject()->evaluate('tf-1', $definition, AnswerSet::fromArray(['pregnancy_status' => 'yes']));

        self::assertTrue($outcome->isHard());
        self::assertSame('pregnancy_hard_stop_notice', $outcome->ruleId());
        self::assertStringContainsString('unable to prescribe', (string) $outcome->message());
    }

    public function testNoHardStopFiresWhenNoConditionMatches(): void
    {
        $outcome = $this->subject()->evaluate('tf-1', self::definitionWithPregnancyStop(), AnswerSet::fromArray(['pregnancy_status' => 'no']));

        self::assertFalse($outcome->isHard());
        self::assertNull($outcome->ruleId());
    }

    public function testAnUnansweredFormDoesNotDisqualifyAnyone(): void
    {
        $outcome = $this->subject()->evaluate('tf-1', self::definitionWithPregnancyStop(), AnswerSet::empty());

        self::assertFalse($outcome->isHard(), 'a missing answer must not be treated as disqualifying -- that was [8.5]s dead rule');
    }

    public function testABelowThresholdComputedValueFires(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'bmi_measurement', 'name' => 'bmi_measurement', 'type' => 'hidden', 'label' => 'BMI'],
            [
                'fieldId' => 'bmi_low', 'name' => 'bmi_low', 'type' => 'alert', 'label' => 'BMI notice',
                'properties' => ['alertType' => 'danger', 'alertText' => 'A BMI of at least 27 is required.'],
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'bmi_measurement', 'operator' => 'less_than', 'value' => '27']],
                ]],
            ],
        ]]]]);
        $subject = $this->subject();

        self::assertTrue($subject->evaluate('tf-1', $definition, AnswerSet::fromArray(['bmi_measurement' => 24.7]))->isHard());
        self::assertFalse($subject->evaluate('tf-1', $definition, AnswerSet::fromArray(['bmi_measurement' => 28.7]))->isHard());
    }

    public function testAdvisoriesAreReportedAlongsideANonHardOutcome(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'q', 'name' => 'pancreatitis', 'type' => 'choice-single', 'label' => 'Pancreatitis?'],
            [
                'fieldId' => 'review', 'name' => 'review', 'type' => 'alert', 'label' => 'Review notice',
                'properties' => ['alertType' => 'warning', 'alertText' => 'A clinician will review this.'],
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'pancreatitis', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]]]]);

        $outcome = $this->subject()->evaluate('tf-1', $definition, AnswerSet::fromArray(['pancreatitis' => 'yes']));

        self::assertFalse($outcome->isHard());
        self::assertCount(1, $outcome->advisories());
    }

    public function testAHardStopWinsOverAnAdvisoryWhenBothMatch(): void
    {
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'q', 'name' => 'q', 'type' => 'choice-single', 'label' => 'Q'],
            [
                'fieldId' => 'soft', 'name' => 'soft', 'type' => 'alert', 'label' => 'Soft',
                'properties' => ['alertType' => 'warning', 'alertText' => 'Review'],
                'conditions' => [['conditionId' => 'c1', 'action' => 'show', 'logic' => 'and', 'rules' => [['field' => 'q', 'operator' => 'equals', 'value' => 'yes']]]],
            ],
            [
                'fieldId' => 'hard', 'name' => 'hard', 'type' => 'alert', 'label' => 'Hard',
                'properties' => ['alertType' => 'danger', 'alertText' => 'Stop'],
                'conditions' => [['conditionId' => 'c2', 'action' => 'show', 'logic' => 'and', 'rules' => [['field' => 'q', 'operator' => 'equals', 'value' => 'yes']]]],
            ],
        ]]]]);

        $outcome = $this->subject()->evaluate('tf-1', $definition, AnswerSet::fromArray(['q' => 'yes']));

        self::assertTrue($outcome->isHard());
        self::assertSame('hard', $outcome->ruleId());
    }

    public function testFallsBackToConfiguredRulesOnlyWhenTheDefinitionDeclaresNone(): void
    {
        $bare = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'q', 'name' => 'pregnancy_status', 'type' => 'choice-single', 'label' => 'Pregnant?'],
        ]]]]);
        $subject = $this->subject(['disqualification' => ['tf-1' => [[
            'id' => 'configured_pregnancy',
            'mode' => 'hard',
            'message' => 'Configured message.',
            'logic' => 'and',
            'rules' => [['field' => 'pregnancy_status', 'operator' => 'equals', 'value' => 'yes']],
        ]]]]);

        $outcome = $subject->evaluate('tf-1', $bare, AnswerSet::fromArray(['pregnancy_status' => 'yes']));

        self::assertTrue($outcome->isHard());
        self::assertSame('configured_pregnancy', $outcome->ruleId());
    }

    public function testTheDefinitionsOwnRulesWinOverConfiguredOnes(): void
    {
        $subject = $this->subject(['disqualification' => ['tf-1' => [[
            'id' => 'configured_pregnancy', 'mode' => 'hard', 'message' => 'Configured.', 'logic' => 'and',
            'rules' => [['field' => 'pregnancy_status', 'operator' => 'equals', 'value' => 'no']],
        ]]]]);

        $outcome = $subject->evaluate('tf-1', self::definitionWithPregnancyStop(), AnswerSet::fromArray(['pregnancy_status' => 'no']));

        self::assertFalse($outcome->isHard(), 'a form that authors its own rules is authoritative');
    }

    public function testWarnsWhenARulesComparandMatchesNoOptionOnTheFieldItReads(): void
    {
        // The real staging form does exactly this: every rule compares against
        // an option label while the options store lowercase values.
        $definition = Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            [
                'fieldId' => 'q', 'name' => 'pregnancy_status', 'type' => 'choice-single', 'label' => 'Pregnant?',
                'properties' => ['options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
            ],
            [
                'fieldId' => 'stop', 'name' => 'stop', 'type' => 'alert', 'label' => 'Stop',
                'properties' => ['alertType' => 'danger', 'alertText' => 'Stop'],
                'conditions' => [['conditionId' => 'c1', 'action' => 'show', 'logic' => 'and', 'rules' => [['field' => 'pregnancy_status', 'operator' => 'equals', 'value' => 'Yes']]]],
            ],
        ]]]]);

        $this->subject()->rulesFor('tf-1', $definition);

        $log = (string) file_get_contents($this->logPath);
        self::assertStringContainsString('intake.termination_rule_unmatchable', $log);
        self::assertStringContainsString('pregnancy_status', $log);
    }

    private static function definitionWithPregnancyStop(): Definition
    {
        return Definition::fromArray(['pages' => [['pageId' => 'p1', 'order' => 0, 'fields' => [
            ['fieldId' => 'q', 'name' => 'pregnancy_status', 'type' => 'choice-single', 'label' => 'Pregnant?'],
            [
                'fieldId' => 'pregnancy_hard_stop_notice', 'name' => 'pregnancy_hard_stop_notice', 'type' => 'alert', 'label' => 'Notice',
                'properties' => ['alertType' => 'danger', 'alertText' => 'We are unable to prescribe this while you are pregnant.'],
                'conditions' => [[
                    'conditionId' => 'c1', 'action' => 'show', 'logic' => 'and',
                    'rules' => [['field' => 'pregnancy_status', 'operator' => 'equals', 'value' => 'yes']],
                ]],
            ],
        ]]]]);
    }
}

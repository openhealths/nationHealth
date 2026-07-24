<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\AtLeastOneEncounterAction;
use Tests\TestCase;

class AtLeastOneEncounterActionTest extends TestCase
{
    private function fails(AtLeastOneEncounterAction $rule, mixed $value = []): bool
    {
        $failed = false;
        $rule->validate('encounter.actionReferences', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    public function test_amb_class_fails_without_any_action(): void
    {
        $rule = new AtLeastOneEncounterAction(classCode: 'AMB', diagnosticReports: [], procedures: []);

        $this->assertTrue($this->fails($rule));
    }

    public function test_inpatient_class_fails_without_any_action(): void
    {
        $rule = new AtLeastOneEncounterAction(classCode: 'INPATIENT', diagnosticReports: [], procedures: []);

        $this->assertTrue($this->fails($rule));
    }

    public function test_amb_class_passes_with_action_references(): void
    {
        $rule = new AtLeastOneEncounterAction(classCode: 'AMB', diagnosticReports: [], procedures: []);

        $this->assertFalse($this->fails($rule, [['uuid' => 'some-uuid']]));
    }

    public function test_inpatient_class_passes_with_diagnostic_reports(): void
    {
        $rule = new AtLeastOneEncounterAction(
            classCode: 'INPATIENT',
            diagnosticReports: [['categoryCode' => 'laboratory_procedure']],
            procedures: []
        );

        $this->assertFalse($this->fails($rule));
    }

    public function test_amb_class_passes_with_procedures(): void
    {
        $rule = new AtLeastOneEncounterAction(
            classCode: 'AMB',
            diagnosticReports: [],
            procedures: [['categoryCode' => 'medical_procedure']]
        );

        $this->assertFalse($this->fails($rule));
    }

    public function test_phc_class_passes_without_any_action_since_actions_icpc2_block_covers_it(): void
    {
        $rule = new AtLeastOneEncounterAction(classCode: 'PHC', diagnosticReports: [], procedures: []);

        $this->assertFalse($this->fails($rule));
    }

    public function test_unknown_class_defaults_to_requiring_an_action(): void
    {
        $rule = new AtLeastOneEncounterAction(classCode: null, diagnosticReports: [], procedures: []);

        $this->assertTrue($this->fails($rule));
    }
}

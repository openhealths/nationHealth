<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\AtLeastOneEncounterAction;
use Tests\TestCase;

class AtLeastOneEncounterActionTest extends TestCase
{
    public function test_fails_when_encounter_has_no_action_references_diagnostic_reports_or_procedures(): void
    {
        $rule = new AtLeastOneEncounterAction(diagnosticReports: [], procedures: []);

        $failed = false;
        $rule->validate('encounter.actionReferences', [], function () use (&$failed): void {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    public function test_passes_when_action_references_are_present(): void
    {
        $rule = new AtLeastOneEncounterAction(diagnosticReports: [], procedures: []);

        $failed = false;
        $rule->validate('encounter.actionReferences', [['uuid' => 'some-uuid']], function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_passes_when_diagnostic_reports_are_present(): void
    {
        $rule = new AtLeastOneEncounterAction(diagnosticReports: [['categoryCode' => 'laboratory_procedure']], procedures: []);

        $failed = false;
        $rule->validate('encounter.actionReferences', [], function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_passes_when_procedures_are_present(): void
    {
        $rule = new AtLeastOneEncounterAction(diagnosticReports: [], procedures: [['categoryCode' => 'medical_procedure']]);

        $failed = false;
        $rule->validate('encounter.actionReferences', [], function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed);
    }
}

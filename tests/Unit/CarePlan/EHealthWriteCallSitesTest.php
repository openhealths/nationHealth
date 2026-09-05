<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use Tests\TestCase;

class EHealthWriteCallSitesTest extends TestCase
{
    public function test_care_plan_show_defers_activity_form_to_a_concern(): void
    {
        $show = file_get_contents(app_path('Livewire/CarePlan/CarePlanShow.php'));

        $this->assertIsString($show);
        $this->assertStringContainsString('use ManagesCarePlanActivities;', $show);
        $this->assertStringNotContainsString('function saveActivity', $show);
        $this->assertStringNotContainsString('function initActivityForm', $show);
        $this->assertLessThan(80, substr_count($show, "\n"));
    }

    public function test_livewire_does_not_post_clinical_writes_directly_to_ehealth(): void
    {
        $writePattern = '/EHealth::(?:carePlan|carePlanActivity|serviceRequest|deviceRequest)\(\)->(?:create|createSigned|cancel|complete|recall)\s*\(/';

        foreach ($this->clinicalLivewirePaths() as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression(
                $writePattern,
                $source,
                basename($path).' still posts a clinical write through EHealth::'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function clinicalLivewirePaths(): array
    {
        return [
            app_path('Livewire/CarePlan/CarePlanCreate.php'),
            app_path('Livewire/CarePlan/CarePlanUpdate.php'),
            app_path('Livewire/CarePlan/CarePlanShow.php'),
            app_path('Livewire/CarePlan/CarePlanApprovals.php'),
            app_path('Livewire/CarePlan/Concerns/CarePlanManager.php'),
            app_path('Livewire/CarePlan/Concerns/ManagesCarePlanActivities.php'),
            app_path('Livewire/CarePlan/Concerns/ManagesCarePlanReferrals.php'),
            app_path('Livewire/CarePlan/Concerns/ManagesCarePlanEPrescription.php'),
            app_path('Livewire/Person/Records/PatientReferrals.php'),
            app_path('Livewire/Encounter/Concerns/ManagesEncounterReferrals.php'),
        ];
    }
}

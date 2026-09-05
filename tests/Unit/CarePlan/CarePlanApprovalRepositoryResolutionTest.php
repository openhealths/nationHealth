<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Repositories\MedicalEvents\ApprovalRepository;
use App\Repositories\MedicalEvents\Repository;
use Tests\TestCase;

/**
 * Regression for G3: Care Plan sync must not resolve the non-existent
 * App\Repositories\ApprovalRepository FQCN.
 *
 * @see specs/001-care-plans-domain/analysis-approvals-architecture-gap.md
 */
class CarePlanApprovalRepositoryResolutionTest extends TestCase
{
    public function test_wrong_approval_repository_fqcn_does_not_exist(): void
    {
        $this->assertFalse(
            class_exists(\App\Repositories\ApprovalRepository::class, false),
            'App\\Repositories\\ApprovalRepository must not exist; use MedicalEvents\\ApprovalRepository'
        );
        $this->assertFileDoesNotExist(app_path('Repositories/ApprovalRepository.php'));
    }

    public function test_medical_events_repository_resolves_approval_with_sync_approvals(): void
    {
        $repo = Repository::approval();

        $this->assertInstanceOf(ApprovalRepository::class, $repo);
        $this->assertTrue(method_exists($repo, 'syncApprovals'));
    }

    public function test_care_plan_sync_call_sites_use_medical_events_repository(): void
    {
        $sources = [
            'CarePlanShow' => file_get_contents(app_path('Livewire/CarePlan/CarePlanShow.php')),
            'CarePlanManager' => file_get_contents(app_path('Livewire/CarePlan/Concerns/CarePlanManager.php')),
        ];

        foreach ($sources as $name => $source) {
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'App\\Repositories\\ApprovalRepository::class',
                $source,
                $name.' resolves the non-existent App\\Repositories\\ApprovalRepository'
            );
        }

        // The call lives in whichever of the two holds the sync action; CarePlanShow uses the
        // CarePlanManager trait, so requiring it in both files only pins where the code sits.
        $this->assertStringContainsString(
            'app(CarePlanApprovalService::class)->syncForCarePlan($this->carePlan)',
            implode("\n", $sources)
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostic;

use Tests\TestCase;

/**
 * Diagnostic: Care Plan mount depends on DeviceProgramParticipationGuard which is missing from app/.
 *
 * @group diagnostic
 */
class DeviceProgramParticipationGuardPresenceDiagnosticTest extends TestCase
{
    public function test_device_program_participation_guard_class_file_exists(): void
    {
        $path = app_path('Services/MedicalEvents/DeviceProgramParticipationGuard.php');

        $this->assertFileExists(
            $path,
            'DeviceProgramParticipationGuard.php is missing under app/Services/MedicalEvents/. '
            .'CarePlanComponent::loadDeviceProgramParticipationState() resolves this class on mount; '
            .'without the file, care-plan / activity pages throw BindingResolutionException and block eRx/referral UI. '
            .'A unit test for the class exists, but the implementation was not present in PR #652 merge commit.'
        );
    }
}

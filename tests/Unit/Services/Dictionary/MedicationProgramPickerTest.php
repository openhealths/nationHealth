<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dictionary;

use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Dictionary\MedicationProgramPicker;
use Illuminate\Support\Facades\Auth;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicationProgramPickerTest extends TestCase
{
    #[DataProvider('specialityCases')]
    public function test_role_and_speciality_rules(array $settings, bool $expected, string $role = 'SPECIALIST'): void
    {
        $this->instance('legalEntity', new LegalEntity());
        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('allowedRoles')->andReturn(collect([$role]));
        $user->shouldReceive('getMainSpeciality')->andReturn(collect(['CARDIOLOGY']));
        $user->shouldReceive('hasAllowedRole')->with(Role::SPECIALIST)->andReturn($role === 'SPECIALIST');
        $user->shouldReceive('hasAllowedRole')->with(Role::DOCTOR)->andReturn($role === 'DOCTOR');
        Auth::shouldReceive('user')->andReturn($user);

        $program = ['id' => 'test', 'is_active' => true, 'medical_program_settings' => $settings];
        $this->assertSame($expected, MedicationProgramPicker::forCurrentUser(collect([$program]))->isNotEmpty());
        $program['is_active'] = false;
        $this->assertTrue(MedicationProgramPicker::forCurrentUser(collect([$program]))->isEmpty());
    }

    public static function specialityCases(): array
    {
        return [
            'request matches without legacy' => [['speciality_types_request_allowed' => ['CARDIOLOGY'], 'speciality_types_care_plan_activity_allowed' => ['SURGERY']], true],
            'activity matches without legacy' => [['speciality_types_request_allowed' => ['SURGERY'], 'speciality_types_care_plan_activity_allowed' => ['CARDIOLOGY']], true],
            'legacy null does not hide' => [['speciality_types_allowed' => null], true],
            'request null removes restriction' => [['speciality_types_request_allowed' => null, 'speciality_types_care_plan_activity_allowed' => ['SURGERY']], true],
            'activity null removes restriction' => [['speciality_types_request_allowed' => ['SURGERY'], 'speciality_types_care_plan_activity_allowed' => null], true],
            'missing request removes restriction' => [['speciality_types_care_plan_activity_allowed' => ['SURGERY']], true],
            'missing activity removes restriction' => [['speciality_types_request_allowed' => ['SURGERY']], true],
            'legacy cannot grant access' => [['speciality_types_allowed' => ['CARDIOLOGY'], 'speciality_types_request_allowed' => ['SURGERY'], 'speciality_types_care_plan_activity_allowed' => ['NEUROLOGY']], false],
            'role denied even without speciality restriction' => [['employee_types_to_create_request' => ['DOCTOR']], false],
            'coordinator role denied' => [['employee_types_to_create_request' => ['DOCTOR']], false, 'MED_COORDINATOR'],
            'doctor role allowed' => [['employee_types_to_create_request' => ['DOCTOR']], true, 'DOCTOR'],
            'both empty lists' => [['speciality_types_request_allowed' => [], 'speciality_types_care_plan_activity_allowed' => []], false],
        ];
    }

    #[DataProvider('diagnosisCases')]
    public function test_diagnosis_rules(array $settings, array $codings, bool $expected): void
    {
        $this->assertSame($expected, MedicationProgramPicker::matchesDiagnosis(['medical_program_settings' => $settings], $codings));
    }

    public static function diagnosisCases(): array
    {
        $icd = ['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => 'E11'];
        $icpc = ['system' => 'eHealth/ICPC2/condition_codes', 'code' => 'T90'];

        return [
            'unrestricted' => [[], [], true],
            'null lists' => [['conditions_icd10_am_allowed' => null, 'conditions_icpc2_allowed' => null], [], true],
            'icd match with null icpc' => [['conditions_icd10_am_allowed' => ['E11'], 'conditions_icpc2_allowed' => null], [$icd], true],
            'icpc match with null icd' => [['conditions_icd10_am_allowed' => null, 'conditions_icpc2_allowed' => ['T90']], [$icpc], true],
            'wrong code' => [['conditions_icd10_am_allowed' => ['E10']], [$icd], false],
            'no primary coding' => [['conditions_icd10_am_allowed' => ['E11']], [], false],
            'wrong system cannot match code' => [['conditions_icd10_am_allowed' => ['E11']], [['system' => 'other/ICD10_AM', 'code' => 'E11']], false],
            'icd code in icpc list does not match' => [['conditions_icpc2_allowed' => ['E11']], [$icd], false],
        ];
    }
}

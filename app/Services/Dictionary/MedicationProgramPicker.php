<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Enums\User\Role;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class MedicationProgramPicker
{
    public const string DEFAULT_ID = '1318eabc-1a1a-42f6-8450-61e11c19eede';

    public static function resolve(?string $programId): string
    {
        return filled($programId) ? $programId : self::DEFAULT_ID;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $programs
     * @return Collection<int, array<string, mixed>>
     */
    public static function forCurrentUser(Collection $programs): Collection
    {
        $filtered = $programs->where('is_active', '=', true);
        $user = Auth::user();
        if (!$user) {
            return $filtered;
        }

        $roles = $user->allowedRoles;
        $mainSpeciality = $user->getMainSpeciality(legalEntity());
        $checkSpeciality = $user->hasAllowedRole(Role::SPECIALIST) || $user->hasAllowedRole(Role::DOCTOR);

        return $filtered->filter(static function (array $program) use ($roles, $mainSpeciality, $checkSpeciality): bool {
            $employeeTypes = Arr::get($program, 'medical_program_settings.employee_types_to_create_request', []);
            if (!empty($employeeTypes) && $roles->intersect($employeeTypes)->isEmpty()) {
                return false;
            }

            $requestSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_request_allowed');
            $carePlanSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_care_plan_activity_allowed');
            if (!$checkSpeciality || $requestSpecialities === null || $carePlanSpecialities === null) {
                return true;
            }

            return $mainSpeciality->intersect([...$requestSpecialities, ...$carePlanSpecialities])->isNotEmpty();
        });
    }

    /**
     * @param  array<string, mixed>  $program
     * @param  array<int, array{system?: string, code?: string}>  $codings
     */
    public static function matchesDiagnosis(array $program, array $codings): bool
    {
        $allowedIcd10 = Arr::get($program, 'medical_program_settings.conditions_icd10_am_allowed') ?? [];
        $allowedIcpc2 = Arr::get($program, 'medical_program_settings.conditions_icpc2_allowed') ?? [];
        if ($allowedIcd10 === [] && $allowedIcpc2 === []) {
            return true;
        }

        foreach ($codings as $coding) {
            $allowed = match ($coding['system'] ?? '') {
                'eHealth/ICD10_AM/condition_codes' => $allowedIcd10,
                'eHealth/ICPC2/condition_codes' => $allowedIcpc2,
                default => [],
            };
            if (in_array($coding['code'] ?? '', $allowed, true)) {
                return true;
            }
        }

        return false;
    }
}

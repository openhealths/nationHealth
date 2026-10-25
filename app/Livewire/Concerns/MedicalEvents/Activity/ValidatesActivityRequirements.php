<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Activity;

use App\Dto\CarePlan\ActivityRequirements;
use App\Enums\CarePlanRehabilitationCategory;
use App\Models\CarePlan;
use Illuminate\Support\Arr;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait ValidatesActivityRequirements
{
    protected function providingConditionsBlockReason(CarePlan $carePlan, ?array $program): ?string
    {
        if ($program === null || $program === []) {
            return null;
        }

        $allowed = ActivityRequirements::allowedConditions(
            Arr::get($program, 'medical_program_settings.providing_conditions_allowed')
                ?? Arr::get($program, 'medical_program_settings.PROVIDING_CONDITIONS_ALLOWED')
                ?? Arr::get($program, 'providing_conditions_allowed')
        );

        if ($allowed === []) {
            return null;
        }

        $termsOfService = ActivityRequirements::mapTermsOfService($carePlan->termsOfService);
        if ($termsOfService === null || $termsOfService === '') {
            return __('care-plan.providing_conditions_mismatch', [
                'allowed' => implode(', ', $allowed),
                'current' => '—',
            ]);
        }

        $normalizedTerms = strtoupper($termsOfService);
        $normalizedAllowed = array_map('strtoupper', $allowed);

        if (!in_array($normalizedTerms, $normalizedAllowed, true)) {
            return __('care-plan.providing_conditions_mismatch', [
                'allowed' => implode(', ', $allowed),
                'current' => $termsOfService,
            ]);
        }

        return null;
    }

    protected function rehabReasonReferenceBlockReason(CarePlan $carePlan, array $reasonReferences): ?string
    {
        if (!$this->isRehabCategory($carePlan)) {
            return null;
        }

        $hasReference = collect($reasonReferences)
            ->filter(function (mixed $ref): bool {
                if (is_string($ref)) {
                    return trim($ref) !== '';
                }

                if (is_array($ref)) {
                    return trim((string) ($ref['uuid'] ?? $ref['identifier']['value'] ?? $ref['value'] ?? '')) !== '';
                }

                return false;
            })
            ->isNotEmpty();

        if ($hasReference) {
            return null;
        }

        return __('care-plan.rehab_reason_reference_required');
    }

    protected function isRehabCategory(CarePlan $carePlan): bool
    {
        // Load outside the mapper: old plans may store category only in the FHIR relation.
        if (ActivityRequirements::category($carePlan->category, $carePlan) === null) {
            $carePlan->loadMissing('categoryConcept.coding');
        }
        $requirements = app(ObjectMapperInterface::class)->map($carePlan, ActivityRequirements::class);

        return CarePlanRehabilitationCategory::requiresReason($requirements->category);
    }
}

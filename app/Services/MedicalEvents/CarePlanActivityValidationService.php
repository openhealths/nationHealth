<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Models\CarePlan;
use Illuminate\Support\Arr;

/**
 * TV 3.10.2 activity create validations (providing conditions + rehab reason_reference).
 */
class CarePlanActivityValidationService
{
    /**
     * Rehab care plan categories that require activity.reason_reference (TV 3.10.2).
     *
     * @var list<string>
     */
    public const REHAB_CATEGORIES = [
        'CLASS 23',
        'CLASS 24',
        'CLASS 25',
        'CLASS_23',
        'CLASS_24',
        'CLASS_25',
        'CLASS23',
        'CLASS24',
        'CLASS25',
    ];

    /**
     * TV 3.10.2.4.4 — when program defines PROVIDING_CONDITIONS_ALLOWED, care plan
     * terms_of_service must be one of those values. Empty/missing list → any ToS allowed.
     *
     * @param  array<string, mixed>|null  $program  Medical program payload (with medical_program_settings)
     */
    public function providingConditionsBlockReason(CarePlan $carePlan, ?array $program): ?string
    {
        if ($program === null || $program === []) {
            return null;
        }

        $allowed = $this->normalizeAllowedList(
            Arr::get($program, 'medical_program_settings.providing_conditions_allowed')
                ?? Arr::get($program, 'medical_program_settings.PROVIDING_CONDITIONS_ALLOWED')
                ?? Arr::get($program, 'providing_conditions_allowed')
        );

        if ($allowed === []) {
            return null;
        }

        $termsOfService = $this->resolveTermsOfService($carePlan);
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

    /**
     * TV 3.10.2 — rehab CLASS 23–25 requires reason_reference (rehab observations / grounds).
     *
     * @param  list<string|array<string, mixed>>  $reasonReferences
     */
    public function rehabReasonReferenceBlockReason(CarePlan $carePlan, array $reasonReferences): ?string
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

    public function isRehabCategory(CarePlan $carePlan): bool
    {
        $category = $this->resolveCategoryCode($carePlan);
        if ($category === null || $category === '') {
            return false;
        }

        $normalized = $this->normalizeCategoryCode($category);

        foreach (self::REHAB_CATEGORIES as $rehab) {
            if ($normalized === $this->normalizeCategoryCode($rehab)) {
                return true;
            }
        }

        return false;
    }

    public function resolveCategoryCode(CarePlan $carePlan): ?string
    {
        $category = $carePlan->category;
        if (is_array($category)) {
            $category = $category['coding'][0]['code'] ?? ($category['text'] ?? null);
        }

        if (is_string($category) && trim($category) !== '') {
            return trim($category);
        }

        $conceptCode = $carePlan->categoryConcept?->coding?->first()?->code;
        if (is_string($conceptCode) && trim($conceptCode) !== '') {
            return trim($conceptCode);
        }

        return null;
    }

    public function resolveTermsOfService(CarePlan $carePlan): ?string
    {
        $tos = $carePlan->termsOfService;

        if (is_array($tos)) {
            $tos = $tos['coding'][0]['code'] ?? ($tos['text'] ?? null);
        }

        if (!is_string($tos) || trim($tos) === '') {
            return null;
        }

        return trim($tos);
    }

    /**
     * @return list<string>
     */
    private function normalizeAllowedList(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $code = $item['code'] ?? ($item['coding'][0]['code'] ?? null);
                if (is_string($code) && trim($code) !== '') {
                    $out[] = trim($code);
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeCategoryCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s_\-]+/', '', $code) ?? $code);
    }
}

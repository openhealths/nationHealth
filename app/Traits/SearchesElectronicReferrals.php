<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\eHealth\EHealth;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Throwable;

trait SearchesElectronicReferrals
{
    /**
     * Search electronic referrals in eHealth.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchElectronicReferrals(string $search): array
    {
        abort_unless(Auth::user()->can('searchElectronicReferrals', ServiceRequestRequest::class), 404);

        if ($this->personId === null || !isset($this->patientUuid) || $this->patientUuid === '') {
            return [];
        }

        try {
            $serviceRequests = $this->searchActiveByRequisition($this->patientUuid, $search);
        } catch (Throwable) {
            Session::flash('error', __('errors.ehealth.messages.electronic_referral_search_failed'));

            return [];
        }

        $services = collect($this->dictionaries['custom/services'] ?? []);
        $hasProcedureCategories = array_key_exists('eHealth/procedure_categories', $this->dictionaries);
        $hasDiagnosticReportCategories = array_key_exists('eHealth/diagnostic_report_categories', $this->dictionaries);
        $procedureCategories = array_keys($this->dictionaries['eHealth/procedure_categories'] ?? []);
        $diagnosticReportCategories = array_keys($this->dictionaries['eHealth/diagnostic_report_categories'] ?? []);
        $fallbackCategory = $hasProcedureCategories && !$hasDiagnosticReportCategories ? __('procedures.electronic_referral') : __('encounters.electronic_referral');

        return collect($serviceRequests)
            ->map(static function (array $referral) use ($services, $hasProcedureCategories, $hasDiagnosticReportCategories, $procedureCategories, $diagnosticReportCategories, $fallbackCategory): array {
                $serviceId = data_get($referral, 'code.identifier.value') ?? data_get($referral, 'code.coding.0.code');
                $service = $services->firstWhere('id', $serviceId);
                $category = data_get($referral, 'category.coding.0.code') ?? data_get($referral, 'category.0.coding.0.code');
                $remainingQuantity = data_get($referral, 'remaining_quantity.value');

                $result = [
                    'id' => $referral['id'] ?? '',
                    'requisition' => $referral['requisition'] ?? '',
                    'category' => $category ? __('care-plan.referral_category.'.$category) : $fallbackCategory,
                    'service' => $service,
                    'isExhausted' => $remainingQuantity !== null && (float) $remainingQuantity <= 0,
                ];

                if ($hasProcedureCategories) {
                    $result['isProcedureAllowed'] = $service !== null && in_array($service['category'] ?? null, $procedureCategories, true);
                }

                if ($hasDiagnosticReportCategories) {
                    $result['isDiagnosticReportAllowed'] = $service !== null && in_array($service['category'] ?? null, $diagnosticReportCategories, true);
                }

                return $result;
            })
            ->filter(static fn (array $referral): bool => $referral['id'] !== '' && $referral['requisition'] !== '')
            ->values()
            ->toArray();
    }

    /**
     * Search active Service Requests by requisition.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function searchActiveByRequisition(string $patientId, string $search): array
    {
        $search = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $search) ?? '');

        if ($search === '') {
            return [];
        }

        $serviceRequests = [];
        $page = 1;

        do {
            $response = EHealth::serviceRequest()->getBySearchParams($patientId, [
                'status' => 'active',
                'page' => $page,
            ]);

            $serviceRequests = [...$serviceRequests, ...$response->validate()];
            $page++;
        } while ($response->isNotLast());

        return collect($serviceRequests)
            ->filter(static function (array $serviceRequest) use ($search): bool {
                $requisition = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($serviceRequest['requisition'] ?? '')) ?? '');

                return $requisition !== '' && str_contains($requisition, $search);
            })
            ->values()
            ->toArray();
    }
}
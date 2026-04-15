<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaperReferralRepository extends BaseRepository
{
    /**
     * Sync paper referral data for provided model with comparison logic.
     *
     * @param  array  $data
     * @param  Model  $parent
     * @param  Model|null  $existing
     * @return mixed
     * @throws Throwable
     */
    public function sync(array $data, Model $parent, ?Model $existing = null): mixed
    {
        $existingPaperReferral = $existing?->paperReferral;

        if ($existingPaperReferral) {
            $existingPaperReferral->update($data);

            return $existingPaperReferral;
        }

        return $parent->paperReferral()->create($data);
    }

    /**
     * Store paper referral data for provided model.
     *
     * @param  array  $data
     * @param  Model  $parent
     * @return mixed
     * @throws Throwable
     */
    public function store(array $data, Model $parent): mixed
    {
        try {
            return DB::transaction(static function () use ($data, $parent) {
                $parent->paperReferral()->create([
                    'requisition' => $data['requisition'] ?? null,
                    'requester_legal_entity_name' => $data['requesterLegalEntityName'] ?? null,
                    'requester_legal_entity_edrpou' => $data['requesterLegalEntityEdrpou'],
                    'requester_employee_name' => $data['requesterEmployeeName'],
                    'service_request_date' => $data['serviceRequestDate'],
                    'note' => $data['note'] ?? null
                ]);
            });
        } catch (Exception $e) {
            Log::channel('db_errors')->error('Error saving paper referral report', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            throw $e;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TreatmentPlanActivity;

class TreatmentPlanActivityRepository
{
    public function getByTreatmentPlanId(int $treatmentPlanId)
    {
        return TreatmentPlanActivity::where('treatment_plan_id', $treatmentPlanId)->get();
    }

    public function create(array $data): TreatmentPlanActivity
    {
        return TreatmentPlanActivity::create($data);
    }

    public function update(TreatmentPlanActivity $activity, array $data): bool
    {
        return $activity->update($data);
    }
}

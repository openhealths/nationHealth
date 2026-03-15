<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TreatmentPlan;
use Illuminate\Database\Eloquent\Collection;

class TreatmentPlanRepository
{
    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return TreatmentPlan::where('legal_entity_id', $legalEntityId)
            ->with(['person', 'author.party'])
            ->latest()
            ->get();
    }
    
    public function findById(int $id): ?TreatmentPlan
    {
        return TreatmentPlan::with(['person', 'author.party', 'activities'])->find($id);
    }
    
    public function findByUuid(string $uuid): ?TreatmentPlan
    {
        return TreatmentPlan::with(['person', 'author.party', 'activities'])->where('uuid', $uuid)->first();
    }
    
    public function create(array $data): TreatmentPlan
    {
        return TreatmentPlan::create($data);
    }
    
    public function update(TreatmentPlan $treatmentPlan, array $data): bool
    {
        return $treatmentPlan->update($data);
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;
 
use App\Enums\CarePlanStatus;
use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CarePlanRepository
{
    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return CarePlan::where('legal_entity_id', $legalEntityId)
            ->with(['person', 'author.party', 'encounter.diagnoses.condition'])
            ->latest()
            ->get();
    }

    public function getByPersonId(int $personId): Collection
    {
        return CarePlan::where('person_id', $personId)
            ->with(['person', 'author.party', 'encounter.diagnoses.condition'])
            ->latest()
            ->get();
    }
    
    public function findById(int $id): ?CarePlan
    {
        return CarePlan::with(['person', 'author.party', 'activities'])->find($id);
    }
    
    public function findByUuid(string $uuid): ?CarePlan
    {
        return CarePlan::with(['person', 'author.party', 'activities'])->where('uuid', $uuid)->first();
    }
    
    public function create(array $data): CarePlan
    {
        return CarePlan::create($data);
    }
    
    public function update(CarePlan $carePlan, array $data): bool
    {
        return $carePlan->update($data);
    }

    public function updateById(int $id, array $data): bool
    {
        $carePlan = CarePlan::find($id);
        if (!$carePlan) {
            return false;
        }
        return $carePlan->update($data);
    }

    /**
     * Format Care Plan data into the proper FHIR schema for eHealth API requests.
     */
    public function formatCarePlanRequest(array $form, ?string $encounterUuid, array $encounterData, ?string $employeeUuid): array
    {
        return [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => CarePlanStatus::DRAFT->value,
            'title' => $form['title'] ?? 'План лікування',
        ];
    }

    public function syncCarePlans(array $validatedData, ?int $personId = null): void
    {
        // Simplified: removed actual sync logic
    }
}

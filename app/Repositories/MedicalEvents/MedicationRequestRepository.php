<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property MedicationRequestRequest $model
 */
class MedicationRequestRepository extends BaseRepository
{
    /**
     * Create medication request request in DB for patient with related dosage instructions.
     *
     * @param  array  $data
     * @param  int  $personId
     * @return int
     * @throws Throwable
     */
    public function store(array $data, int $personId): int
    {
        return DB::transaction(function () use ($data, $personId) {
            $request = $this->model->create([
                'uuid' => $data['uuid'] ?? $data['id'],
                'employee_id' => $data['employee_id'],
                'person_id' => $personId,
                'division_id' => $data['division_id'] ?? null,
                'status' => $data['status'],
                'request_number' => $data['request_number'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'ended_at' => $data['ended_at'] ?? null,
                'medication_id' => $data['medication_id'],
                'medication_qty' => $data['medication_qty'],
                'medication_program_id' => $data['medication_program_id'] ?? null,
                'intent' => $data['intent'] ?? 'order',
                'category' => $data['category'] ?? null,
                'based_on_id' => $data['based_on_id'] ?? null,
                'context_id' => $data['context_id'] ?? null,
                'priority' => $data['priority'] ?? null,
                'prior_prescription_id' => $data['prior_prescription_id'] ?? null,
                'container_dosage' => $data['container_dosage'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            if (!empty($data['dosage_instructions'])) {
                foreach ($data['dosage_instructions'] as $inst) {
                    $instruction = $request->dosageInstructions()->create([
                        'medication_request_id' => $inst['medication_request_id'] ?? null,
                        'sequence' => $inst['sequence'] ?? null,
                        'text' => $inst['text'] ?? null,
                        'patient_instruction' => $inst['patient_instruction'] ?? null,
                        'timing' => !empty($inst['timing']) ? json_encode($inst['timing']) : null,
                        'as_needed_boolean' => $inst['as_needed_boolean'] ?? false,
                        'route' => $inst['route'] ?? null,
                        'method' => $inst['method'] ?? null,
                        'dose_and_rate' => !empty($inst['dose_and_rate']) ? json_encode($inst['dose_and_rate']) : null,
                        'max_dose_per_period' => $inst['max_dose_per_period'] ?? null,
                        'max_dose_per_administration' => $inst['max_dose_per_administration'] ?? null,
                        'max_dose_per_lifetime' => $inst['max_dose_per_lifetime'] ?? null,
                    ]);

                    if (!empty($inst['dose_and_rate'])) {
                        foreach ($inst['dose_and_rate'] as $dr) {
                            $instruction->doseRate()->create([
                                'rate_ratio' => $dr['rate_ratio'] ?? null,
                            ]);
                        }
                    }
                }
            }

            return (int) $request->id;
        });
    }

    /**
     * Get medication request requests data that is related to the person.
     *
     * @param  int  $personId
     * @return array
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->with(['dosageInstructions.doseRate'])
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }

    public function findByUuid(string $uuid): ?MedicationRequestRequest
    {
        return $this->model->newQuery()->where('uuid', $uuid)->first();
    }

    public function sumIssuedQuantityByActivity(int $activityId): float
    {
        return (float) $this->model->newQuery()
            ->where('based_on_id', $activityId)
            ->where('status', '!=', 'entered-in-error')
            ->sum('medication_qty');
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\PersonCurrentDiagnosis;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property PersonCurrentDiagnosis $model
 */
class PersonCurrentDiagnosisRepository extends BaseRepository
{
    /**
     * Sync the current diagnoses of the patient's active episodes by comparing existing entries with API data
     * by index. The web service returns the whole set, so the entries left over from the previous response
     * no longer belong to an active episode and are removed.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData): void {
            $existingDiagnoses = $this->model->where($ownerColumn, $ownerId)
                ->withAllRelations()
                ->get();

            foreach ($validatedData as $index => $data) {
                $existing = $existingDiagnoses[$index] ?? null;

                $condition = $this->syncIdentifier($existing, $data['condition'], 'condition');
                $code = $this->syncCodeableConcept($existing, $data['code'], 'code');
                $role = $this->syncCodeableConcept($existing, $data['role'], 'role');

                $diagnosisData = [
                    $ownerColumn => $ownerId,
                    'condition_id' => $condition->id,
                    'code_id' => $code->id,
                    'role_id' => $role->id,
                    'rank' => $data['rank'] ?? null
                ];

                if ($existing) {
                    $existing->update($diagnosisData);
                } else {
                    $this->model->create($diagnosisData);
                }
            }

            foreach ($existingDiagnoses->slice(count($validatedData)) as $extra) {
                $extra->delete();
            }
        });
    }
}

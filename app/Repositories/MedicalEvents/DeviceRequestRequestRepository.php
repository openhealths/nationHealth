<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property DeviceRequestRequest $model
 */
class DeviceRequestRequestRepository extends BaseRepository
{
    /**
     * Create or update device request request in DB for patient.
     *
     * @param  array  $data
     * @param  int  $personId
     * @return int
     * @throws Throwable
     */
    public function store(array $data, int $personId): int
    {
        return DB::transaction(function () use ($data, $personId) {
            $request = $this->model->updateOrCreate(
                ['uuid' => $data['uuid'] ?? $data['id']],
                [
                    'employee_id' => $data['employee_id'],
                    'person_id' => $personId,
                    'division_id' => $data['division_id'] ?? null,
                    'status' => $data['status'],
                    'request_number' => $data['request_number'] ?? null,
                    'started_at' => $data['started_at'] ?? null,
                    'ended_at' => $data['ended_at'] ?? null,
                    'device_id' => $data['device_id'],
                    'quantity' => $data['quantity'] ?? 1,
                    'program_id' => $data['program_id'] ?? null,
                    'intent' => $data['intent'] ?? 'order',
                    'category' => $data['category'] ?? null,
                    'based_on_id' => $data['based_on_id'] ?? null,
                    'context_id' => $data['context_id'] ?? null,
                    'priority' => $data['priority'] ?? null,
                    'note' => $data['note'] ?? null,
                    'supporting_info' => $data['supporting_info'] ?? null,
                ]
            );

            return (int) $request->id;
        });
    }

    /**
     * Get device request requests data related to the person.
     *
     * @param  int  $personId
     * @return array
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\DetectedIssue;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Enums\DetectedIssue\Status;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property DetectedIssue $model
 */
class DetectedIssueRepository extends BaseRepository
{
    /**
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): void {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($data, $ownerColumn, $ownerId) {
            foreach ($data as $datum) {
                $subject = $this->storeIdentifier($datum['subject']);
                $encounter = $this->storeIdentifier($datum['encounter']);
                $recorder = $this->storeIdentifier($datum['recorder']);
                $author = data_get($datum, 'author.identifier.value') ? $this->storeIdentifier($datum['author']) : null;
                $implicated = data_get($datum, 'implicated.identifier.value') ? $this->storeIdentifier($datum['implicated']) : null;
                $basedOn = data_get($datum, 'basedOn.identifier.value') ? $this->storeIdentifier($datum['basedOn']) : null;
                $code = isset($datum['code']) ? Repository::codeableConcept()->store($datum['code']) : null;
                $reportOrigin = isset($datum['reportOrigin']) ? Repository::codeableConcept()->store($datum['reportOrigin']) : null;
                $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    $ownerColumn => $ownerId,
                    'status' => $datum['status'],
                    'subject_id' => $subject->id,
                    'encounter_id' => $encounter->id,
                    'author_id' => $author?->id,
                    'code_id' => $code?->id,
                    'detail' => $datum['detail'] ?? null,
                    'identified_date_time' => $datum['identifiedDateTime'] ?? null,
                    'implicated_id' => $implicated?->id,
                    'based_on_id' => $basedOn?->id,
                    'primary_source' => $datum['primarySource'],
                    'report_origin_id' => $reportOrigin?->id,
                    'recorder_id' => $recorder->id
                ]);
            }
        });
    }

    public function get(string $encounterUuid): array
    {
        return $this->model
            ->withAllRelations()
            ->forEncounter($encounterUuid)
            ->get()
            ->toArray();
    }

    /**
     * Get previous detected issues for a patient's device.
     *
     * @param  Person|Preperson  $patient
     * @param  string  $deviceUuid
     * @return array
     */
    public function getByDevice(Person|Preperson $patient, string $deviceUuid): array
    {
        return $this->model
            ->forPatient($patient)
            ->whereNot('status', Status::ENTERED_IN_ERROR->value)
            ->whereHas('subject', static fn ($query) => $query->where('value', $deviceUuid))
            ->with(['subject', 'code.coding'])
            ->orderByDesc('identified_date_time')
            ->get()
            ->map(static function (DetectedIssue $issue): array {
                $identifiedDateTime = $issue->identifiedDateTime;

                return [
                    'uuid' => $issue->uuid,
                    'subjectId' => $issue->subject?->value,
                    'code' => $issue->code?->coding->first()?->code,
                    'identifiedDate' => $identifiedDateTime ? explode(' ', $identifiedDateTime)[0] : null
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get detected issues by UUIDs for displaying them in a select.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $uuids
     * @return array
     */
    public function getByUuidsForSelect(Person|Preperson $patient, array $uuids): array
    {
        if (!$uuids) {
            return [];
        }

        return $this->model
            ->forPatient($patient)
            ->whereIn('uuid', $uuids)
            ->with('code.coding')
            ->get()
            ->map(static function (DetectedIssue $issue): array {
                $identifiedDateTime = $issue->identifiedDateTime;

                return [
                    'uuid' => $issue->uuid,
                    'code' => $issue->code?->coding->first()?->code,
                    'identifiedDate' => $identifiedDateTime ? explode(' ', $identifiedDateTime)[0] : null
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($validatedData, $ownerColumn, $ownerId) {
            $existingIssues = $this->model
                ->whereIn('uuid', collect($validatedData)->pluck('uuid')->toArray())
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingIssues->get($data['uuid']);
                $subject = $this->syncIdentifier($existing, $data['subject'], 'subject');
                $encounter = $this->syncIdentifier($existing, $data['encounter'], 'encounter');
                $recorder = $this->syncIdentifier($existing, $data['recorder'], 'recorder');
                $author = !empty(data_get($data, 'author.identifier.value')) ? $this->syncIdentifier($existing, $data['author'], 'author') : null;
                $implicated = !empty(data_get($data, 'implicated.identifier.value')) ? $this->syncIdentifier($existing, $data['implicated'], 'implicated') : null;
                $basedOn = !empty(data_get($data, 'based_on.identifier.value')) ? $this->syncIdentifier($existing, $data['based_on'], 'basedOn') : null;
                $code = isset($data['code']) ? $this->syncCodeableConcept($existing, $data['code'], 'code') : null;
                $reportOrigin = isset($data['report_origin']) ? $this->syncCodeableConcept($existing, $data['report_origin'], 'reportOrigin') : null;
                $issueData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'],
                    'subject_id' => $subject->id,
                    'encounter_id' => $encounter->id,
                    'author_id' => $author?->id,
                    'code_id' => $code?->id,
                    'detail' => $data['detail'] ?? null,
                    'identified_date_time' => $data['identified_date_time'] ?? null,
                    'implicated_id' => $implicated?->id,
                    'based_on_id' => $basedOn?->id,
                    'primary_source' => $data['primary_source'],
                    'report_origin_id' => $reportOrigin?->id,
                    'recorder_id' => $recorder->id
                ];

                if ($existing) {
                    $existing->update($issueData);

                    continue;
                }

                $this->model->create(array_merge(['uuid' => $data['uuid']], $issueData));
            }
        });
    }

    private function storeIdentifier(array $reference)
    {
        $identifier = Repository::identifier()->store($reference['identifier']['value']);

        Repository::codeableConcept()->attach($identifier, $reference);

        return $identifier;
    }
}
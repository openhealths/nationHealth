<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Models\DeclarationRequest;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\License;
use App\Models\Person\Person;
use App\Models\Relations\Address;
use App\Models\Relations\AuthenticationMethod;
use App\Models\Relations\Document;
use App\Models\Relations\Party;
use App\Models\Relations\Phone;
use App\Models\ReorganizationEmployeeDeclaration;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class DeclarationRequestRepository
{
    /**
     * Store data during first request or local saving.
     *
     * @param  array  $validatedData
     * @return DeclarationRequest
     */
    public function store(array $validatedData): DeclarationRequest
    {
        $validatedData['legal_entity_id'] = legalEntity()->id;
        $validatedData = $this->mapUuidsToIds($validatedData);

        return DeclarationRequest::create($validatedData);
    }

    /**
     * Update previously created request.
     *
     * @param  int  $id
     * @param  array  $validatedData
     * @return void
     */
    public function updateRequest(int $id, array $validatedData): void
    {
        $validatedData = $this->mapUuidsToIds($validatedData);
        DeclarationRequest::where('id', $id)->update($validatedData);
    }

    /**
     * Update records based on response from EHealth.
     *
     * @param  int  $id
     * @param  array  $responseData
     * @return void
     */
    public function update(int|string $id, array $responseData): void
    {
        $responseData['uuid'] = $responseData['id'];
        unset($responseData['id'], $responseData['declaration_id']);
        $responseData = $this->mapUuidsToIds($responseData, true);

        if (\is_int($id)) {
            DeclarationRequest::where('id', $id)->update($responseData);
        } else {
            DeclarationRequest::where('uuid', $id)->update($responseData);
        }
    }

    /**
     * Update status by provided UUID.
     *
     * @param  string  $uuid
     * @param  array  $data
     * @return void
     */
    public function updateAfterApprove(string $uuid, array $data): void
    {
        DeclarationRequest::where('uuid', $uuid)->update(
            [
                'status' => $data['status'],
                'data_to_be_signed' => $data['data_to_be_signed'],
                'sync_status' => JobStatus::PARTIAL->value
            ]
        );
    }

    /**
     * Update status column by provided ID.
     *
     * @param  int  $id
     * @param  string  $status
     * @return void
     */
    public function updateStatus(int $id, string $status): void
    {
        DeclarationRequest::where('id', $id)->update([
            'status' => $status,
            'sync_status' => JobStatus::PARTIAL->value
        ]);
    }

    /**
     * Update status and status reason after reject.
     *
     * @param  string  $uuid
     * @param  string  $status
     * @param  string  $statusReason
     * @return void
     */
    public function updateStatuses(string $uuid, string $status, string $statusReason): void
    {
        DeclarationRequest::where('uuid', $uuid)->update([
            'status' => $status,
            'status_reason' => $statusReason,
            'sync_status' => JobStatus::PARTIAL
        ]);
    }

    /**
     * Sync all persons data with addresses, authentication methods, documents and phones.
     *
     * @param  array  $personData
     * @return bool
     */
    public function syncPersonData(array $personData): bool
    {
        $person = Person::where('uuid', $personData['id'] ?? $personData['uuid'])
            ->with(['addresses', 'authenticationMethods', 'documents', 'phones', 'confidantPersons'])
            ->firstOrFail();

        $isUpdated = false;

        $isUpdated |= $this->syncJsonField($person, 'emergency_contact', $personData['emergency_contact']);

        // Emergency contact has been updated, so delete it.
        unset($personData['emergency_contact']);
        $isUpdated |= $this->syncBasicData($person, $personData);

        $isUpdated |= $this->syncRelatedData($person, 'addresses', $personData['addresses'], Address::class);
        $isUpdated |= $this->syncRelatedData(
            $person,
            'authenticationMethods',
            $personData['authentication_methods'],
            AuthenticationMethod::class
        );
        $isUpdated |= $this->syncRelatedData($person, 'documents', $personData['documents'], Document::class);
        $isUpdated |= $this->syncRelatedData($person, 'phones', $personData['phones'], Phone::class);

        return (bool)$isUpdated;
    }

    /**
     * Sync the employee's position.
     *
     * @param  array  $employeeData
     * @return bool
     */
    public function syncEmployeeData(array $employeeData): bool
    {
        $updated = Employee::where('uuid', $employeeData['id'])
            ->where('position', '!=', $employeeData['position'])
            ->update(['position' => $employeeData['position']]);

        return $updated > 0;
    }

    /**
     * Sync party data with phones.
     *
     * @param  array  $partyData
     * @return bool
     */
    public function syncPartyData(array $partyData): bool
    {
        $party = Party::where('uuid', $partyData['id'])
            ->with('phones')
            ->select(['id', 'first_name', 'last_name', 'second_name', 'tax_id'])
            ->firstOrFail();
        $isUpdated = false;

        $isUpdated |= $this->syncBasicData($party, $partyData);

        $isUpdated |= $this->syncRelatedData($party, 'phones', $partyData['phones'], Phone::class);

        return (bool)$isUpdated;
    }

    /**
     * Sync division data with addresses and phones.
     *
     * @param  array  $divisionData
     * @return bool
     */
    public function syncDivisionData(array $divisionData): bool
    {
        $division = Division::where('uuid', $divisionData['id'])
            ->with(['addresses', 'phones'])
            ->select(['id', 'email', 'external_id', 'name', 'type'])
            ->firstOrFail();
        $isUpdated = false;
        unset($divisionData['legal_entity_id']);

        $isUpdated |= $this->syncBasicData($division, $divisionData);

        $isUpdated |= $this->syncRelatedData($division, 'addresses', $divisionData['addresses'], Address::class);
        $isUpdated |= $this->syncRelatedData($division, 'phones', $divisionData['phones'], Phone::class);

        return (bool)$isUpdated;
    }

    /**
     * Sync legal entity data with addresses, phones and licences.
     *
     * @param  array  $legalEntityData
     * @return bool
     */
    public function syncLegalEntityData(array $legalEntityData): bool
    {
        $legalEntity = LegalEntity::where('uuid', $legalEntityData['id'])
            ->with(['addresses', 'phones', 'licenses'])
            ->firstOrFail();
        $isUpdated = false;

        $isUpdated |= $this->syncBasicData($legalEntity, $legalEntityData);

        $legalEntityData['edr'] = [
            'legal_form' => $legalEntityData['legal_form'],
            'name' => $legalEntityData['name'],
            'public_name' => $legalEntityData['public_name'],
            'short_name' => $legalEntityData['short_name']
        ];
        $isUpdated |= $this->syncJsonField($legalEntity, 'edr', $legalEntityData['edr']);

        // The legal_entity_id column has been removed to avoid updating it.
        $licenses = collect($legalEntityData['licenses'])
            ->map(function (array $license) use ($legalEntity) {
                $license['legal_entity_id'] = $legalEntity->id;

                return $license;
            })
            ->toArray();
        $isUpdated |= $this->syncRelatedData($legalEntity, 'licenses', $licenses, License::class);
        $isUpdated |= $this->syncRelatedData($legalEntity, 'addresses', $legalEntityData['addresses'], Address::class);
        $isUpdated |= $this->syncRelatedData($legalEntity, 'phones', $legalEntityData['phones'], Phone::class);

        return (bool)$isUpdated;
    }

    /**
     * Update fillable rows if they differ.
     *
     * @param  Model  $model
     * @param  array  $modelData
     * @return bool
     */
    protected function syncBasicData(Model $model, array $modelData): bool
    {
        $fillable = $model->getFillable();

        $attributes = collect($modelData)
            ->only($fillable)
            ->toArray();

        $model->fill($attributes);

        if ($model->isDirty()) {
            $model->save();

            return true;
        }

        return false;
    }

    /**
     * Sync JSON field if they differ.
     *
     * @param  Model  $model
     * @param  string  $field
     * @param  array  $newData
     * @return bool
     */
    protected function syncJsonField(Model $model, string $field, array $newData): bool
    {
        $current = collect($model->{$field} ?? []);
        $incoming = collect($newData);

        $merged = $current->merge($incoming);

        $sortedCurrent = Arr::sortArrayRecursive($model->{$field});
        $sortedMerged = Arr::sortArrayRecursive($merged->toArray());

        if ($sortedMerged !== $sortedCurrent) {
            $model->{$field} = $merged->toArray();
            $model->save();

            return true;
        }

        return false;
    }

    /**
     * Sync related data using fillable for comparison.
     *
     * @param  Model  $model
     * @param  string  $relationName
     * @param  array  $incomingData
     * @param  string  $modelClass  Model to sync with
     * @return bool
     */
    public function syncRelatedData(
        Model $model,
        string $relationName,
        array $incomingData,
        string $modelClass
    ): bool {
        if (empty($incomingData)) {
            return false;
        }

        $fillable = new $modelClass()->getFillable();
        $changed = false;

        // If the relation data is empty, create it.
        if ($model->{$relationName}->isEmpty()) {
            $attributes = collect($incomingData)
                ->map(function (array $item) use ($fillable) {
                    return collect($item)
                        ->only($fillable)
                        ->toArray();
                })
                ->toArray();

            $model->$relationName()->createMany($attributes);

            return true;
        }

        // Update existing data if a difference is found.
        foreach ($incomingData as $incoming) {
            // Find by type
            $existing = $model->{$relationName}->firstWhere('type', $incoming['type']);

            $data = collect($incoming)
                ->only($fillable)
                ->map(fn (mixed $value) => $value instanceof BackedEnum ? $value->value : $value)
                ->toArray();

            if ($existing) {
                // Convert Carbon object to string, and Enum to string
                $existingData = collect($existing->only($fillable))
                    ->map(function ($value) {
                        if ($value instanceof Carbon) {
                            return $value->toDateString();
                        }

                        if ($value instanceof BackedEnum) {
                            return $value->value;
                        }

                        return $value;
                    })
                    ->toArray();

                $diff = array_diff_assoc($data, $existingData);
                if (!empty($diff)) {
                    $existing->update($diff);
                    $changed = true;
                }
            } else {
                $model->$relationName()->create($data);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Map uuids to ids for setting relationship.
     *
     * @param  array  $data
     * @param  bool  $includeLegalEntity
     * @return array
     */
    private function mapUuidsToIds(array $data, bool $includeLegalEntity = false): array
    {
        $data['employee_id'] = Employee::where('uuid', $data['employee_id'])
            ->pluck('id')
            ->firstOrFail();
        $data['person_id'] = Person::where('uuid', $data['person_id'])->pluck('id')->firstOrFail();
        $data['division_id'] = Division::where('uuid', $data['division_id'])
            ->pluck('id')
            ->firstOrFail();

        if ($includeLegalEntity && isset($data['legal_entity_id'])) {
            $data['legal_entity_id'] = LegalEntity::where('uuid', $data['legal_entity_id'])
                ->pluck('id')
                ->firstOrFail();
        }

        $data['parent_declaration_uuid'] = Arr::pull($data, 'parent_declaration_id', null);

        return $data;
    }

    public function checkIfNeedToResign(string $personUuid): bool
    {
        return DeclarationRequest::whereNotNull('parent_declaration_uuid')
            ->whereIn('parent_declaration_uuid', ReorganizationEmployeeDeclaration::where('person_uuid', $personUuid)->selectRaw('declaration_uuid::text'))
            ->whereIn('status', [Status::NEW->value, Status::APPROVED->value])
            ->exists();
    }
}

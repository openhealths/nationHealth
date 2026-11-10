<?php

declare(strict_types=1);

namespace App\Repositories;

use Arr;
use Exception;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Phone;
use App\Models\Relations\Address;
use App\Dto\Division\DivisionGetMany;
use App\Dto\Division\Model as DivisionData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DivisionRepository
{
    /**
     * Save a mapped division and replace its relations in one transaction.
     *
     * @param  LegalEntity  $legalEntity  The LegalEntity model instance associated with the division
     * @return Division Saved and reloaded Division model instance with updated relations
     * @throws \Throwable
     */
    public function saveMappedDivision(DivisionData $data, Division $division, LegalEntity $legalEntity): Division
    {
        return DB::transaction(function () use ($data, $division, $legalEntity): Division {
            $data->toModel($division);
            $division->legalEntity()->associate($legalEntity);
            $division->saveOrFail();

            $division->addresses()->delete();
            $division->phones()->delete();

            foreach ($data->addresses as $address) {
                if (!$division->addresses()->save($address->toModel())) {
                    throw new \RuntimeException('Cannot save division address.');
                }
            }

            foreach ($data->phones as $phone) {
                if (!$division->phones()->save($phone->toModel())) {
                    throw new \RuntimeException('Cannot save division phone.');
                }
            }

            return $division->refresh()->load(['addresses', 'phones']);
        });
    }

    /** @param list<array<string, mixed>> $rows */
    public function upsertDivisions(array $rows): void
    {
        Division::upsert($rows, uniqueBy: ['uuid'], update: [
            'uuid',
            'external_id',
            'name',
            'type',
            'mountain_group',
            'location',
            'email',
            'working_hours',
            'legal_entity_id',
            'status',
            'dls_id',
            'dls_verified',
            'ehealth_inserted_at',
            'inserted_by',
            'ehealth_updated_at',
            'updated_by',
        ]);
    }

    /** Persist a division list and its relationships in one transaction. */
    public function saveDivisionsList(DivisionGetMany $divisions, LegalEntity $legalEntity): void
    {
        if ($divisions->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($divisions, $legalEntity): void {
            $this->upsertDivisions($divisions->toUpsertRows($legalEntity->id));

            $divisionIds = $this->getDivisionIds($divisions->uuids());
            $relations = $divisions->toRelationshipRows($divisionIds, now()->toDateTimeString());

            $this->replaceDivisionRelations(array_values($divisionIds), $relations['addresses'], $relations['phones']);
        });
    }

    /**
     * Set status for specific action (for activate or deactivate)
     *
     * @param  \App\Models\Division  $division
     * @param  string  $status
     * @return void
     * @throws \Exception
     */
    public function setAction(Division $division, string $status): void
    {
        try {
            $division->setAttribute('status', $status)->save();

            $division->refresh();

        } catch (Exception $err) {
            throw new Exception($err->getMessage());
        }
    }

    /**
     * Create instance of Division cclass
     *
     * @param  array  $responseData  // The data array suitable to do fill on Division Model
     * @return Division|null
     */
    public function createOrUpdate(array $responseData): ?Division
    {
        $id = $responseData['id'] ?? null;
        $uuid = $responseData['uuid'] ?? null;

        Arr::forget($responseData, [
            'id',
            'phones',
            'addresses',
            'created_at',
            'updated_at',
            'legal_entity_uuid'
        ]);

        if (!$id && !$uuid) {
            $division = new Division();
        } else {
            /*
             * At first try to get division by 'uuid'. But even if $uuid is present
             * the DB may contains record without DB (DRAFT case).
             * In that case the code below returns null
            */
            $division = $uuid ? Division::where('uuid', $uuid)->first() : null;

            // If $division is null, trying to get the record from the DB by 'id'
            $division ??= Division::find($id); // For the DRAFT records
        }

        if (!$division) {
            return null;
        }

        $division->fill($responseData);

        $division->setAttribute('external_id', $responseData['external_id'] ?? null);
        $division->setAttribute('status', $responseData['status']);

        return $division;
    }

    /**
     * TODO: need more testing on further PRs
     * Create instance of Division model and save it's data to the DB (with all it's relations aka: Address, Phone and LegalEntity)
     *
     * @param  array  $divisionData
     * @param  \App\Models\LegalEntity  $legalEntity
     * @return Division
     */
    public function syncDivisionData(array $divisionData, LegalEntity $legalEntity): Division
    {
        $division = $this->createOrUpdate($divisionData);

        if ($division->update()) {
            $division->refresh();
        }

        Repository::address()->syncAddresses($division, $divisionData['addresses']);

        Repository::phone()->syncPhones($division, $divisionData['phones']);

        return $division;
    }

    /**
     * Creates a relation between a Division and a LegalEntity
     *
     * @param  Division  $division  The division to create the relation for
     * @param  LegalEntity  $legalEntity  The legal entity to relate to the division
     * @return Division Returns the updated Division instance
     */
    public function createLegalEntityRelation(Division $division, LegalEntity $legalEntity): Division
    {
        return $division->legalEntity()->associate($legalEntity);
    }

    /**
     * TODO: it suppose to be useful when do sync via jobs. If not, then just will remove it.
     * Creates a relation between a Division and such models as Employee, EmployeeRequest etc.
     *
     * @param  Division  $division  The division entity to create relation for
     * @param  Object  $model  The model to create relation with
     * @return void
     */
    public function createRelationForDivision(Division $division, Object $model)
    {
        if (!$model) {
            return;
        }

        if ($model?->division_id === $division->id) {
            return;
        }

        $model->division()->associate($division);

        if (Schema::hasColumn($model->getTable(), 'division_uuid')) {
            $model->division_uuid = $division->uuid;
        }

        $model->save();
    }

    /**
     * Retrieves a collection of division IDs keyed by their UUIDs.
     *
     * @param  list<string>  $uuids
     * @return array<string, int> UUIDs mapped to local primary keys.
     */
    public function getDivisionIds(array $uuids): array
    {
        return Division::whereIn('uuid', $uuids)->pluck('id', 'uuid')->all();
    }

    /**
     * Synchronizes address and phone relations for multiple divisions in bulk.
     *
     * This method performs a high-performance sync by first deleting all existing
     * address and phone records for the given divisions and then bulk-inserting
     * the new relational data. It is designed to be used within a transaction.
     *
     * @param  list<int>  $divisionIds
     * @param  list<array<string, mixed>>  $addressesData
     * @param  list<array<string, mixed>>  $phonesData
     */
    public function replaceDivisionRelations(array $divisionIds, array $addressesData, array $phonesData): void
    {
        // Remove all addresses records belongs to specified divisions
        Address::where('addressable_type', Division::class)
            ->whereIn('addressable_id', $divisionIds)
            ->delete();

        if (!empty($addressesData)) {
            Address::insert($addressesData);
        }

        // Remove all phones records belongs to specified divisions
        Phone::where('phoneable_type', Division::class)
            ->whereIn('phoneable_id', $divisionIds)
            ->delete();

        if (!empty($phonesData)) {
            Phone::insert($phonesData);
        }
    }
}

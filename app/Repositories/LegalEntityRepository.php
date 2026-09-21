<?php

declare(strict_types=1);

namespace App\Repositories;

use Throwable;
use Exception;
use App\Core\Arr;
use App\Models\User;
use App\Enums\Status;
use App\Models\Client;
use App\Enums\User\Role;
use App\Models\Connection;
use App\Models\LegalEntity;
use App\Traits\LogsExceptions;
use App\Models\LegalEntityType;
use App\Enums\LegalEntity\States;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\LegalEntity\ConnectionStatus;
use Illuminate\Database\Eloquent\Collection;

class LegalEntityRepository
{
    use LogsExceptions;

    /**
     * Initialize a legal entity with its initial connection data.
     *
     * @param  string  $uuid  The legal entity UUID.
     * @param  string  $name  The legal entity name.
     * @param  string|null  $secret  The optional client secret.
     *
     * @return LegalEntity|null The created legal entity, or null when initialization fails.
     */
    public function initLegalEntity(string $uuid, int $type, string $name, ?string $secret = null): ?LegalEntity
    {
        try {
            DB::transaction(function () use ($uuid, $type, $name, $secret) {
                new LegalEntity([
                    'uuid' => $uuid,
                    'client_id' => $uuid,
                    'client_secret' => $secret,
                    'status' => States::NEW->value,
                    'legal_entity_type_id' => $type,
                    'edr' => [
                        'name' => $name
                    ]
                ])->save();
            });
        } catch (Throwable $err) {
            return null;
        }

        return LegalEntity::where('uuid', $uuid)->first();
    }

    /**
     * Get all legal entities founded in the system.
     * Reformat it data to the array looks like:
     * [
     *  ['<uuid-1>', 'Legal Entity 1 Name']
     *  ['<uuid-2>', 'Legal Entity 2 Name']
     * ]
     *
     * @param  array  $legalEntityIds  // Optional filter by specific legal entity IDs
     * @return array
     */
    public function getLegalEntitiesList(array $legalEntityIds = []): array
    {
        $typesById = LegalEntityType::pluck('name', 'id');

        // Get list of Legal Entities grouped by their name
        $legalEntityList = LegalEntity::listByFields()
            ->when(!empty($legalEntityIds), fn (Builder $query) => $query->whereIn('id', $legalEntityIds))
            ->get()
            ->groupBy(fn (LegalEntity $item) => data_get($item, 'edr.name') ?: (data_get($item, 'edr.public_name') ?? $item->uuid))
            ->map(fn (Collection $group) => $group->each->makeHidden(['edr'])) // Hide unnecessary fields
            ->toArray();

        $result = [];

        foreach (array_keys($legalEntityList) as $key) {
            // Count of Legal Entities with the same name
            $legalEntitiesCount = count($legalEntityList[$key]);

            foreach ($legalEntityList[$key] as $data) {
                $legalEntityTypeName = $typesById[$data['legalEntityTypeId']] ?? '';
                $name = $key;

                // If there are multiple Legal Entities with the same name - add Legal Entity Type to distinguish them
                if ($legalEntitiesCount > 1) {
                    $name .= " <{$legalEntityTypeName}>";
                }

                if ($data['status'] === Status::REORGANIZED->value) {
                    $name .= " (" . Status::REORGANIZED->value . ")";
                }

                if ($data['status'] === Status::NEW->value) {
                    $name .= " (" . Status::CONNECTED->value . ")";
                }

                $result[] = ['id' => $data['id'], 'uuid' => $data['uuid'], 'name' => $name];
            }
        }

        return $result;
    }

    /**
     * Save legators for the given legal entity.
     *
     * Deletes existing legators and inserts the new ones derived from the provided data.
     *
     * @param  LegalEntity  $legalEntity  The legal entity to associate legators with.
     * @param  array  $data  Array of legator data from the eHealth API response.
     *                       Each entry is expected to contain:
     *                       - merged_from_legal_entity (array): { uuid, name, edrpou }
     *                       - is_active (bool)
     *                       - reason (string)
     *                       - reason_date (string|null)
     *                       - type (string)
     *                       - ehealth_inserted_at (string)
     *                       - inserted_by (string)
     * @return void
     */
    public function saveLegators(LegalEntity $legalEntity, array $data): void
    {
        $legalEntityId = $legalEntity->id;

        $legatorsData = [];

        foreach ($data as $legator) {
            $legatorsData[] = [
                'legal_entity_id' => $legalEntityId,
                'uuid' => $legator['merged_from_legal_entity']['uuid'],
                'name' => $legator['merged_from_legal_entity']['name'],
                'is_active' => $legator['is_active'],
                'reason' => $legator['reason'],
                'reason_date' => $legator['reason_date'] ?? null,
                'edrpou' => $legator['merged_from_legal_entity']['edrpou'],
                "type" => $legator['type'],
                "ehealth_inserted_at" => $legator['ehealth_inserted_at'],
                "inserted_by" => $legator['inserted_by'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($legatorsData)) {
            $legalEntity->legators()->upsert(
                $legatorsData,
                ['uuid', 'legal_entity_id'], // unique keys
                ['edrpou', 'name', 'is_active', 'type', 'reason', 'reason_date', 'ehealth_inserted_at', 'inserted_by', 'updated_at'] // fields to update if record exists
            );
        }
    }

    /**
     * Handles the owner change process for the legal entity.
     *
     * Removes the OWNER and/or REORGANIZATION_OWNER roles from the current authenticated user,
     * detaches their employee records from the pivot table, and sets status to STOPPED
     * for the OWNER's employee record in the employees table.
     * Logs out the old owner and redirects to login.
     *
     * @return void
     */
    public function disableOldOwner(User $oldOwner, ?LegalEntity $legalEntity = null): void
    {
        $legalEntity ??= legalEntity();

        setPermissionsTeamId($legalEntity->id);

        $partyUsers = User::where('party_id', $oldOwner->party_id)->get();
        $partyUsers->loadMissing(['roles', 'permissions', 'party']);

        $partyUserIds = $partyUsers->pluck('id');

        Auth::shouldUse('web');

        // Remove the OWNER's roles for all party users via web guard
        $partyUsers->each->removeRole([Role::OWNER, Role::REORGANIZATION_OWNER]);

        Auth::shouldUse('ehealth');

        // Remove the OWNER's roles for all party users via 'ehealth' guard
        $partyUsers->each->removeRole([Role::OWNER, Role::REORGANIZATION_OWNER]);

        Employee::where('legal_entity_id', $legalEntity->id)
            ->whereIn('employee_type', [Role::OWNER->value, Role::REORGANIZATION_OWNER->value])
            ->where('party_id', $oldOwner->party_id)
            ->each(fn ($employee) => $employee->users()->detach($partyUserIds));

        // Set the employee status to STOPPED for the OWNER's employee record in the employees table
        // Because the OWNER's employee record is no longer associated with a user
        Employee::where('legal_entity_id', $legalEntity->id)
            ->whereIn('employee_type', [Role::OWNER->value, Role::REORGANIZATION_OWNER->value])
            ->where('party_id', $oldOwner->party_id)
            ->where('user_id', $oldOwner->id)
            ->update(['status' => Status::STOPPED->value]);

        Log::info(__('** OWNER CHANGED **', [], 'en'), ['old_owner_id' => $oldOwner->id, 'legal_entity_id' => $legalEntity->id]);
    }

    /**
     * Synchronize connections from eHealth with the local database.
     *
     * @param  array  $connections  Array of connection data from eHealth API.
     *                               Each entry must contain:
     *                               - uuid: Connection UUID
     *                               - client_uuid: Client UUID
     *                               - consumer_uuid: Consumer UUID
     *                               - redirect_uri: Redirect URI
     *                               - secret: Optional secret
     *                               - ehealth_inserted_at: Timestamp from eHealth
     *                               - ehealth_updated_at: Timestamp from eHealth
     *
     * @param  LegalEntity|null  $legalEntity  Optional LegalEntity instance. Defaults to the current legal entity.
     *
     * @return bool  Returns true if synchronization was successful, false otherwise.
     *
     * @throws Exception  If a database error occurs during synchronization.
     */
    public function syncConnections(array $connections, ?LegalEntity $legalEntity = null): bool
    {
        $legalEntity ??= legalEntity();

        $connectionsData = [];

        $legalEntityIdsByUuid = LegalEntity::whereIn('uuid', array_column($connections, 'client_uuid'))->pluck('id', 'uuid');

        foreach ($connections as $connection) {
            $connectionsData[] = [
                'legal_entity_id' => $legalEntityIdsByUuid[$connection['client_uuid']],
                'uuid' => $connection['uuid'],
                'client_uuid' => $connection['client_uuid'],
                'consumer_uuid' => $connection['consumer_uuid'],
                'redirect_uri' => $connection['redirect_uri'],
                'secret' => $connection['secret'] ?? null,
                "ehealth_inserted_at" => $connection['ehealth_inserted_at'],
                "ehealth_updated_at" => $connection['ehealth_updated_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($connectionsData)) {
            try {
                DB::transaction(function () use ($connectionsData, $legalEntity) {
                    // Ensure a client row exists for each connection before inserting since client_uuid is a foreign key
                    // (you cannot insert a connection row with a client_uuid value unless a clients row with that exact uuid already exists)
                    collect($connectionsData)
                        ->unique('client_uuid')
                        ->each(fn (array $data) => Client::firstOrCreate(
                            ['uuid' => $data['client_uuid']],
                            ['legal_entity_id' => $data['legal_entity_id']]
                        ));

                    $legalEntity->connections()->upsert(
                        $connectionsData,
                        ['uuid', 'legal_entity_id'], // unique keys
                        ['client_uuid', 'consumer_uuid', 'redirect_uri', 'secret', 'ehealth_inserted_at', 'ehealth_updated_at'] // fields to update if record exists
                    );
                });
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, __('Error occurred while trying to save connections'), __('Error occurred while trying to save connections'));

                return false;
            }
        }

        return true;
    }

    /**
     * Synchronize a single client's data from eHealth with the local database.
     *
     * @param  array  $clientData  Client data from eHealth API. Must contain:
     *                              - uuid: Client UUID
     *                              - client_type_name: Client type name
     *                              - legal_entity_type_uuid: Client type UUID
     *                              Additional fields are passed directly to the Client model.
     *
     * @return Client|null  Returns the synchronized Client instance, or null if synchronization failed.
     */
    public function syncClient(array $clientData): ?Client
    {
        $client = null;

        $clientData['legal_entity_id'] = LegalEntity::whereUuid($clientData['uuid'])->value('id');
        $clientData['legal_entity_type_id'] = LegalEntityType::whereName(Arr::pull($clientData, 'client_type_name'))->value('id');
        $clientTypeUuid = Arr::pull($clientData, 'legal_entity_type_uuid');

        try {
            DB::transaction(function () use ($clientData, $clientTypeUuid, &$client) {
                LegalEntityType::whereKey($clientData['legal_entity_type_id'])->update(['uuid' => $clientTypeUuid]);

                $client = Client::updateOrCreate(
                    ['uuid' => $clientData['uuid']],
                    $clientData
                );
            });
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, __('Error occurred while trying to save client data'), __('Error occurred while trying to save client data'));
        }

        return $client;
    }

    /**
     * Synchronize the redirect URI for a given connection.
     *
     * @param  Connection  $connection  The connection instance to update.
     * @param  string  $redirectUri  The new redirect URI to set for the connection.
     *
     * @return bool  Returns true if the update was successful, false otherwise.
     *
     * @throws Exception  If a database error occurs during the update process.
     */
    public function syncRedirectUri(Connection $connection, string $redirectUri): bool
    {
        try {
            DB::transaction(function () use ($connection, $redirectUri) {
                $connection->update(['redirect_uri' => $redirectUri]);
            });
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, __('Error occurred while trying to update connection data'), __('Error occurred while trying to update connection\'s data'));

            return false;
        }

        return true;
    }

    /**
     * Synchronize the Legal Entity secret for a given connection.
     *
     * @param  Connection  $connection  The connection instance to update.
     * @param  string  $secret  The new client_secret to set for the connection.
     *
     * @return bool  Returns true if the update was successful, false otherwise.
     *
     * @throws Exception  If a database error occurs during the update process.
     */
    public function syncConnectionSecret(Connection $connection, string $secret): bool
    {
        try {
            DB::transaction(function () use ($connection, $secret) {
                $connection->update(['secret' => $secret]);

                $this->updateLegalEntitySecret($connection->legalEntity, $secret);
            });
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, __('Error occurred while trying to update connection secret'), __('Error occurred while trying to update connection\'s secret'));

            return false;
        }

        return true;
    }

    /**
     * Synchronize the Connection's status for a given connection when delete
     *
     * @param  Connection  $connection  The connection instance to update.
     *
     * @return bool  Returns true if the update was successful, false otherwise.
     *
     * @throws Exception  If a database error occurs during the update process.
     */
    public function syncConnectionDelete(Connection $connection): bool
    {
        try {
            DB::transaction(function () use ($connection) {
                $connection->update(['status' => ConnectionStatus::TERMINATED->value]);
            });
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, __('Error occurred while trying to update connection termination'), __('Error occurred while trying to update connection termination'));

            return false;
        }

        return true;
    }

    /**
     * Update the client secret stored for a legal entity.
     *
     * @param  LegalEntity  $legalEntity  The legal entity to update.
     * @param  string  $secret  The new client secret.
     *
     * @return void
     */
    public function updateLegalEntitySecret(LegalEntity $legalEntity, string $secret): void
    {
        $legalEntity->update(['client_secret' => $secret]);
        $legalEntity->refresh();
    }
}

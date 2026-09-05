<?php

namespace App\Listeners;

use App\Core\Arr;
use Carbon\Carbon;
use App\Models\User;
use RuntimeException;
use App\Enums\Status;
use App\Enums\JobStatus;
use App\Enums\User\Role;
use App\Events\EHealthUserLogin;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Models\Employee\Employee;
use App\Models\Role as RoleModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthConnectionException;

class OwnerNewReplace
{
    public function handle(EHealthUserLogin $event): void
    {
        $legalEntityId = $event->legalEntity->id;

        // This need to be user with roles and permissions loaded
        setPermissionsTeamId($event->legalEntity->id);

        Auth::shouldUse($event->guard);

        $user = $event->user->load('roles', 'permissions', 'party');

        $role = session('first_login_role');

        if ($role !== Role::OWNER->value) {
            return;
        }

        // permissions() intersects role_has_permissions with legal_entity_type_permissions for the current team (set via setPermissionsTeamId above)
        $ownerScopes = RoleModel::where('name', Role::OWNER->value)
            ->where('guard_name', 'ehealth')
            ->first()
            ?->permissions()
            ->pluck('name')
            ->all() ?? [];

        // Order-independent equality check
        $scopesMatch = empty(array_diff($ownerScopes, $event->scopes)) && empty(array_diff($event->scopes, $ownerScopes));

        if ($role !== Role::OWNER->value) {
            return;
        }

        // If OWNER by role but not by permissions, we don't need to do anything
        if (!$scopesMatch) {
            return;
        }

        try {
            $data = EHealth::employee()->getMany([
                'legal_entity_id' => $event->legalEntity->uuid,
                'status' => Status::APPROVED->value,
                'employee_type' => Role::OWNER->value,
            ])->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting employees data');

            throw new RuntimeException( __('auth.login.error.owner_replacement.new_owner_employees_not_found'));
        }

        $newOwner = Arr::first($data);

        $oldOwner = Employee::activeOwners($legalEntityId)->first();

        if ($oldOwner->uuid === $newOwner['uuid']) {
            return;
        }

        try {
            $newOwnerDetails = EHealth::employee()->getDetails($newOwner['uuid'])->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting new owner details');

            throw new RuntimeException( __('auth.login.error.owner_replacement.new_owner_details_not_found'));
        }

        if (empty($newOwnerDetails)) {
            return;
        }

        // ===================================================================================
        // Create missed OWNER
        // ===================================================================================

        $timeNow = Carbon::parse(now())->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $partyData = $newOwnerDetails['party'] ?? null;
        $phonesData = $partyData ['phones'] ?? null;
        $documentsData = $partyData ['documents'] ?? null;

        data_forget($newOwnerDetails, 'party');
        data_forget($newOwnerDetails, 'doctor');
        data_forget($newOwnerDetails, 'division');
        data_forget($partyData, 'phones');
        data_forget($partyData, 'documents');

        DB::transaction(function () use ($user, $newOwnerDetails, $event, $timeNow, $partyData, $phonesData, $documentsData, $legalEntityId, $oldOwner) {
            $newEmployee = Employee::updateOrCreate(
                        ['uuid' => $newOwnerDetails['uuid']],
                        array_merge($newOwnerDetails, [
                            'legal_entity_id' => $legalEntityId,
                            'legal_entity_uuid' => $event->legalEntity->uuid,
                            'inserted_at' => $timeNow,
                            'user_id' => $user->id
                        ])
                    );

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            data_fill($newOwnerDetails, 'party', $partyData);
            data_fill($newOwnerDetails, 'phones', $phonesData);
            data_fill($newOwnerDetails, 'documents', $documentsData);

            Repository::employee()->updateDetails(
                $newEmployee,
                $newOwnerDetails['party'],
                $newOwnerDetails['documents'],
                $newOwnerDetails['phones'],
                $newOwnerDetails['educations'] ?? null,
                $newOwnerDetails['specialities'] ?? null,
                $newOwnerDetails['qualifications'] ?? null,
                $newOwnerDetails['scienceDegree'] ?? null
            );

            Log::info('[OwnerNewReplace]: Processing EmployeeDetailsUpsert for employee:' . $newEmployee->id . ', LE:' . ($this->legalEntity->id ?? 'N/A'));

            $newEmployee->legalEntityUuid = $event->legalEntity?->uuid;

            $newEmployee->save();

            $newEmployee->setSyncStatus(JobStatus::COMPLETED);
            $newEmployee->refresh();

            $legalEntityId = $newEmployee->legal_entity_id;

            setPermissionsTeamId($legalEntityId);

            // If the employee type is OWNER, we need to check if the current owner is different from the one in EHealth.
            if ($newEmployee->employeeType === Role::OWNER->value) {
                // $oldOwner = null when the Legal Entity just created, so we don't need to change anything in this case.
                if ($oldOwner?->uuid && $oldOwner->uuid !== $newEmployee->uuid) {
                    $currentOwnerUser = User::find($oldOwner->userId);

                    // Just overcautiousness
                    if ($currentOwnerUser) {
                        Repository::legalEntity()->disableOldOwner($currentOwnerUser, $event->legalEntity);
                    } else {
                            Log::error('[OwnerNewReplace] User not found for current owner.', [
                            'user_id' => $oldOwner->userId,
                            'legal_entity_uuid' => $event->legalEntity->uuid,
                            'employee_uuid' => $newEmployee->uuid ?? null,
                        ]);

                        throw new RuntimeException( __('auth.login.error.owner_replacement.current_owner_user_not_found'));
                    }
                }
            }

            if (!$user->partyId && $newEmployee->partyId) {
                $user->partyId = $newEmployee->partyId;
                $user->save();

                Log::info('[OwnerNewReplace] Associated User with Party from new Employee record.', ['user_id' => $user->id, 'party_id' => $newEmployee->partyId]);
            }

            // All the going on below is need due to the fact that we need to assign roles based on employee types,
            // and employee types are assigned based on the employee records that are just created.
            $user->refresh();

            if (!$user?->party) {
                Log::error('[OwnerNewReplace] User does not have an associated Party after processing new Employee.', ['user_id' => $user->id, 'legal_entity_uuid' => $event->legalEntity->uuid, 'employee_uuid' => $newEmployee->uuid ?? null]);

                throw new RuntimeException( __('auth.login.error.owner_replacement.new_owner_party_not_found'));
            }
        });

        Repository::party()->syncUserEmployeesAndRoles($user->party, $event->legalEntity);
    }
}

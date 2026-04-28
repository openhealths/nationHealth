<?php

declare(strict_types=1);

namespace App\Models;

use BackedEnum;
use Exception;
use App\Enums\Status;
use App\Enums\User\Role;
use InvalidArgumentException;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use App\Models\Role as ModelsRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Traits\HasRoles;
use Eloquence\Behaviours\HasCamelCasing;
use App\Models\Employee\EmployeeRequest;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable implements MustVerifyEmail
{
    use Notifiable,
        TwoFactorAuthenticatable,
        HasCamelCasing,
        HasRoles {
            HasRoles::assignRole as assignRoleParent;                // Aliasing original assignRole
            HasRoles::syncPermissions as syncPermissionsParent;      // Aliasing original syncPermissions
            HasRoles::givePermissionTo as givePermissionToParent;    // Aliasing original givePermissionTo
            HasRoles::getAllPermissions as getAllPermissionsParent;  // Alias original getAllPermissions
        }

    /**
     * Track if email verification was already sent
     */
    private static array $emailVerificationSent = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'email',
        'password',
        'secret_key',
        'party_id',
        'inserted_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * The relationships that should always be loaded.
     *
     * @var array
     */
    protected $with = ['person'];

    // If User is able to consume multiple roles or permissions from different guards
    // this method helps make sure that User class's operate within all allowed guards defined in config/auth.php
    public function guardName() {
        return collect(array_keys((array) config('auth.guards')))->values();
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * The employees that belong to the user
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_users', 'user_id', 'employee_id');
    }

    /**
     * Get the party that owns the user.
     *
     * @return BelongsTo
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * Get the active employee for the user in the current legal entity.
     *
     * @return Employee|null
     */
    public function activeEmployee(): ?Employee
    {
        if (!config('permission.teams')) {
            return $this->employees()->first();
        }

        $teamId = getPermissionsTeamId();

        if (!$teamId) {
            return null;
        }

        return $this->employees()
            ->where('legal_entity_id', $teamId)
            ->first();
    }

    public function employeeRequests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class);
    }

    /**
     * This need to override because trait HasProfilePhoto was disabled to remove 'name' attribute calling.
     *
     * @return string
     */
    public function getProfilePhotoUrlAttribute(): string
    {
        return $this->profile_photo_path
            ? asset('storage/' . $this->profile_photo_path)
            : $this->defaultProfilePhotoUrl();
    }

    /**
     * Get email verified at timestamp in camelCase
     *
     * @return null|string
     */
    public function getEmailVerifiedAtAttribute(): ?string
    {
        return $this->attributes['email_verified_at'];
    }

    /**
     * This need to override because trait HasProfilePhoto was disabled to remove 'name' attribute calling.
     *
     * @return string
     */
    public function defaultProfilePhotoUrl(): string
    {
        return '';
    }

    /**
     * Find a user by their eHealth UUID who has at least one employee in the given Legal Entity.
     *
     * @param  string  $userUuid        The user's eHealth UUID (from access token)
     * @param  string  $legalEntityUuid The Legal Entity UUID to check access against
     */
    public function scopeWithLegalEntityAccess(Builder $query, string $userUuid, string $legalEntityUuid): Builder
    {
        return $query
            ->where('uuid', $userUuid)
            ->whereExists(fn (QueryBuilder $q) => $q
                ->from('employee_users')
                ->join('employees', 'employees.id', '=', 'employee_users.employee_id')
                ->whereColumn('employee_users.user_id', 'users.id')
                ->where('employees.legal_entity_uuid', $legalEntityUuid)
            );
    }

    /**
     * Get ALL Legal Entities IDs available for this user
     *
     * @return Collection<int|string, mixed>|null
     */
    public function accessibleLegalEntities(): Collection
    {
        return $this->party?->employees()
            ->with('legalEntity')
            ->get()
            ->unique('legal_entity_id')
            ->pluck('legal_entity_id') ?? collect();
    }

    /**
     * Scope a query to users related to a given party (direct or via employee_users pivot).
     *
     * @param Builder $query
     * @param int $partyId
     *
     * @return Builder
     */
    public function scopeAllRelated(Builder $query, ?int $partyId = null, ?int $legalEntityId = null): Builder
    {
        $partyId ??= $this->partyId;
        $legalEntityId ??= legalEntity()->id ?? $this->legalEntityId;

        return $query
            ->where('party_id', $partyId)
            ->where(function (Builder $q) use ($partyId, $legalEntityId) {
                $employeeBase = Employee::getEmployeesForParty(legalEntityId: $legalEntityId, partyId: $partyId);
                $employeeBaseReorganized = Employee::getEmployeesForParty(legalEntityId: $legalEntityId, partyId: $partyId, status: Status::REORGANIZED);

                // Users linked via direct employee.user_id column
                $q->whereIn('id', $employeeBase->whereNotNull('user_id')->select('user_id'))
                    ->orWhereIn('id', $employeeBaseReorganized->whereNotNull('user_id')->select('user_id'))
                    // Users linked via employee_users pivot
                    // NOTE: about reuse of $employeeBase. At the first glance it can be done with mergeConstraintsFrom(),
                    // but trying to reuse the base query directly with methods like mergeConstraintsFrom()
                    // won't work cleanly since that's not a standard Laravel approach.
                    // The simplest solution is to just duplicate the conditions in the orWhereHas callback
                    // rather than trying to extract them into a reusable query builder instance.
                    ->orWhereHas('employees', fn (Builder $q1) => $q1
                        ->where('party_id', $partyId)
                        ->where('legal_entity_id', $legalEntityId)
                        ->whereIn('status', [Status::APPROVED, Status::REORGANIZED])
                    );
            })
            ->distinct();
    }

    /**
     * Retrieves the scopes assigned to a specific user.
     *
     * @return string The concatenated string of user's scopes
     */
    public function getScopes(): string
    {
        // Collect all permissions (direct + via roles)
        return $this->getAllPermissions()->pluck('name')->unique()->join(' ');
    }

    /**
     * Override: return all permissions filtered by current team's LegalEntity type
     * (MSP_LIMITED when status is REORGANIZED). This wraps the original
     * HasRoles::getAllPermissions and applies a type whitelist intersection.
     */
    public function getAllPermissions(): Collection
    {
        // Base union of direct + role permissions from Spatie
        $all = $this->getAllPermissionsParent();

        if (!config('permission.teams')) {
            return $all;
        }

        $teamId = getPermissionsTeamId();

        if (!$teamId) {
            return $all;
        }

        $status = LegalEntity::whereKey($teamId)->value('status');

        $typeId = $status === Status::REORGANIZED->value
            ? LegalEntityType::where('name', 'MSP_LIMITED')->value('id')
            : LegalEntity::whereKey($teamId)->value('legal_entity_type_id');

        if (!$typeId) {
            return $all->where(fn () => false); // empty collection
        }

        $guard = Auth::getDefaultDriver();

        // Permission names allowed for the current team’s LegalEntity type (MSP_LIMITED if REORGANIZED or assigned) and current guard
        $allowedNames = Permission::where('guard_name', $guard)
            ->whereHas('legalEntityTypes', fn ($q) => $q->where('legal_entity_type_id', $typeId))
            ->pluck('name')
            ->unique();

        return $all->filter(fn ($perm) => $allowedNames->contains($perm->name))->values();
    }

    /**
     * Get employee by priority with encounter:write permission.
     *
     * @return Employee|null
     */
    public function getEncounterWriterEmployee(): ?Employee
    {
        return $this->getWriterEmployeeByRolePriority(Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT, Role::MED_COORDINATOR);
    }

    /**
     * Get employee by priority with diagnostic_report:write permission.
     *
     * @return Employee|null
     */
    public function getDiagnosticReportWriterEmployee(): ?Employee
    {
        return $this->getWriterEmployeeByRolePriority(Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT, Role::LABORANT);
    }

    /**
     * Get employee by priority with procedure:write permission.
     *
     * @return Employee|null
     */
    public function getProcedureWriterEmployee(): ?Employee
    {
        return $this->getWriterEmployeeByRolePriority(Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT);
    }

    /**
     * Get main speciality for this user within a legal entity.
     *
     * @param  LegalEntity  $legalEntity
     * @return Collection<int, string>
     */
    public function getMainSpeciality(LegalEntity $legalEntity): Collection
    {
        return $this->employees()
            ->where('legal_entity_id', $legalEntity->id)
            ->get()
            ->loadMissing('specialities')
            ->flatMap->specialities
            ->where('speciality_officio', true)
            ->pluck('speciality');
    }

    /**
     * OVERRIDE: the parent method.
     * Send the email verification notification with error handling.
     *
     * @return void
     */
    public function sendEmailVerificationNotification(): void
    {
        // Check if we already sent verification to this email in this request
        $emailKey = $this->email . '_' . $this->id;

        // Already sent, skipping
        if (isset(self::$emailVerificationSent[$emailKey])) {
            return;
        }

        try {
            parent::sendEmailVerificationNotification();

            // Mark as sent
            self::$emailVerificationSent[$emailKey] = true;
        } catch (Exception $err) {
            Log::error('EmailVerification Error:', ['error' => $err->getMessage(), 'user_email' => $this->email]);

            throw new Exception(__("Cannot send verification email to the user"));
        }
    }

    /**
     * Get the roles the user is allowed to hold, based on their directly assigned permissions.
     *
     * A role is considered "allowed" when:
     * - Its full permission set is covered by the user's direct permissions (model_has_permissions), and
     * - The user is actually assigned that role (model_has_roles).
     *
     * Accessible as a property: $user->allowedRoles
     *
     * @return Collection<int, string> Role names
     */
    public function getAllowedRolesAttribute(): Collection
    {
        $guard = Auth::getDefaultDriver();

        // Why this need?
        // If $this->permissions was already eager-loaded before getAllowedRolesAttribute is called (e.g., via $this->load('permissions')
        // or $this->with = ['permissions'] in a different team context), the cached relation won't re-query —
        // it will return whatever was loaded earlier, which may not match the current team.
        $this->unsetRelation('permissions');

        // Direct permissions from model_has_permissions only
        $permissions = $this->getDirectPermissions()->pluck('name')->unique();

        // Roles whose full permission set fits within those direct permissions
        $possibleAllowedRoles = ModelsRole::coveredByPermissions($permissions)
            ->whereHas('permissions') // only consider roles that actually have permissions
            ->get()
            ->pluck('name')
            ->unique();

        // Roles actually assigned to the user (model_has_roles)
        $modelAllowedRoles = $this->roles->where('guard_name', $guard)->pluck('name')->unique();

        // Intersection: roles the user HAS that are also justified by their direct permissions
        return $possibleAllowedRoles->intersect($modelAllowedRoles)->values();
    }

    /**
     * Determine if the user holds ALL of the given role(s), each justified by their direct permissions.
     *
     *
     * @param  string|BackedEnum|array<string|BackedEnum>  $roles  Single role or array of roles
     */
    public function hasAllowedRole(string|BackedEnum|array $roles, bool $exactMatch = false): bool
    {
        $names = collect(is_array($roles) ? $roles : [$roles])
            ->map(fn ($role) => $role instanceof BackedEnum ? $role->value : $role);

        return $exactMatch
            ? $names->every(fn ($name) => $this->allowedRoles->contains($name)) // Return TRUE if user has assigned to ALL of incoming roles
            : $names->contains(fn ($name) => $this->allowedRoles->contains($name)); // Return TRUE if user is assigned to at least of the ONE of incoming roles
    }

    /**
     * Get employee by priority with specific write permission. Example: procedure:write.
     *
     * @param  Role  ...$priorityRoles  Ordered role from most valuable to least
     * @return Employee|null
     */
    protected function getWriterEmployeeByRolePriority(Role ...$priorityRoles): ?Employee
    {
        $roleValues = array_map(static fn (Role $role) => $role->value, $priorityRoles);

        $employees = $this->party?->employees()
            ->with('party:id,first_name,last_name,second_name')
            ->whereIn('employee_type', $roleValues)
            ->get(['id', 'uuid', 'party_id', 'employee_type']);

        return $employees->sortBy(
            fn (Employee $employee) => array_search($employee->employeeType, $roleValues, true)
        )->first();
    }

    /**
     * Type-aware syncPermissions that respects current team (legal_entity_id) and the
     * LegalEntity type -> Permission pivot (legal_entity_type_permissions).
     *
     * Contract:
     * - Inputs: strings|arrays|Permission models, variadic, nested arrays allowed
     * - Behavior: intersect incoming with permissions allowed by user's roles for current team
     *   AND allowed by the LegalEntity type of that team
     * - Fallback: if teams disabled or team/type/roles missing, sync to []
     */
    public function syncPermissions(...$permissions)
    {
        // If teams are disabled, fallback to original behavior
        if (!config('permission.teams')) {
            return $this->syncPermissionsParent(...$permissions);
        }

        $teamId = getPermissionsTeamId();

        // Team context is mandatory when teams are enabled
        if (!$teamId) {
            // Calling original syncPermissions with empty set
            return $this->syncPermissionsParent([]);
        }

        // Normalize inputs to unique, non-empty permission names (strings):
        // - Accept strings, arrays, variadic args, Permission models, and numeric IDs
        // - Flatten nested arrays, map models to their names, resolve numeric ids to names, remove empties & duplicates
        $incoming = collect($permissions)
            ->flatten()
            ->map(function ($p) {
                if ($p instanceof Permission) {
                    return $p->name;
                }

                if (is_int($p) || (is_string($p) && ctype_digit($p))) {
                    $perm = Permission::query()->find((int) $p);

                    return $perm?->name;
                }

                return (string) $p;
            })
            ->filter()
            ->unique();

        // Calling original syncPermissions with empty set
        if ($incoming->isEmpty()) {
            return $this->syncPermissionsParent([]);
        }

        // Allowed permissions for those roles AND for this LegalEntity type
        $allowed = $this->getAllPermissions()->pluck('name')->unique();

        // Intersect incoming with allowed
        $filtered = $incoming->intersect($allowed)->values()->all();

        // Delegate to original syncPermissions for the filtered set
        $result = $this->syncPermissionsParent($filtered);

        // Refresh caches and relations
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->unsetRelation('permissions');

        return $result;
    }

    /**
     * Override: type- and team-aware givePermissionTo for direct user permissions.
     * Only grants permissions that are in the intersection of:
     * - role_has_permissions for user's roles on current team, and
     * - legal_entity_type_permissions for the current team's LegalEntity type,
     * filtered by the user's default guard.
     *
     * If teams are disabled, fallback to original behavior.
     * If team/type/roles are missing, no-op (safe default).
     */
    public function givePermissionTo(...$permissions)
    {
        if (!config('permission.teams')) {
            // Teams are disabled: fallback to original behavior
            return $this->givePermissionToParent(...$permissions);
        }

        $teamId = getPermissionsTeamId();

        // If no active team than do not grant
        if (!$teamId) {
            return $this;
        }

        // Normalize inputs to unique, non-empty permission names (strings):
        // - Accept strings, arrays, variadic args, Permission models, and numeric IDs
        // - Flatten nested arrays, map models to their names, resolve numeric ids to names, remove empties & duplicates
        $incoming = collect($permissions)
            ->flatten()
            ->map(function ($p) {
                if ($p instanceof Permission) {
                    return $p->name;
                }

                if (is_int($p) || (is_string($p) && ctype_digit($p))) {
                    $perm = Permission::find((int) $p);

                    return $perm?->name;
                }

                return (string) $p;
            })
            ->filter()
            ->unique();

        // If no valid permissions were found, return $this
        if ($incoming->isEmpty()) {
            return $this;
        }

        // Allowed names by role+type whitelist intersection for this guard
        $allowed = $this->getAllPermissions()->pluck('name')->unique();

        $toGrant = $incoming->intersect($allowed)->values()->all();

        // If nothing to grant after filtering
        if (empty($toGrant)) {
            return $this;
        }

        // Delegate to original givePermissionTo for the filtered set
        $result = $this->givePermissionToParent($toGrant);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->unsetRelation('permissions');

        return $result;
    }

    /**
     * Assign role(s) to the user for the current team, validating against Legal Entity type.
     * Throws disallowed   ArgumentException if attempting to assign a role not allowed for the team's type.
     * Falls back to original behavior when teams are disabled.
     *
     * @param  mixed  ...$roles  role names or Role models (variadic or arrays)
     */
    public function assignRole(...$roles): static
    {
        if (!config('permission.teams')) {
            // Teams are disabled: fallback to original behavior
            return $this->assignRoleParent(...$roles);
        }

        $teamId = getPermissionsTeamId();

        if (!$teamId) {
            throw new InvalidArgumentException('No active legal entity (team) context for role assignment.');
        }

        /**
         * Collection of role IDs assigned to the user for the current team (legal entity).
         *
         * @var string $typeName // Legal Entity type name for the current team or empty string
         */
        $typeName = (legalEntity() ?? LegalEntity::find($teamId))
            ->loadMissing('type')
            ->type
            ->name ?? '';

        $allowedRoles = collect((array) config('ehealth.legal_entity_employee_types.' . $typeName))
            ->filter(fn ($role) => is_string($role) && $role !== '')
            ->unique()
            ->values();

        // Normalize requested roles to names
        $requested = collect($roles)
            ->flatten()
            ->map(function ($role) {
                if ($role instanceof SpatieRole) {
                    return $role->name;
                }
                if ($role instanceof BackedEnum) {
                    return $role->value;
                }

                return (string) $role;
            })
            ->filter() // remove empty strings
            ->unique()
            ->values();

        // If nothing to assign
        if ($requested->isEmpty()) {
            return $this;
        }

        // Roles not allowed for this LE type
        $disallowed = $requested->diff($allowedRoles)->values();

        if ($disallowed->isNotEmpty()) {
            Log::warning('AssignRole skipped roles not allowed for legal entity type', [
                'user_id' => $this->getKey(),
                'team_id' => $teamId,
                'legal_entity_type' => $typeName,
                'disallowed_roles' => $disallowed->all()
            ]);
        }

        $validRoles = $requested->intersect($allowedRoles)->values();

        // If nothing to assign after filtering
        if ($validRoles->isEmpty()) {
            return $this;
        }

        // Get current guard
        $guard = Auth::getDefaultDriver();

        // Resolve Role models for current request guard
        $roleModels = SpatieRole::whereIn('name', $validRoles)
            ->where('guard_name', $guard)
            ->get()
            ->all();

        if (empty($roleModels)) {
            return $this;
        }

        // Delegate to trait with filtered and valid roles
        $result = $this->assignRoleParent($roleModels);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }

    /**
     * Determine if the model may perform the given permission.
     *
     * @param  string|int|Permission|BackedEnum  $permission
     * @param  string|null  $guardName
     * @return bool
     */
    public function hasPermissionTo(string|int|Permission|BackedEnum $permission, ?string $guardName = null): bool
    {
        $guardName = $guardName ?: $this->getDefaultGuardName();

        // If wildcard support is configured, delegate to wildcard check
        if ($this->getWildcardClass()) {
            return $this->hasWildcardPermission($permission, $guardName);
        }

        // Normalize to Permission model via Spatie filterPermission().
        // If permission does not exist, just return false.
        try {
            $permModel = $this->filterPermission($permission, $guardName);
            $name = $permModel->name;
        } catch (PermissionDoesNotExist) {
            return false;
        }

        // If user is in the "single role login" state with a temporary role, only check direct permissions
        if (session('first_login_role')) {
            return $this->getDirectPermissions()
                ->pluck('name')
                ->contains($name);
        }

        // Check against filtered union of user's permissions (direct + via roles),
        // already constrained by LegalEntity type and current guard in getAllPermissions()
        return $this->getAllPermissions()->pluck('name')->unique()->contains($name);
    }
}

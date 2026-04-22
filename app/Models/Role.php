<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use App\Enums\Status;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends SpatieRole
{
    public function legalEntityTypes(): BelongsToMany
    {
        return $this->belongsToMany(LegalEntityType::class, 'legal_entity_type_roles', 'role_id', 'legal_entity_type_id');
    }

    /**
     * Override permissions() to return the intersection of:
     * - role_has_permissions (this role's assigned permissions), and
     * - legal_entity_type_permissions (permissions allowed for the current LegalEntity type)
     * based on the active team (legal_entity_id) from Spatie's PermissionRegistrar.
     */
    #[Override]
    public function permissions(): BelongsToMany
    {
        $relation = parent::permissions();

        // Avoid accidental duplicates from complex scopes
        $relation->distinct();

        if (config('permission.teams')) {
            $teamId = getPermissionsTeamId();

            if ($teamId) {
                // Cache teamId -> legal_entity_type_id to avoid repeated DB lookups during frequent permission checks.
                // This mapping is invalidated immediately on type change in LegalEntity::booted().
                $typeId = cache()->remember("le_type:$teamId", now()->addMinutes(5), function () use ($teamId) {
                    $status = LegalEntity::whereKey($teamId)->value('status') ?? '';

                    if ($status === Status::REORGANIZED->value) {
                        return LegalEntityType::where('name', 'MSP_LIMITED')->value('id');
                    }

                    return LegalEntity::whereKey($teamId)->value('legal_entity_type_id');
                });

                if ($typeId) {
                    // Intersect with permissions whitelisted for this LegalEntity type
                    $relation->whereHas('legalEntityTypes', fn ($perm) => $perm->where('legal_entity_type_id', $typeId));
                } else {
                    // No LegalEntity type resolved for current team: return no permissions
                    $relation->whereRaw('1 = 0');
                }
            }
        }

        return $relation;
    }

    /**
     * Create a new Role and attach it to LegalEntityType(s).
     *
     * Accepted extra attributes (optional):
     * - legal_entity_type_ids: array<int> of LegalEntityType IDs to attach
     * - legal_entity_type_names: array<string> of LegalEntityType names to resolve and attach
     *
     * Behavior:
     * - If neither is provided (or both empty), the role will be attached to the current team's
     *   LegalEntityType (resolved via PermissionRegistrar->getPermissionsTeamId()).
     * - If a team type cannot be resolved and no types were passed, the role will be created without
     *   any type association.
     */
    #[Override]
    public static function create(array $attributes = [])
    {
        // Extract optional type hints before persisting the role
        $typeIds = Arr::pull($attributes, 'legal_entity_type_ids');
        $typeNames = Arr::pull($attributes, 'legal_entity_type_names');

        return DB::transaction(function () use ($attributes, $typeIds, $typeNames) {
            /** @var self $role */
            $role = parent::create($attributes);

            // Normalize desired type IDs
            $desiredTypeIds = [];

            if (is_array($typeIds) && !empty($typeIds)) {
                $desiredTypeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds), fn ($v) => $v > 0)));
            } elseif (is_array($typeNames) && !empty($typeNames)) {
                $desiredTypeIds = LegalEntityType::whereIn('name', $typeNames)->pluck('id')->all();
            } elseif (config('permission.teams')) {
                // Fallback: attach to current team's LegalEntity type if resolvable
                $teamId = getPermissionsTeamId();

                if ($teamId) {
                    $typeId = LegalEntity::whereKey($teamId)->value('legal_entity_type_id');

                    $desiredTypeIds = [$typeId];
                }
            }

            if (!empty($desiredTypeIds)) {
                $role->legalEntityTypes()->syncWithoutDetaching($desiredTypeIds);
            }

            return $role;
        });
    }

    /**
     * Scope to roles whose entire permission set is covered by the given permissions.
     *
     * Example usage: Role::coveredByPermissions($user->getAllPermissions()->pluck('name'))->get();
     *
     * @param  Builder  $query
     * @param  Collection|array<string>  $permissions  Permission names the user already holds
     */
    public function scopeCoveredByPermissions(Builder $query, Collection|array $permissions): void
    {
        $permissions = collect($permissions);

        $query
            ->whereHas('permissions')
            ->whereDoesntHave('permissions', fn (Builder $q) => $q->whereNotIn('name', $permissions));
    }
}

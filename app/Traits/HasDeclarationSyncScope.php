<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\User\Role;
use App\Models\User;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;

trait HasDeclarationSyncScope
{
    /**
     * Restrict doctors to their own declarations and requests during synchronization.
     */
    #[Scope]
    protected function forSyncBy(Builder $query, User $user, LegalEntity $legalEntity): Builder
    {
        return $query->when(
            $user->hasAllowedRole(Role::DOCTOR) && !$user->hasAllowedRole([Role::OWNER, Role::REORGANIZATION_OWNER, Role::ADMIN]),
            fn (Builder $query) => $query->whereIn('employee_id', Employee::forParty($user->partyId)
                ->filterByLegalEntityId($legalEntity->id)
                ->doctor()
                ->select('id'))
        );
    }
}

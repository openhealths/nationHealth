<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\User\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::whereEmail($email)->first();
    }

    /**
     * Get list of users that:
     * 1) have permission 'division:write'
     * 2) have an employee in the updated division
     * 3) and this employee belongs to the same legal_entity
     *
     * @param  int  $divisionId
     * @return Collection
     */
    public function getDivisionEditorsByLegalEntity(int $divisionId): Collection
    {
        return User::permission('division:write')
            ->whereHas('employees', static function (Builder $query) use ($divisionId) {
                $query->whereDivisionId($divisionId)
                    ->whereLegalEntityId(legalEntity()->id);
            })
            ->get();
    }

    /**
     * Get a collection of users who have the "OWNER" role and are linked as employees to the current legal entity.
     *
     * @return Collection
     */
    public function getLegalEntityOwners(): Collection
    {
        return User::role(Role::OWNER)
            ->whereHas('employees', static fn (Builder $query) => $query->whereLegalEntityId(legalEntity()->id))
            ->get();
    }
}

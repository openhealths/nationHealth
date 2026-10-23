<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\User\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    /** @return Collection<int, User> */
    public function getSyncRetryCandidates(User $excludedUser, int $legalEntityId): Collection
    {
        // Caller sets Spatie's current team to this legal entity before this eager load.
        // Only direct permissions are loaded; OAuth scopes/token are checked separately by EHealthJob.
        return User::with('permissions')
            // Exclude the failed user and other accounts of the same party to avoid retrying as
            // the same person. A missing party ID must not exclude all candidates with NULL party_id.
            ->whereKeyNot($excludedUser->id)
            ->when($excludedUser->partyId !== null, fn (Builder $query) => $query->whereNot('party_id', $excludedUser->partyId))
            // Employee membership, not the current HTTP session, ties candidates to the job's LE.
            ->whereHas('employees', fn (Builder $query) => $query->whereLegalEntityId($legalEntityId))
            // Both correlations are necessary: user_id alone can match an obsolete session left
            // after re-login. Only the session currently bound by users.session_id can supply a token.
            // Laravel may not have pruned expired database sessions yet, so also check last_activity.
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('sessions')
                ->whereColumn('sessions.user_id', 'users.id')
                ->whereColumn('sessions.id', 'users.session_id')
                ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp))
            // Stable selection makes fallback deterministic when multiple employees are eligible.
            ->orderBy('users.id')
            ->get();
    }

    /** Read only the session currently bound in the database, even for a serialized User. */
    public function getSyncSessionPayload(User $user): array
    {
        $payload = DB::table('sessions')
            ->join('users', 'users.session_id', '=', 'sessions.id')
            ->where('users.id', $user->id)
            ->where('sessions.user_id', $user->id)
            ->where('sessions.last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp)
            ->value('sessions.payload');

        if (!is_string($payload)) {
            return [];
        }

        $decoded = base64_decode($payload, true);
        $attributes = $decoded === false ? false : @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($attributes) ? $attributes : [];
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

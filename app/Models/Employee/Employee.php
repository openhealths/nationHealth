<?php

declare(strict_types=1);

namespace App\Models\Employee;

use App\Casts\EHealthDateCast;
use App\Enums\Party\VerificationStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Declaration;
use App\Models\Relations\Education;
use App\Models\Relations\Qualification;
use App\Models\Relations\ScienceDegree;
use App\Models\Relations\Speciality;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Employee extends BaseEmployee
{
    protected $table = 'employees';

    /**
     * Merging parent casts with specific ones for this model.
     */
    protected $casts = [
        'status' => Status::class,
        'start_date' => EHealthDateCast::class,
        'end_date' => EHealthDateCast::class,
    ];

    // --- EMPLOYEE-SPECIFIC RELATIONS ---

    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class);
    }

    public function educations(): MorphMany
    {
        return $this->morphMany(Education::class, 'educationable');
    }

    public function scienceDegree(): MorphOne
    {
        return $this->morphOne(ScienceDegree::class, 'science_degreeable');
    }

    public function qualifications(): MorphMany
    {
        return $this->morphMany(Qualification::class, 'qualificationable');
    }

    public function specialities(): MorphMany
    {
        return $this->morphMany(Speciality::class, 'specialityable');
    }

    #[Scope]
    public function doctor(Builder $query): Builder
    {
        return $query->whereEmployeeType(Role::DOCTOR);
    }

    #[Scope]
    protected function forParty(Builder $query, int $partyId): Builder
    {
        return $query->wherePartyId($partyId);
    }

    public function scopeEmployeeInstance(Builder $query, int $userId, string $legalEntityUUID, array $roles, bool $isInclude = false): void
    {
        $query->whereHas('party.users', fn ($q) => $q->where('users.id', $userId))
            ->where('legal_entity_uuid', $legalEntityUUID)
            ->when(
                $isInclude,
                fn ($q) => $q->whereIn('employee_type', $roles),
                fn ($q) => $q->whereNotIn('employee_type', $roles)
            );
    }

    public function scopeIdentifyEmployee(Builder $query, array $employeeTypes, string $status, int $userId, int $legalEntityId, ?int $partyId): void
    {
        $query->whereIn('employee_type', $employeeTypes)
            ->where('status', $status)
            ->whereHas('party.users', fn ($q) => $q->where('users.id', $userId))
            ->where('legal_entity_id', $legalEntityId)
            ->forParty($partyId);
    }

    public function scopeFilterByUuids(Builder $query, array $uuids): Builder
    {
        return $query->whereIn('uuid', $uuids);
    }

    #[Scope]
    protected function activeSpecialists(Builder $query, int $legalEntityId): Builder
    {
        return $query->whereLegalEntityId($legalEntityId)
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true)
            ->whereHas('specialities', static function (Builder $query) {
                $query->select('id')->whereSpecialityOfficio(true);
            })
            ->select(['id', 'uuid', 'party_id', 'position'])
            ->with('party:id,first_name,last_name,second_name');
    }

    #[Scope]
    protected function activeRecorders(Builder $query, int $legalEntityId, bool $skipVerificationCheck = false): Builder
    {
        $query->whereLegalEntityId($legalEntityId)
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true);

        if (!$skipVerificationCheck) {
            $query->whereHas(
                'party',
                static fn (Builder $query) => $query->select('id')
                    ->whereNot('verification_status', VerificationStatus::NOT_VERIFIED)
            );
        }

        return $query->with('party:id,first_name,last_name,second_name');
    }

    #[Scope]
    protected function contractors(Builder $query, int $legalEntityId): Builder
    {
        return $query->whereLegalEntityId($legalEntityId)
            ->whereIn('employee_type', [Role::OWNER, Role::ADMIN])
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true)
            ->with('party:id,first_name,last_name,second_name');
    }

    /**
     * Scope to find active OWNERS for a specific legal entity.
     */
    #[Scope]
    public function activeOwners(Builder $query, int $legalEntityId): Builder
    {
        return $query->where('legal_entity_id', $legalEntityId)
            ->where('employee_type', Role::OWNER)
            ->where('status', Status::APPROVED)
            ->where('is_active', true);
    }
}

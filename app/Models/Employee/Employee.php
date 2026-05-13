<?php

declare(strict_types=1);

namespace App\Models\Employee;

use Carbon\Carbon;
use App\Models\User;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Declaration;
use App\Casts\EHealthDateCast;
use App\Models\Relations\Education;
use App\Models\Relations\Speciality;
use App\Models\Relations\Qualification;
use App\Models\Relations\ScienceDegree;
use App\Enums\Party\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Models\ReorganizationEmployeeDeclaration;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * The users that belong to the employee
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employee_users', 'employee_id', 'user_id');
    }

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

    /**
     * Get the declarations associated with this employee through the reorganization process.
     *
     * @return BelongsToMany<Declaration, ReorganizationEmployeeDeclaration>
     */
    public function reorganizedDeclarations(): BelongsToMany
    {
        return $this->belongsToMany(
            Declaration::class,
            'reorganization_employee_declarations'
        )
        ->using(ReorganizationEmployeeDeclaration::class)
        ->withPivot([
            'legal_entity_uuid',
            'employee_uuid',
            'declaration_uuid',
            'person_uuid',
            'declaration_number',
            'authorize_with'
        ]);
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

    /**
     * Scope to find employees matching the given types, status, user, legal entity, and optionally a party.
     *
     * @param  Builder    $query
     * @param  array      $employeeTypes
     * @param  string     $status
     * @param  int        $userId
     * @param  int        $legalEntityId
     * @param  int|null   $partyId
     *
     * @return void
     */
    public function scopeIdentifyEmployee(Builder $query, array $employeeTypes, string $status, int $userId, int $legalEntityId, ?int $partyId): void
    {
        $query->whereIn('employee_type', $employeeTypes)
            ->where('status', $status)
            ->whereHas('party.users', fn ($q) => $q->where('users.id', $userId))
            ->where('legal_entity_id', $legalEntityId)
            ->forParty($partyId);
    }

    /**
     * Scope to filter employees by a list of UUIDs.
     *
     * @param  Builder $query
     * @param  array   $uuids
     *
     * @return Builder
     */
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

    /**
     * Scope to get employees for a specific party within a legal entity.
     *
     * Falls back to the current legal entity and party if not provided.
     *
     * @param  Builder $query
     * @param  int|null $legalEntityId
     * @param  int|null $partyId
     * @param  Status $status
     *
     * @return Builder
     */
    public function scopeGetEmployeesForParty(Builder $query, ?int $legalEntityId = null, ?int $partyId = null, Status $status = Status::APPROVED): Builder
    {
        $legalEntityId ??= legalEntity()->id ?? $this->legalEntityId;
        $partyId ??= $this->partyId;

        return $query->where('legal_entity_id', $legalEntityId)
            ->where('party_id', $partyId)
            ->where('status', $status);
    }

    /**
     * Scope to get employees for a specific legal entity via pivot table.
     *
     * Falls back to the current legal entity if not provided.
     *
     * @param  Builder $query
     * @param  int|null $legalEntityId
     *
     * @return Builder
     */
    public function scopeGetEmployeesViaPivot(Builder $query, ?int $legalEntityId = null): Builder
    {
        $legalEntityId ??= legalEntity()->id ?? $this->legalEntityId;

        return $query
            ->where('legal_entity_id', $legalEntityId)
            ->whereHas('users');
    }

    /**
     * Scope to find an employee matching the given legal entity, type, position, and party.
     *
     * Used to check whether a duplicate employee record already exists before creating a new one.
     *
     * @param  Builder $query
     * @param  string  $legalEntityUuid
     * @param  string  $employeeType
     * @param  string  $position
     * @param  int     $partyId
     *
     * @return Builder
     */
    public function scopeMatchingEmployee(Builder $query, string $legalEntityUuid, string $employeeType, string $position, int $partyId): Builder {
        return $query
            ->where('legal_entity_uuid', $legalEntityUuid)
            ->where('employee_type', $employeeType)
            ->where('position', $position)
            ->where('party_id', $partyId);
    }

    /**
     * Check if the employee was created at or after the given time.
     *
     * Used to determine employees that should be available to a user based on their effective creation time.
     *
     * @param string $time The reference time to compare against
     *
     * @return bool
     */
    public function isCreatedAtOrAfter(string $time): bool
    {
        return Carbon::parse($this->insertedAt)->greaterThanOrEqualTo(Carbon::parse($time));
    }
}

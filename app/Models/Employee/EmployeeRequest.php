<?php

declare(strict_types=1);

namespace App\Models\Employee;

use App\Models\User;
use App\Models\Revision;
use App\Casts\EHealthDateCast;
use App\Enums\Employee\RequestStatus;
use App\Models\Relations\ScienceDegree;
use App\Models\Relations\Education;
use App\Models\Relations\Qualification;
use App\Models\Relations\Speciality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * Represents a request to create or modify an employee.
 * Inherits common properties from BaseEmployee.
 */
class EmployeeRequest extends BaseEmployee
{
    protected $table = 'employee_requests';

    /**
     * The attributes that are mass assignable.
     * Extends the list from the parent BaseEmployee class.
     */
    protected $fillable = [
        'uuid',
        'legal_entity_uuid',
        'division_uuid',
        'legal_entity_id',
        'status',
        'position',
        'start_date',
        'end_date',
        'party_id',
        'employee_type',
        'user_id',
        'division_id',
        'inserted_at',
        'applied_at',
        'employee_id',
        'email',
        'sync_status',
        'created_at'
    ];

    /**
     * The attributes that should be cast.
     * Extends the casts from the parent BaseEmployee class.
     */
    protected $casts = [
        'status' => RequestStatus::class,
        'start_date' => EHealthDateCast::class,
        'end_date' => EHealthDateCast::class,
        'applied_at' => 'datetime',
        'created_at' => 'datetime'
    ];

    // --- REQUEST-SPECIFIC RELATIONS ---

    /**
     * The employee this request is associated with (can be null for new employees).
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The user this request is associated with (can be null for synced employees)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revision(): MorphOne
    {
        return $this->morphOne(Revision::class, 'revisionable');
    }

    public function scienceDegree(): MorphOne
    {
        return $this->morphOne(ScienceDegree::class, 'science_degreeable');
    }

    public function educations(): MorphMany
    {
        return $this->morphMany(Education::class, 'educationable');
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
     * Local draft: not yet submitted to eHealth (no uuid). Status stays NEW.
     */
    public function isLocalDraft(): bool
    {
        return $this->uuid === null
            && $this->status === RequestStatus::NEW
            && $this->applied_at === null;
    }

    /**
     * Submitted to eHealth, awaiting APPROVED/REJECTED/EXPIRED.
     * Includes legacy rows that still use local SIGNED status.
     * Do not gate on applied_at — details sync previously set it for still-NEW rows.
     */
    public function isPendingEhealth(): bool
    {
        if ($this->status === RequestStatus::SIGNED) {
            return true;
        }

        return $this->status === RequestStatus::NEW && $this->uuid !== null;
    }

    /**
     * Pending eHealth decision: NEW with uuid, or legacy SIGNED.
     *
     * @param  Builder<EmployeeRequest>  $query
     * @return Builder<EmployeeRequest>
     */
    public function scopePendingEhealth(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner
                ->where('status', RequestStatus::SIGNED)
                ->orWhere(function (Builder $newSubmitted): void {
                    $newSubmitted
                        ->where('status', RequestStatus::NEW)
                        ->whereNotNull('uuid');
                });
        });
    }

    /**
     * Search by full name words against linked party and/or revision.party JSON.
     * Each word must match at least one name part (same idea as EmployeeIndex).
     *
     * @param  Builder<EmployeeRequest>  $query
     * @return Builder<EmployeeRequest>
     */
    public function scopeSearchByFullName(Builder $query, string $search): Builder
    {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($words): void {
            $outer
                ->whereHas('party', function (Builder $partyQuery) use ($words): void {
                    foreach ($words as $word) {
                        $term = '%' . $word . '%';
                        $partyQuery->where(function (Builder $nameQuery) use ($term): void {
                            $nameQuery
                                ->whereLike('last_name', $term)
                                ->orWhereLike('first_name', $term)
                                ->orWhereLike('second_name', $term);
                        });
                    }
                })
                ->orWhereHas('revision', function (Builder $revisionQuery) use ($words): void {
                    foreach ($words as $word) {
                        $term = '%' . $word . '%';
                        $revisionQuery->where(function (Builder $nameQuery) use ($term): void {
                            // JSON path is Postgres-specific; whereLike still abstracts case-insensitive matching.
                            $nameQuery
                                ->whereLike(DB::raw("(data->'party'->>'last_name')"), $term)
                                ->orWhereLike(DB::raw("(data->'party'->>'first_name')"), $term)
                                ->orWhereLike(DB::raw("(data->'party'->>'second_name')"), $term);
                        });
                    }
                });
        });
    }

    // --- TEMPORARY SCOPES (to be removed after controller refactoring) ---

    public function scopeEmployeeInstance(Builder $query, int $userId, string $legalEntityUUID, array $roles, bool $isInclude = false): void
    {
        $query->where('user_id', $userId)
            ->where('legal_entity_uuid', $legalEntityUUID)
            ->when(
                $isInclude,
                fn ($q) => $q->whereIn('employee_type', $roles),
                fn ($q) => $q->whereNotIn('employee_type', $roles)
            );
    }
}

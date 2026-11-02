<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EHealthTimestampCast;
use App\Enums\EmployeeRole\Status;
use App\Models\Employee\Employee;
use Database\Factories\EmployeeRoleFactory;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRole extends Model
{
    /** @use HasFactory<EmployeeRoleFactory> */
    use HasFactory;
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'employee_id',
        'healthcare_service_id',
        'start_date',
        'end_date',
        'status',
        'is_active',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'start_date' => 'immutable_datetime',
        'end_date' => 'immutable_datetime',
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class,
        'status' => Status::class
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function healthcareService(): BelongsTo
    {
        return $this->belongsTo(HealthcareService::class);
    }

    /**
     * User who created the role in eHealth, resolved from the inserted_by UUID.
     *
     * @return BelongsTo
     */
    public function insertedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ehealth_inserted_by', 'uuid');
    }

    /**
     * User who last updated the role in eHealth, resolved from the updated_by UUID.
     *
     * @return BelongsTo
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ehealth_updated_by', 'uuid');
    }

    /**
     * List of employee roles for current legal entity.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function forLegalEntity(Builder $query): Builder
    {
        return $query->with([
            'employee:id,party_id',
            'employee.party:id,first_name,last_name,second_name',
            'healthcareService:id,legal_entity_id,division_id,speciality_type,providing_condition',
            'healthcareService.legalEntity:id',
            'healthcareService.division:id,name'
        ])
            ->whereHas(
                'healthcareService',
                fn (Builder $healthcareServiceQuery) => $healthcareServiceQuery->whereLegalEntityId(legalEntity()->id)
            )
            ->latest();
    }

    /**
     * Filter by the selected employee (used as employee_id in the request).
     *
     * @param  Builder  $query
     * @param  string|null  $employeeUuid
     * @return Builder
     */
    #[Scope]
    protected function filterByEmployeeId(Builder $query, ?string $employeeUuid): Builder
    {
        if ($employeeUuid) {
            $query->whereHas(
                'employee',
                fn (Builder $employeeQuery) => $employeeQuery->whereUuid($employeeUuid)
            );
        }

        return $query;
    }

    /**
     * Filter by the selected healthcare service (used as healthcare_service_id in the request).
     *
     * @param  Builder  $query
     * @param  string|null  $healthcareServiceUuid
     * @return Builder
     */
    #[Scope]
    protected function filterByHealthcareServiceId(Builder $query, ?string $healthcareServiceUuid): Builder
    {
        if ($healthcareServiceUuid) {
            $query->whereHas(
                'healthcareService',
                fn (Builder $healthcareServiceQuery) => $healthcareServiceQuery->whereUuid($healthcareServiceUuid)
            );
        }

        return $query;
    }

    #[Scope]
    protected function filterByStatus(Builder $query, array $status): Builder
    {
        if ($status) {
            $query->whereIn('status', $status);
        }

        return $query;
    }
}

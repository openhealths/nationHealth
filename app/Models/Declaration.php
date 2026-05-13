<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Declaration\Status;
use App\Enums\JobStatus;
use App\Models\Employee\Employee;
use App\Models\Person\Person;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Declaration extends Model
{
    use HasCamelCasing;

    public const int ADULT_AGE = 18;

    protected $fillable = [
        'id',
        'uuid',
        'declaration_number',
        'declaration_request_id',
        'division_id',
        'employee_id',
        'legal_entity_id',
        'person_id',
        'end_date',
        'inserted_at',
        'is_active',
        'reason',
        'reason_description',
        'signed_at',
        'start_date',
        'status',
        'sync_status'
    ];

    protected $casts = [
        'status' => Status::class
    ];

    #[Scope]
    protected function filterByLegalEntityId(Builder $query, int $legalEntityId): Builder
    {
        return $query->whereLegalEntityId($legalEntityId);
    }

    #[Scope]
    protected function filterBySyncStatus(Builder $query, JobStatus $status): Builder
    {
        return $query->whereSyncStatus($status);
    }

    #[Scope]
    protected function forEmployees(Builder $query, array $employeeIds): Builder
    {
        return $query->whereIn('employee_id', $employeeIds);
    }

    public function declarationRequest(): BelongsTo
    {
        return $this->belongsTo(DeclarationRequest::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Get the employees associated with this declaration through the reorganization process.
     *
     * @return BelongsToMany<Employee, ReorganizationEmployeeDeclaration>
     */
    public function reorganizedEmployees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
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
}

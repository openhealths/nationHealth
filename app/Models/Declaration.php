<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Person\Person;
use App\Models\Employee\Employee;
use App\Enums\Declaration\Status;
use Illuminate\Database\Eloquent\Model;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Declaration extends Model
{
    use HasCamelCasing;

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
    protected function active(Builder $query): Builder
    {
        return $query->whereIsActive(true)->whereStatus(Status::ACTIVE);
    }

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
     * @return HasOne<ReorganizationEmployeeDeclaration, $this>
     */
    public function reorganizedEmployeeDeclaration(): HasOne
    {
        return $this->hasOne(ReorganizationEmployeeDeclaration::class, 'declaration_id');
    }

    /**
     * Determines whether this declaration is linked to a declaration request that has a parent declaration.
     *
     * @return bool
     */
    public function hasParentDeclaration(): bool
    {
        return $this->declarationRequest()->whereNotNull('parent_declaration_uuid')->exists();
    }
}

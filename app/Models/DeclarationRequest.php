<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Person\Person;
use App\Enums\Declaration\RequestStatus;
use App\Models\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeclarationRequest extends Model
{
    use HasCamelCasing;

    protected $guarded = [];

    protected $casts = [
        'status' => RequestStatus::class,
        'data_to_be_signed' => 'array'
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

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function declaration(): HasOne
    {
        return $this->hasOne(Declaration::class, 'declaration_request_id');
    }
}

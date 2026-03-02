<?php

declare(strict_types=1);

namespace App\Models\Employee;

use App\Casts\EHealthDateCast;
use App\Enums\JobStatus;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Traits\SyncsMorphManyRelations;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Scope;

/**
 * An abstract base class for Employee and EmployeeRequest models.
 * It contains only the common logic, properties, and relationships.
 *
 * @mixin SyncsMorphManyRelations
 */
abstract class BaseEmployee extends Model
{
    use HasCamelCasing;
    use SyncsMorphManyRelations;

    /**
     * Common fillable attributes for both employees and requests.
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
        'is_active'
    ];

    /**
     * Common casts.
     */
    protected $casts = [
        'start_date' => EHealthDateCast::class,
        'end_date' => EHealthDateCast::class,
    ];

    // --- COMMON ACCESSORS ---

    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn () => implode(
                ' ',
                array_filter(
                    [
                        $this->party?->last_name,
                        $this->party?->first_name,
                        $this->party?->second_name,
                    ]
                )
            )
        );
    }

    protected function isVerified(): Attribute
    {
        return Attribute::get(fn () => $this->party?->users()->first()?->email_verified_at !== null);
    }

    // --- COMMON RELATIONS ---

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    #[Scope]
    protected function filterBySyncStatus(Builder $query, JobStatus $status): Builder
    {
        return $query->where('sync_status', $status);
    }

    #[Scope]
    protected function filterByLegalEntityId(Builder $query, int $legalEntityId): Builder
    {
        return $query->where('legal_entity_id', $legalEntityId);
    }

    public function setSyncStatus(JobStatus $status): void
    {
        $this->sync_status = $status;
        $this->save();
    }
}

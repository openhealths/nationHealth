<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ReorganizationEmployeeDeclaration extends Pivot
{
    use HasCamelCasing;

    protected $table = 'reorganization_employee_declarations';
    public $incrementing = true; // This need to set explicitly because Pivot sets it to false

    protected $fillable = [
        'legal_entity_id',
        'legal_entity_uuid',
        'employee_id',
        'employee_uuid',
        'party_id',
        'party_uuid',
        'person_id',
        'person_uuid',
        'declaration_id',
        'declaration_uuid',
        'declaration_number',
        'declaration_request_id',
        'declaration_request_uuid',
        'authorize_with',
        'updated_at'
    ];

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<LegalEntity, $this>
     */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    /**
     * Scope to filter records that are connected to the given legal entity
     * through the legators table (i.e. the record's legal_entity_uuid is a legator of the given entity).
     *
     * @param  Builder<static>  $query
     * @param  LegalEntity  $legalEntity
     * @return Builder<static>
     */
    #[Scope]
    public function hasConnectionTo(Builder $query, LegalEntity $legalEntity): Builder
    {
        return $query->whereIn(
            'legal_entity_uuid',
            fn (QueryBuilder $q) => $q
                ->select('uuid')
                ->from('legators')
                ->where('legal_entity_id', $legalEntity->id)
        );
    }
}

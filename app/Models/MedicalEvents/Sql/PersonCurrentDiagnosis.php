<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonCurrentDiagnosis extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'person_id',
        'preperson_id',
        'condition_id',
        'code_id',
        'role_id',
        'rank'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'condition_id',
        'code_id',
        'role_id',
        'created_at',
        'updated_at'
    ];

    /**
     * Filter diagnoses belonging to the given patient (person or preperson).
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder
    {
        return $patient instanceof Preperson
            ? $query->wherePrepersonId($patient->id)
            : $query->wherePersonId($patient->id);
    }

    /**
     * Limit diagnoses to the condition codes allowed in the patient summary.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function allowedForSummary(Builder $query): Builder
    {
        return $query->whereHas(
            'code.coding',
            static fn (Builder $coding): Builder => $coding->whereIn(
                'code',
                config('ehealth.summary_conditions_allowed')
            )
        );
    }

    /**
     * Scope to eager load all diagnosis relationships.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with(['code.coding', 'condition.type.coding', 'role.coding']);
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'condition_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'role_id');
    }
}

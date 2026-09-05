<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Enums\DetectedIssue\Status;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetectedIssue extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'subject_id',
        'encounter_id',
        'author_id',
        'code_id',
        'detail',
        'identified_date_time',
        'implicated_id',
        'based_on_id',
        'primary_source',
        'report_origin_id',
        'recorder_id'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'subject_id',
        'encounter_id',
        'author_id',
        'code_id',
        'implicated_id',
        'based_on_id',
        'report_origin_id',
        'recorder_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'status' => Status::class,
        'identified_date_time' => EHealthTimestampCast::class,
        'primary_source' => 'boolean'
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'subject_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'encounter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'author_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function implicated(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'implicated_id');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'recorder_id');
    }

    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'subject.type.coding',
            'encounter.type.coding',
            'author.type.coding',
            'code.coding',
            'implicated.type.coding',
            'basedOn.type.coding',
            'reportOrigin.coding',
            'recorder.type.coding'
        ]);
    }

    /**
     * Filter detected issues belonging to the given patient.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder {
        return $patient instanceof Preperson ? $query->wherePrepersonId($patient->id) : $query->wherePersonId($patient->id);
    }

    /**
     * Filter detected issues recorded within the given encounter.
     *
     * @param  Builder  $query
     * @param  string  $encounterUuid
     * @return Builder
     */
    #[Scope]
    protected function forEncounter(Builder $query, string $encounterUuid): Builder {
        return $query->whereHas('encounter', static fn (Builder $identifier): Builder => $identifier->whereValue($encounterUuid));
    }
}
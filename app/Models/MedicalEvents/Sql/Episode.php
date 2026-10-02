<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Episode\Status;
use App\Enums\Person\MergedPersonStatus;
use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

class Episode extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'encounter_id',
        'episode_type_id',
        'status',
        'name',
        'managing_organization_id',
        'care_manager_id',
        'status_reason_id',
        'closing_summary',
        'explanatory_letter',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'encounter_id',
        'episode_type_id',
        'managing_organization_id',
        'care_manager_id',
        'status_reason_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'status' => Status::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    protected function ehealthInsertedDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Str::before((string) $this->ehealthInsertedAt, ' ')
        );
    }

    protected function ehealthInsertedTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Str::after((string) $this->ehealthInsertedAt, ' ')
        );
    }

    protected function ehealthUpdatedDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Str::before((string) $this->ehealthUpdatedAt, ' ')
        );
    }

    protected function ehealthUpdatedTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Str::after((string) $this->ehealthUpdatedAt, ' ')
        );
    }

    public function period(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'episode_type_id');
    }

    public function managingOrganization(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'managing_organization_id');
    }

    public function careManager(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'care_manager_id');
    }

    public function statusReason(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function currentDiagnoses(): HasMany
    {
        return $this->hasMany(EpisodeCurrentDiagnosis::class);
    }

    public function diagnosesHistory(): HasMany
    {
        return $this->hasMany(EpisodeDiagnosesHistory::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(EpisodeStatusHistory::class);
    }

    /**
     * Filter episodes belonging to the given patient (person or preperson).
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
     * Filter out the episodes known to be managed by another legal entity. An episode without a managing organization
     * came from a short (summary) response that does not return one, so it is kept: there is nothing to tell it apart.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function forLegalEntity(Builder $query): Builder
    {
        return $query->where(
            static fn (Builder $episode): Builder => $episode
                ->whereNull('managing_organization_id')
                ->orWhereHas(
                    'managingOrganization',
                    static fn (Builder $identifier): Builder => $identifier->whereValue(legalEntity()->uuid)
                )
        );
    }

    /**
     * Episodes of the patient the user may read: with access to all the patient's data (an active declaration or
     * approval) all of them, including the ones of the persons and prepersons merged into the patient, otherwise only
     * the patient's own ones of the current legal entity.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  bool  $hasPatientAccess
     * @return Builder
     */
    #[Scope]
    protected function readableFor(Builder $query, Person|Preperson $patient, bool $hasPatientAccess): Builder
    {
        if (!$hasPatientAccess || $patient instanceof Preperson) {
            return $query->forPatient($patient)->forLegalEntity();
        }

        $mergedPersons = $patient->mergedPersons()
            ->whereStatus(MergedPersonStatus::MERGED)
            ->get(['merged_person_id', 'merged_preperson_id']);

        return $query->where(
            static fn (Builder $episode): Builder => $episode->wherePersonId($patient->id)
                ->orWhereIn('person_id', $mergedPersons->pluck('mergedPersonId')->filter()->all())
                ->orWhereIn('preperson_id', $mergedPersons->pluck('mergedPrepersonId')->filter()->all())
        );
    }

    #[Scope]
    protected function withRelationships(Builder $query): Builder
    {
        return $query->with([
            'type',
            'managingOrganization.type.coding',
            'careManager.type.coding',
            'statusReason.coding',
            'period',
            'currentDiagnoses.code.coding',
            'currentDiagnoses.condition.type.coding',
            'currentDiagnoses.role.coding',
            'diagnosesHistory.evidence.type.coding',
            'diagnosesHistory.diagnoses.condition.type.coding',
            'diagnosesHistory.diagnoses.code.coding',
            'diagnosesHistory.diagnoses.role.coding',
            'statusHistory.statusReason.coding',
        ]);
    }

    /**
     * Order by most recently updated in eHealth first, keeping records without a timestamp last.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function recentlyUpdatedFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN ehealth_updated_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('ehealth_updated_at');
    }
}

<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Device\Status;
use App\Enums\Person\EncounterStatus;
use App\Models\Employee\Employee;
use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'type_id',
        'model_number',
        'lot_number',
        'manufacturer',
        'serial_number',
        'manufacture_date',
        'expiration_date',
        'note',
        'explanatory_letter',
        'primary_source',
        'report_origin_id',
        'status_reason_id',
        'context_id',
        'recorder_id',
        'definition_id',
        'parent_id',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $casts = [
        'status' => Status::class,
        'manufacture_date' => EHealthTimestampCast::class,
        'expiration_date' => EHealthTimestampCast::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'type_id',
        'report_origin_id',
        'status_reason_id',
        'context_id',
        'recorder_id',
        'definition_id',
        'parent_id',
        'created_at',
        'updated_at'
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

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'type_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'definition_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function statusReason(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'recorder_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'parent_id');
    }

    public function names(): HasMany
    {
        return $this->hasMany(DeviceName::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(DeviceProperty::class);
    }

    public function identifiers(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'device_identifiers');
    }

    /**
     * Scope to eager load all device relationships.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'type.coding',
            'reportOrigin.coding',
            'statusReason.coding',
            'context.type.coding',
            'recorder.type.coding',
            'definition.type.coding',
            'parent.type.coding',
            'names',
            'properties.code.coding',
            'properties.value.valueCodeableConcept.coding',
            'properties.value.valueQuantity',
            'properties.value.valueRange.low',
            'properties.value.valueRange.high',
            'identifiers.type.coding'
        ]);
    }

    /**
     * Filter devices belonging to the given patient (person or preperson).
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
     * Filter the patient's devices recorded in the current legal entity. The record does not carry the legal entity
     * itself, so it is recognised by the recorder, who is an employee of that legal entity.
     *
     * ABAC rule 2.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function forLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'recorder',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Employee::whereLegalEntityId(legalEntity()->id)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's devices recorded within an encounter whose episode is managed by the current legal entity.
     *
     * ABAC rule 3.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function inEpisodeOfLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'context',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Encounter::inEpisodeOfLegalEntity($patient)->pluck('uuid')
            )
        );
    }

    /**
     * Devices of the patient the user may read: with access to all the patient's devices (an active declaration or
     * approval) all of them, including the ones of the persons and prepersons merged into a person, otherwise only the
     * patient's own ones recorded in the current legal entity or within an encounter of its episode.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  bool  $hasPatientAccess
     * @return Builder
     */
    #[Scope]
    protected function readableFor(Builder $query, Person|Preperson $patient, bool $hasPatientAccess): Builder
    {
        if ($hasPatientAccess) {
            return $patient instanceof Person
                ? $patient->filterRecordsWithMerged($query)
                : $query->forPatient($patient);
        }

        return $query->where(
            static fn (Builder $device): Builder => $device->forLegalEntity($patient)
                ->orWhere(static fn (Builder $inEpisode): Builder => $inEpisode->inEpisodeOfLegalEntity($patient))
        );
    }

    /**
     * Leave out the records of an encounter package eHealth has not accepted yet, which is still a draft, including the
     * draft encounters of the persons and prepersons merged into a person.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function notInDraftEncounter(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->whereDoesntHave(
            'context',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Encounter::readableFor($patient, true)->whereStatus(EncounterStatus::DRAFT)->pluck('uuid')
            )
        );
    }

    /**
     * Leave out the devices marked as entered in error.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function notEnteredInError(Builder $query): Builder
    {
        return $query->whereNot('status', Status::ENTERED_IN_ERROR);
    }

    /**
     * Limit devices to the types allowed in the patient summary.
     * An empty list of allowed types leaves nothing to show, exactly as the eHealth summary does.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function allowedForSummary(Builder $query): Builder
    {
        return $query->whereHas(
            'type.coding',
            static fn (Builder $coding): Builder => $coding->whereIn(
                'code',
                config('ehealth.device.summary_device_types_allowed')
            )
        );
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

    /**
     * Filter devices recorded within the given encounter, which is stored as the context identifier.
     *
     * @param  Builder  $query
     * @param  string  $encounterId
     * @return Builder
     */
    #[Scope]
    protected function forEncounter(Builder $query, string $encounterId): Builder
    {
        return $query->whereHas(
            'context',
            static fn (Builder $identifier): Builder => $identifier->whereValue($encounterId)
        );
    }
}

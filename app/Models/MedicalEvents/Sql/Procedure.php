<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Contracts\SubmittedToEHealth;
use App\Enums\Person\EncounterStatus;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Procedure extends Model implements SubmittedToEHealth
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'status_reason_id',
        'based_on_id',
        'code_id',
        'performed_date_time',
        'encounter_id',
        'origin_episode_id',
        'recorded_by_id',
        'primary_source',
        'performer_id',
        'report_origin_id',
        'division_id',
        'managing_organization_id',
        'outcome_id',
        'note',
        'explanatory_letter',
        'category_id'
    ];

    protected $hidden = [
        'id',
        'status_reason_id',
        'based_on_id',
        'code_id',
        'encounter_id',
        'origin_episode_id',
        'recorded_by_id',
        'performer_id',
        'report_origin_id',
        'division_id',
        'managing_organization_id',
        'outcome_id',
        'category_id',
        'created_at',
        'updated_at'
    ];

    protected $appends = [
        'performed_date',
        'performed_time',
        'performed_period_start_date',
        'performed_period_start_time',
        'performed_period_end_date',
        'performed_period_end_time'
    ];

    protected $casts = [
        'performed_date_time' => EHealthTimestampCast::class,
    ];

    protected function performedDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->performedDateTime
                ? convertToAppDateFormat(
                    $this->performedDateTime
                ) : '',
        );
    }

    protected function performedTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->performedDateTime
                ? CarbonImmutable::parse(
                    $this->performedDateTime
                )->format('H:i') : '',
        );
    }

    protected function performedPeriodStartDate(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['start'])
                ? CarbonImmutable::parse($this->performedPeriod['start'])->toDateString()
                : null
        );
    }

    protected function performedPeriodStartTime(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['start'])
                ? CarbonImmutable::parse($this->performedPeriod['start'])->toTimeString()
                : null
        );
    }

    protected function performedPeriodEndDate(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['end'])
                ? CarbonImmutable::parse($this->performedPeriod['end'])->toDateString()
                : null
        );
    }

    protected function performedPeriodEndTime(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['end'])
                ? CarbonImmutable::parse($this->performedPeriod['end'])->toTimeString()
                : null
        );
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
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

    /**
     * eHealth jobs the signed procedure was submitted with, the latest one telling how the last submission went.
     *
     * @return MorphMany
     */
    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'code_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'encounter_id');
    }

    public function originEpisode(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'origin_episode_id');
    }

    public function performedPeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'recorded_by_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'division_id');
    }

    public function managingOrganization(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'managing_organization_id');
    }

    public function reasonReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'procedure_reason_references');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'outcome_id');
    }

    public function complicationDetails(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'procedure_complication_details');
    }

    public function usedReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'procedure_used_references');
    }

    public function focalDevices(): HasMany
    {
        return $this->hasMany(FocalDevice::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function paperReferral(): MorphOne
    {
        return $this->morphOne(PaperReferral::class, 'paper_referralable');
    }

    public function usedCodes(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'procedure_used_codes')->withTimestamps();
    }

    /**
     * Filter procedures belonging to the given patient (person or preperson).
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
            'encounter',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Encounter::readableFor($patient, true)->whereStatus(EncounterStatus::DRAFT)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's procedures managed by the current legal entity.
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
            'managingOrganization',
            static fn (Builder $identifier): Builder => $identifier->whereValue(legalEntity()->uuid)
        );
    }

    /**
     * Filter the patient's procedures created within an encounter whose episode is managed by the current legal entity.
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
            'encounter',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Encounter::inEpisodeOfLegalEntity($patient)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's procedures originated by an episode managed by the current legal entity.
     *
     * ABAC rule 6.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function originatedInLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'originEpisode',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Episode::forPatient($patient)->managedByLegalEntity()->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's procedures created within an encounter originated by an episode managed by the current
     * legal entity.
     *
     * ABAC rule 8.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function inEncounterOriginatedInLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'encounter',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Encounter::originatedInLegalEntity($patient)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's procedures based on a referral issued under a care plan one of the user's employees has an
     * active read approval on.
     *
     * ABAC rule 14.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  User  $user
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function basedOnApprovedCarePlan(
        Builder $query,
        Person|Preperson $patient,
        User $user,
        LegalEntity $legalEntity
    ): Builder {
        return $query->forPatient($patient)->whereHas(
            'basedOn',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                ServiceRequestRequest::basedOnApprovedCarePlan($patient, $user, $legalEntity)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's procedures based on a referral issued under a care plan managed by the current legal entity.
     *
     * ABAC rule 17.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function basedOnCarePlanOfLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'basedOn',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                ServiceRequestRequest::basedOnCarePlanOfLegalEntity($patient)->pluck('uuid')
            )
        );
    }

    /**
     * Procedures of the patient the user may read: with access to all the patient's data (an active declaration or
     * approval) all of them, including the ones of the persons and prepersons merged into a person, otherwise only the
     * patient's own ones managed by the current legal entity, created within an encounter of its episode, originated by
     * its episode or created within an encounter originated by its episode.
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
            static fn (Builder $procedure): Builder => $procedure->forLegalEntity($patient)
                ->orWhere(static fn (Builder $inEpisode): Builder => $inEpisode->inEpisodeOfLegalEntity($patient))
                ->orWhere(static fn (Builder $originated): Builder => $originated->originatedInLegalEntity($patient))
                ->orWhere(
                    static fn (Builder $originated): Builder => $originated->inEncounterOriginatedInLegalEntity($patient)
                )
        );
    }

    /**
     * Filter procedures recorded within the given encounter, which is stored as an identifier holding its eHealth ID.
     *
     * @param  Builder  $query
     * @param  string  $encounterId
     * @return Builder
     */
    #[Scope]
    protected function forEncounter(Builder $query, string $encounterId): Builder
    {
        return $query->whereHas(
            'encounter',
            static fn (Builder $identifier): Builder => $identifier->whereValue($encounterId)
        );
    }

    /**
     * Limit procedures to the services allowed in the patient summary.
     * The procedure stores its service as a reference, so the allowed service codes are resolved to ids first.
     * An empty list of allowed codes leaves nothing to show, exactly as the eHealth summary does.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function allowedForSummary(Builder $query): Builder
    {
        $serviceIds = dictionary()->services()
            ->flattened()
            ->whereIn('code', config('ehealth.procedure.summary_procedures_allowed'))
            ->pluck('id');

        return $query->whereHas(
            'code',
            static fn (Builder $identifier): Builder => $identifier->whereIn('value', $serviceIds)
        );
    }
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'basedOn.type.coding',
            'statusReason.coding',
            'code.type.coding',
            'encounter.type.coding',
            'originEpisode.type.coding',
            'recordedBy.type.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'division.type.coding',
            'managingOrganization.type.coding',
            'outcome.coding',
            'category.coding',
            'performedPeriod',
            'reasonReferences.type.coding',
            'complicationDetails.type.coding',
            'usedReferences.type.coding',
            'paperReferral',
            'usedCodes.coding',
            'focalDevices.action.coding',
            'focalDevices.manipulated.type.coding'
        ]);
    }
}

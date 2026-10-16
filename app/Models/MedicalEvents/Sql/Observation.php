<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Person\EncounterStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Casts\EHealthTimestampCast;
use App\Enums\Person\ObservationStatus;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

class Observation extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'based_on_id',
        'status',
        'diagnostic_report_id',
        'code_id',
        'effective_date_time',
        'issued',
        'primary_source',
        'performer_id',
        'report_origin_id',
        'interpretation_id',
        'comment',
        'body_site_id',
        'method_id',
        'reaction_on_id',
        'context_id',
        'specimen_id',
        'device_id',
        'ehealth_inserted_at',
        'ehealth_updated_at',
        'explanatory_letter'
    ];

    protected $casts = [
        'issued' => EHealthTimestampCast::class,
        'effective_date_time' => EHealthTimestampCast::class,
        'status' => ObservationStatus::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    protected $appends = [
        'issued_date',
        'issued_time',
        'effective_date',
        'effective_time',
        'effective_period_start_date',
        'effective_period_start_time',
        'effective_period_end_date',
        'effective_period_end_time'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'diagnostic_report_id',
        'code_id',
        'performer_id',
        'report_origin_id',
        'interpretation_id',
        'body_site_id',
        'method_id',
        'reaction_on_id',
        'context_id',
        'specimen_id',
        'device_id',
        'based_on_id',
        'created_at',
        'updated_at'
    ];

    protected function issuedDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => convertToAppDateFormat($this->issued),
        );
    }

    protected function issuedTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => CarbonImmutable::parse($this->issued)->format('H:i'),
        );
    }

    protected function effectiveDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->effectiveDateTime ? convertToAppDateFormat($this->effectiveDateTime) : '',
        );
    }

    protected function effectiveTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->effectiveDateTime
                ? CarbonImmutable::parse($this->effectiveDateTime)->format('H:i')
                : '',
        );
    }

    protected function effectivePeriodStartDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->effectivePeriod
                ? CarbonImmutable::parse($this->effectivePeriod->start)->format(config('app.date_format'))
                : '',
        );
    }

    protected function effectivePeriodStartTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->effectivePeriod
                ? CarbonImmutable::parse($this->effectivePeriod->start)->format('H:i')
                : '',
        );
    }

    protected function effectivePeriodEndDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->effectivePeriod?->end
                ? CarbonImmutable::parse($this->effectivePeriod->end)->format(config('app.date_format'))
                : '',
        );
    }

    protected function effectivePeriodEndTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->effectivePeriod?->end
                ? CarbonImmutable::parse($this->effectivePeriod->end)->format('H:i')
                : '',
        );
    }

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

    public function diagnosticReport(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'diagnostic_report_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'observation_categories')->withTimestamps();
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function effectivePeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function interpretation(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'interpretation_id');
    }

    public function bodySite(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'body_site_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'method_id');
    }

    public function value(): HasOne
    {
        return $this->hasOne(Value::class);
    }

    public function reactionOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'reaction_on_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function specimen(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'specimen_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'device_id');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function referenceRanges(): HasMany
    {
        return $this->hasMany(ReferenceRange::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(ObservationComponent::class);
    }

    /**
     * Filter observations belonging to the given patient (person or preperson).
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
     * Leave out the records of an encounter package eHealth has not accepted yet, which is still a draft.
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
                Encounter::forPatient($patient)->whereStatus(EncounterStatus::DRAFT)->pluck('uuid')
            )
        );
    }

    /**
     * Leave out the observations of a diagnostic report eHealth has not accepted yet, which is still a draft.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function notInDraftDiagnosticReport(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->whereDoesntHave(
            'diagnosticReport',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                DiagnosticReport::forPatient($patient)->whereStatus(DiagnosticReportStatus::DRAFT)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's observations of the current legal entity: the managing organization of the episode or of
     * the diagnostic report is the current legal entity.
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
        return $query->forPatient($patient)->where(
            static fn (Builder $observation): Builder => $observation
                ->whereHas(
                    'context',
                    static fn (Builder $identifier): Builder => $identifier->whereIn(
                        'value',
                        Encounter::forLegalEntity($patient)->pluck('uuid')
                    )
                )
                ->orWhereHas(
                    'diagnosticReport',
                    static fn (Builder $identifier): Builder => $identifier->whereIn(
                        'value',
                        DiagnosticReport::forPatient($patient)->managedByLegalEntity()->pluck('uuid')
                    )
                )
        );
    }

    /**
     * Filter the patient's observations created within an encounter whose episode is managed by the current legal
     * entity.
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
     * Filter the patient's observations belonging to a diagnostic report originated by an episode managed by the
     * current legal entity.
     *
     * ABAC rule 7.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function inDiagnosticReportOriginatedInLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'diagnosticReport',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                DiagnosticReport::originatedInLegalEntity($patient)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's observations created within an encounter originated by an episode managed by the current
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
            'context',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Encounter::originatedInLegalEntity($patient)->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's observations belonging to a diagnostic report managed by the current legal entity.
     *
     * ABAC rule 10.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function inDiagnosticReportOfLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'diagnosticReport',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                DiagnosticReport::forPatient($patient)->managedByLegalEntity()->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's observations belonging to a diagnostic report with an active approval granted to one of the
     * user's employees or to the current legal entity.
     *
     * ABAC rule 11.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  User  $user
     * @return Builder
     */
    #[Scope]
    protected function inApprovedDiagnosticReport(Builder $query, Person|Preperson $patient, User $user): Builder
    {
        $diagnosticReportIds = DiagnosticReport::forPatient($patient)
            ->whereKey(Approval::diagnosticReportAccessGrantedTo($user, legalEntity())->pluck('approvable_id'))
            ->pluck('uuid');

        return $query->forPatient($patient)->whereHas(
            'diagnosticReport',
            static fn (Builder $identifier): Builder => $identifier->whereIn('value', $diagnosticReportIds)
        );
    }

    /**
     * Observations of the patient the user may read: with access to all the patient's data (an active declaration or
     * approval) all of them, including the ones of the persons and prepersons merged into a person, otherwise only
     * the patient's own ones of the current legal entity, originated by its episode or of an approved diagnostic report.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  bool  $hasPatientAccess
     * @param  User  $user
     * @return Builder
     */
    #[Scope]
    protected function readableFor(Builder $query, Person|Preperson $patient, bool $hasPatientAccess, User $user): Builder
    {
        if ($hasPatientAccess) {
            return $patient instanceof Person
                ? $patient->filterRecordsWithMerged($query)
                : $query->forPatient($patient);
        }

        return $query->where(
            static fn (Builder $observation): Builder => $observation->forLegalEntity($patient)
                ->orWhere(
                    static fn (Builder $reported): Builder => $reported->inDiagnosticReportOriginatedInLegalEntity($patient)
                )
                ->orWhere(
                    static fn (Builder $originated): Builder => $originated->inEncounterOriginatedInLegalEntity($patient)
                )
                ->orWhere(
                    static fn (Builder $approved): Builder => $approved->inApprovedDiagnosticReport($patient, $user)
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
     * Filter observations created within the given encounter, which is stored as the context identifier.
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

    /**
     * Limit observations to the codes allowed in the patient summary.
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
                config('ehealth.summary_observations_allowed')
            )
        );
    }

    /**
     * Scope to eager load all observation relationships.
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'code.coding',
            'categories.coding',
            'diagnosticReport.type.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'interpretation.coding',
            'bodySite.coding',
            'method.coding',
            'value.valueQuantity',
            'value.valueCodeableConcept.coding',
            'value.valueRange.low',
            'value.valueRange.high',
            'value.valueRatio.numerator',
            'value.valueRatio.denominator',
            'value.valueSampledData',
            'effectivePeriod',
            'context.type.coding',
            'specimen.type.coding',
            'device.type.coding',
            'basedOn.type.coding',
            'referenceRanges.low',
            'referenceRanges.high',
            'components.code.coding',
            'components.interpretation.coding',
            'components.value.valueQuantity',
            'components.value.valueCodeableConcept.coding',
            'components.value.valueRange.low',
            'components.value.valueRange.high',
            'components.value.valueRatio.numerator',
            'components.value.valueRatio.denominator',
            'components.value.valueSampledData',
            'components.referenceRanges.low',
            'components.referenceRanges.high'
        ]);
    }
}

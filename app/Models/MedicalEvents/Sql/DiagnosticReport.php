<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Person\EncounterStatus;
use App\Casts\EHealthTimestampCast;
use App\Contracts\SubmittedToEHealth;
use App\Enums\Person\DiagnosticReportStatus;
use App\Models\EhealthLink;
use App\Models\Person\Person;
use App\Models\Preperson;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class DiagnosticReport extends Model implements SubmittedToEHealth
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'based_on_id',
        'status',
        'code_id',
        'effective_date_time',
        'issued',
        'conclusion',
        'conclusion_code_id',
        'recorded_by_id',
        'encounter_id',
        'primary_source',
        'division_id',
        'managing_organization_id',
        'report_origin_id',
        'origin_episode_id',
        'explanatory_letter',
        'cancellation_reason_id',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'based_on_id',
        'code_id',
        'conclusion_code_id',
        'recorded_by_id',
        'division_id',
        'managing_organization_id',
        'encounter_id',
        'report_origin_id',
        'origin_episode_id',
        'cancellation_reason_id',
        'created_at',
        'updated_at'
    ];

    protected $appends = [
        'issued_date',
        'issued_time',
        'effective_period_start_date',
        'effective_period_start_time',
        'effective_period_end_date',
        'effective_period_end_time',
        'effective_date',
        'effective_time',
    ];

    protected $casts = [
        'status' => DiagnosticReportStatus::class,
        'issued' => EHealthTimestampCast::class,
        'effective_date_time' => EHealthTimestampCast::class,
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
            get: fn (): string => $this->effectiveDateTime ? CarbonImmutable::parse($this->effectiveDateTime)->format('H:i') : '',
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
            get: fn (): string =>
                $this->effectivePeriod?->end ? CarbonImmutable::parse($this->effectivePeriod->end)->format(config('app.date_format')) : '',
        );
    }

    protected function effectivePeriodEndTime(): Attribute
    {
        return Attribute::make(
            get: fn (): string =>
                $this->effectivePeriod?->end ? CarbonImmutable::parse($this->effectivePeriod->end)->format('H:i') : '',
        );
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
     * eHealth jobs the signed report was submitted with, the latest one telling how the last submission went.
     *
     * @return MorphMany
     */
    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function paperReferral(): MorphOne
    {
        return $this->morphOne(PaperReferral::class, 'paper_referralable');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'code_id');
    }

    public function category(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'diagnostic_report_categories')->withTimestamps();
    }

    public function effectivePeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function conclusionCode(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'conclusion_code_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'recorded_by_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'encounter_id');
    }

    public function originEpisode(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'origin_episode_id');
    }

    public function cancellationReason(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'cancellation_reason_id');
    }

    public function performer(): HasMany
    {
        return $this->hasMany(DiagnosticReportPerformer::class);
    }

    public function managingOrganization(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'managing_organization_id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'division_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function resultsInterpreter(): HasOne
    {
        return $this->hasOne(DiagnosticReportResultsInterpreter::class);
    }

    public function specimens(): BelongsToMany
    {
        return $this->belongsToMany(
            Identifier::class,
            'diagnostic_report_specimens',
            'diagnostic_report_id',
            'identifier_id'
        )->withTimestamps();
    }

    public function usedReferences(): BelongsToMany
    {
        return $this->belongsToMany(
            Identifier::class,
            'diagnostic_report_used_references',
            'diagnostic_report_id',
            'identifier_id'
        )->withTimestamps();
    }

    /**
     * Filter diagnostic reports belonging to the given patient (person or preperson).
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
            'encounter',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Encounter::forPatient($patient)->whereStatus(EncounterStatus::DRAFT)->pluck('uuid')
            )
        );
    }

    /**
     * Filter diagnostic reports managed by the current legal entity.
     *
     * ABAC rule 10.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function managedByLegalEntity(Builder $query): Builder
    {
        return $query->whereHas(
            'managingOrganization',
            static fn (Builder $identifier): Builder => $identifier->whereValue(legalEntity()->uuid)
        );
    }

    /**
     * Filter the patient's diagnostic reports of the current legal entity: the managing organization of the episode
     * of the report's encounter or of the report itself is the current legal entity.
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
            static fn (Builder $report): Builder => $report
                ->whereHas(
                    'encounter',
                    static fn (Builder $identifier): Builder => $identifier->whereIn(
                        'value',
                        Encounter::forLegalEntity($patient)->pluck('uuid')
                    )
                )
                ->orWhere(static fn (Builder $managed): Builder => $managed->managedByLegalEntity())
        );
    }

    /**
     * Filter the patient's diagnostic reports created within an encounter whose episode is managed by the current
     * legal entity.
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
     * Filter the patient's diagnostic reports created within an encounter originated by an episode managed by the
     * current legal entity.
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
     * Filter the patient's diagnostic reports originated by an episode managed by the current legal entity.
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
     * Diagnostic reports of the patient the user may read: with access to all the patient's data (an active
     * declaration or approval) all of them, including the ones of the persons and prepersons merged into a person,
     * otherwise only the patient's own ones of the current legal entity, originated by its episode or created within
     * an encounter originated by its episode.
     *
     * ABAC rule 1.
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
            static fn (Builder $report): Builder => $report->forLegalEntity($patient)
                ->orWhere(static fn (Builder $originated): Builder => $originated->originatedInLegalEntity($patient))
                ->orWhere(
                    static fn (Builder $originated): Builder => $originated->inEncounterOriginatedInLegalEntity($patient)
                )
        );
    }

    /**
     * Order by most recently updated in eHealth first, keeping records without a timestamp last. Drafts lead the list,
     * by their local update time, as they are not in eHealth yet.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function recentlyUpdatedFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [DiagnosticReportStatus::DRAFT->value])
            ->orderByRaw('CASE WHEN ehealth_updated_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('ehealth_updated_at')
            ->orderByDesc('updated_at');
    }

    /**
     * Filter diagnostic reports with a final status.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function final(Builder $query): Builder
    {
        return $query->whereStatus(DiagnosticReportStatus::FINAL);
    }

    /**
     * Filter reports recorded within the given encounter, which is stored as an identifier holding its eHealth ID.
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
     * Limit diagnostic reports to the services allowed in the patient summary.
     * The report stores its service as a reference, so the allowed service codes are resolved to ids first.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function allowedForSummary(Builder $query): Builder
    {
        $serviceIds = dictionary()->services()
            ->flattened()
            ->whereIn('code', config('ehealth.summary_diagnostic_reports_allowed'))
            ->pluck('id');

        return $query->whereHas(
            'code',
            static fn (Builder $identifier): Builder => $identifier->whereIn('value', $serviceIds)
        );
    }

    /**
     * Scope to eager load all diagnostic report relationships.
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'basedOn.type.coding',
            'paperReferral',
            'code.type.coding',
            'category.coding',
            'effectivePeriod',
            'conclusionCode.coding',
            'recordedBy.type.coding',
            'encounter.type.coding',
            'originEpisode.type.coding',
            'cancellationReason.coding',
            'performer.reference.type.coding',
            'managingOrganization.type.coding',
            'division.type.coding',
            'reportOrigin.coding',
            'resultsInterpreter.reference.type.coding',
            'specimens.type.coding',
            'usedReferences.type.coding'
        ]);
    }
}

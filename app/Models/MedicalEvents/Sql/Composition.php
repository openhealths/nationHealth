<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Models\Person\Person;
use App\Models\Preperson;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Local representation of an eHealth Composition (МВН/МВТН).
 *
 * Compositions are fetched from the eHealth API and cached in this table to allow
 * efficient listing and filtering without hitting the API on every request.
 * The full API response is stored in the `data` JSON column for display purposes.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $personId
 * @property int|null $prepersonId
 * @property CompositionType $type
 * @property CompositionCategory|null $category
 * @property CompositionStatus $status
 * @property string|null $title
 * @property string|null $encounterUuid
 * @property string|null $episodeOfCareUuid
 * @property string|null $authorUuid
 * @property string|null $custodianUuid
 * @property string|null $sectionFocusUuid
 * @property string|null $subjectUuid
 * @property string|null $eventPeriodStart
 * @property string|null $eventPeriodEnd
 * @property string|null $compositionDate
 * @property string|null $informWithUuid
 * @property bool|null $isAccident
 * @property bool|null $isIntoxicated
 * @property bool|null $isForeignTreatment
 * @property bool|null $isForceRenew
 * @property string|null $treatmentViolation
 * @property CarbonImmutable|null $treatmentViolationDate
 * @property CarbonImmutable|null $newbornBirthDate
 * @property string|null $newbornSex
 * @property string|null $relatesToCode
 * @property string|null $relatesToTargetUuid
 * @property string|null $asyncJobId
 * @property string|null $asyncJobStatus
 * @property string|null $erlnStatus
 * @property string|null $erlnRecordNumber
 * @property string|null $erlnStatusMessage
 * @property array|null $data
 * @property string|null $ehealthInsertedAt
 * @property string|null $ehealthUpdatedAt
 *
 * @property-read bool $isSigned
 * @property-read bool $isNewborn
 * @property-read bool $isTempDisability
 * @property-read bool $hasReadContext
 * @property-read string|null $patientUuid
 */
class Composition extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'type',
        'category',
        'status',
        'title',
        'encounter_uuid',
        'episode_of_care_uuid',
        'author_uuid',
        'custodian_uuid',
        'section_focus_uuid',
        'subject_uuid',
        'event_period_start',
        'event_period_end',
        'composition_date',
        'inform_with_uuid',
        'is_accident',
        'is_intoxicated',
        'is_foreign_treatment',
        'is_force_renew',
        'treatment_violation',
        'treatment_violation_date',
        'newborn_birth_date',
        'newborn_sex',
        'relates_to_code',
        'relates_to_target_uuid',
        'async_job_id',
        'async_job_status',
        'erln_status',
        'erln_record_number',
        'erln_status_message',
        'data',
        'ehealth_inserted_at',
        'ehealth_updated_at',
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompositionStatus::class,
            'type' => CompositionType::class,
            'category' => CompositionCategory::class,
            'is_accident' => 'boolean',
            'is_intoxicated' => 'boolean',
            'is_foreign_treatment' => 'boolean',
            'is_force_renew' => 'boolean',
            'data' => 'array',
            'treatment_violation_date' => 'immutable_date',
            'newborn_birth_date' => 'immutable_date',
            'event_period_start' => EHealthTimestampCast::class,
            'event_period_end' => EHealthTimestampCast::class,
            'composition_date' => EHealthTimestampCast::class,
            'ehealth_inserted_at' => EHealthTimestampCast::class,
            'ehealth_updated_at' => EHealthTimestampCast::class,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────────

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Attribute accessors
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Human-readable event period start date.
     */
    protected function eventPeriodStartDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->eventPeriodStart
                ? CarbonImmutable::parse($this->eventPeriodStart)->format(config('app.date_format'))
                : '',
        );
    }

    /**
     * Human-readable event period end date.
     */
    protected function eventPeriodEndDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->eventPeriodEnd
                ? CarbonImmutable::parse($this->eventPeriodEnd)->format(config('app.date_format'))
                : '',
        );
    }

    /**
     * Human-readable composition creation date.
     */
    protected function compositionDateFormatted(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->compositionDate
                ? CarbonImmutable::parse($this->compositionDate)->format(config('app.date_format'))
                : '',
        );
    }

    /**
     * Whether this composition is signed (FINAL status).
     */
    protected function isSigned(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === CompositionStatus::FINAL,
        );
    }

    /**
     * Identifier eHealth expects as `patientId` when reading this conclusion.
     *
     * A birth conclusion is filed against the newborn, who exists only as a preperson,
     * so the read-side context is keyed on the subject rather than on the person.
     */
    protected function patientUuid(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->subjectUuid,
        );
    }

    /**
     * Whether the read-side endpoints can be called at all.
     *
     * getComposition, getPrintForm and getIntegrationData are all addressed through the
     * episode and encounter the conclusion was built on, so without that context there
     * is nothing we can request.
     */
    protected function hasReadContext(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => filled($this->subjectUuid)
                && filled($this->episodeOfCareUuid)
                && filled($this->encounterUuid),
        );
    }

    /**
     * Whether this is a МВТН (TEMP_DISABILITY) composition.
     */
    protected function isTempDisability(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->type === CompositionType::TEMP_DISABILITY,
        );
    }

    /**
     * Whether this is a МВН (NEWBORN) composition.
     */
    protected function isNewborn(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->type === CompositionType::NEWBORN,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Query Scopes
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Filter compositions belonging to the given patient (person or preperson).
     */
    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder
    {
        return $patient instanceof Preperson
            ? $query->wherePrepersonId($patient->id)
            : $query->wherePersonId($patient->id);
    }

    /**
     * Filter by composition type.
     */
    #[Scope]
    protected function ofType(Builder $query, CompositionType $type): Builder
    {
        return $query->whereType($type->value);
    }

    /**
     * Filter signed (FINAL) compositions.
     */
    #[Scope]
    protected function final(Builder $query): Builder
    {
        return $query->whereStatus(CompositionStatus::FINAL->value);
    }

    /**
     * Filter by composition status.
     */
    #[Scope]
    protected function withStatus(Builder $query, CompositionStatus $status): Builder
    {
        return $query->whereStatus($status->value);
    }

    /**
     * Filter by the person the conclusion is issued about (section.focus).
     */
    #[Scope]
    protected function forFocus(Builder $query, string $focusUuid): Builder
    {
        return $query->whereSectionFocusUuid($focusUuid);
    }

    /**
     * Filter by the encounter the conclusion was built on.
     */
    #[Scope]
    protected function forEncounter(Builder $query, string $encounterUuid): Builder
    {
        return $query->whereEncounterUuid($encounterUuid);
    }

    /**
     * Exclude conclusions that were marked as entered in error.
     *
     * A newborn may only ever have one conclusion that is not in error, so this scope is
     * what the duplicate check in TV 3.8.1.3 is built on.
     */
    #[Scope]
    protected function excludingErrors(Builder $query): Builder
    {
        return $query->where('status', '!=', CompositionStatus::ENTERED_IN_ERROR->value);
    }

    /**
     * Order by most recently updated in eHealth first.
     */
    #[Scope]
    protected function recentlyUpdatedFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN ehealth_updated_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('ehealth_updated_at');
    }
}

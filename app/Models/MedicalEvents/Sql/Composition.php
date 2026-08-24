<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Composition extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'title',
        'date',
        'type_id',
        'category_id',
        'encounter_id',
        'author_id',
        'custodian_id',
        'subject_id',
        'section_focus_id',
        'episode_of_care_id',
        'relates_to_code',
        'relates_to_target_id',
        'extension',
        'data',
        'async_job_id',
        'async_job_status',
        'erln_status',
        'erln_record_number',
        'erln_status_message',
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
            'date' => EHealthTimestampCast::class,
            'extension' => 'array',
            'data' => 'array',
            'ehealth_inserted_at' => EHealthTimestampCast::class,
            'ehealth_updated_at' => EHealthTimestampCast::class,
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function typeCodeableConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'type_id');
    }

    public function categoryCodeableConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'encounter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'author_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'custodian_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'subject_id');
    }

    public function sectionFocus(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'section_focus_id');
    }

    public function episodeOfCare(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'episode_of_care_id');
    }

    public function relatesToTarget(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'relates_to_target_id');
    }

    public function eventPeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    protected function type(): Attribute
    {
        return Attribute::get(
            fn (): ?CompositionType => CompositionType::tryFrom((string) $this->typeCodeableConcept?->coding->first()?->code)
        );
    }

    protected function category(): Attribute
    {
        return Attribute::get(
            fn (): ?CompositionCategory => CompositionCategory::tryFrom((string) $this->categoryCodeableConcept?->coding->first()?->code)
        );
    }

    protected function encounterUuid(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->encounter?->value);
    }

    protected function episodeOfCareUuid(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->episodeOfCare?->value);
    }

    protected function authorUuid(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->author?->value);
    }

    protected function subjectUuid(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->subject?->value);
    }

    protected function sectionFocusUuid(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->sectionFocus?->value);
    }

    protected function patientUuid(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->subjectUuid);
    }

    protected function eventPeriodStart(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->eventPeriod?->start);
    }

    protected function eventPeriodEnd(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->eventPeriod?->end);
    }

    protected function eventPeriodStartDate(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->eventPeriod?->start
                ? \Carbon\CarbonImmutable::parse($this->eventPeriod->start)->format((string) config('app.date_format'))
                : ''
        );
    }

    protected function eventPeriodEndDate(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->eventPeriod?->end
                ? \Carbon\CarbonImmutable::parse($this->eventPeriod->end)->format((string) config('app.date_format'))
                : ''
        );
    }

    protected function compositionDateFormatted(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->date
                ? \Carbon\CarbonImmutable::parse($this->date)->format((string) config('app.date_format'))
                : ''
        );
    }

    protected function isSigned(): Attribute
    {
        return Attribute::get(fn (): bool => $this->status === CompositionStatus::FINAL);
    }

    protected function hasReadContext(): Attribute
    {
        return Attribute::get(
            fn (): bool => filled($this->subjectUuid)
                && filled($this->episodeOfCareUuid)
                && filled($this->encounterUuid)
        );
    }

    protected function isTempDisability(): Attribute
    {
        return Attribute::get(fn (): bool => $this->type === CompositionType::TEMP_DISABILITY);
    }

    protected function isNewborn(): Attribute
    {
        return Attribute::get(fn (): bool => $this->type === CompositionType::NEWBORN);
    }

    protected function informWithUuid(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->extensionValue('INFORM_WITH', 'valueUuid'));
    }

    protected function isAccident(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->extensionValue('IS_ACCIDENT', 'valueBoolean'));
    }

    protected function isIntoxicated(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->extensionValue('IS_INTOXICATED', 'valueBoolean'));
    }

    protected function isForeignTreatment(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->extensionValue('IS_FOREIGN_TREATMENT', 'valueBoolean'));
    }

    protected function isForceRenew(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->extensionValue('IS_FORCE_RENEW', 'valueBoolean'));
    }

    protected function treatmentViolation(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->extensionValue('TREATMENT_VIOLATION', 'valueString'));
    }

    protected function treatmentViolationDate(): Attribute
    {
        return Attribute::get(function (): mixed {
            $value = $this->extensionValue('TREATMENT_VIOLATION_DATE', 'valueDate');

            return $value ? \Carbon\CarbonImmutable::parse((string) $value) : null;
        });
    }

    protected function newbornBirthDate(): Attribute
    {
        return Attribute::get(function (): mixed {
            $value = $this->extensionValue('NEWBORN_BIRTH_DATE', 'valueDate');

            return $value ? \Carbon\CarbonImmutable::parse((string) $value) : null;
        });
    }

    protected function newbornSex(): Attribute
    {
        return Attribute::get(fn (): mixed => $this->extensionValue('NEWBORN_SEX', 'valueString'));
    }

    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder
    {
        return $patient instanceof Preperson
            ? $query->where('preperson_id', $patient->id)
            : $query->where('person_id', $patient->id);
    }

    #[Scope]
    protected function ofType(Builder $query, CompositionType $type): Builder
    {
        return $query->whereHas(
            'typeCodeableConcept.coding',
            static fn (Builder $coding) => $coding->where('code', $type->value)
        );
    }

    #[Scope]
    protected function ofStatus(Builder $query, CompositionStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    #[Scope]
    protected function signed(Builder $query): Builder
    {
        return $query->where('status', CompositionStatus::FINAL->value);
    }

    #[Scope]
    protected function forFocus(Builder $query, string $focusUuid): Builder
    {
        return $query->whereHas('sectionFocus', static fn (Builder $identifier) => $identifier->where('value', $focusUuid));
    }

    #[Scope]
    protected function forEncounter(Builder $query, string $encounterUuid): Builder
    {
        return $query->whereHas('encounter', static fn (Builder $identifier) => $identifier->where('value', $encounterUuid));
    }

    #[Scope]
    protected function excludingErrors(Builder $query): Builder
    {
        return $query->where('status', '!=', CompositionStatus::ENTERED_IN_ERROR->value);
    }

    #[Scope]
    protected function recentlyUpdatedFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN ehealth_updated_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('ehealth_updated_at');
    }

    private function extensionValue(string $code, string $valueKey): mixed
    {
        $item = collect($this->extension ?? [])
            ->first(static fn (mixed $item): bool => is_array($item) && ($item['valueCode'] ?? null) === $code);

        return is_array($item) ? ($item[$valueKey] ?? null) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Enums\Composition\CompositionExtension;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'inform_with_uuid',
        'is_accident',
        'is_intoxicated',
        'is_foreign_treatment',
        'is_force_renew',
        'treatment_violation',
        'treatment_violation_date',
        'newborn_birth_date',
        'newborn_sex',
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
            'is_accident' => 'boolean',
            'is_intoxicated' => 'boolean',
            'is_foreign_treatment' => 'boolean',
            'is_force_renew' => 'boolean',
            'treatment_violation_date' => 'immutable_date',
            'newborn_birth_date' => 'immutable_date',
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

    public function typeConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'type_id');
    }

    public function categoryConcept(): BelongsTo
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
            fn (): ?CompositionType => CompositionType::tryFrom((string) $this->typeConcept?->coding->first()?->code)
        );
    }

    protected function category(): Attribute
    {
        return Attribute::get(
            fn (): ?CompositionCategory => CompositionCategory::tryFrom((string) $this->categoryConcept?->coding->first()?->code)
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

    public function integrations(): HasMany
    {
        return $this->hasMany(CompositionIntegration::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CompositionOperation::class);
    }

    public function latestOperation(): HasOne
    {
        return $this->hasOne(CompositionOperation::class)->latestOfMany();
    }

    protected function erlnStatus(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->erlnIntegration()?->integration_status);
    }

    protected function erlnRecordNumber(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->erlnIntegration()?->record_number);
    }

    protected function erlnStatusMessage(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->erlnIntegration()?->status_message);
    }

    public function integrationDetails(): array
    {
        return $this->integrations->map(fn (CompositionIntegration $item): array => $item->toDetail())->all();
    }

    /** Local presentation only. Signing always uses a fresh eHealth response. */
    public function toDetail(): array
    {
        $this->loadMissing(['typeConcept.coding', 'categoryConcept.coding']);
        $extensions = [];
        foreach (CompositionExtension::cases() as $field) {
            $value = $this->getAttribute($field->column());
            if ($value === null || ($field->valueKey() === 'valueBoolean' && $value === false)) {
                continue;
            }
            $extensions[] = [
                'valueCode' => $field->value,
                $field->valueKey() => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value,
            ];
        }
        $detail = [
            'identifier' => ['value' => $this->uuid],
            'status' => $this->status->value,
            'title' => $this->title,
            'date' => $this->isoDate($this->getRawOriginal('date')),
            'type' => $this->typeConcept?->toArray(),
            'category' => $this->categoryConcept?->toArray(),
            'section' => ['focus' => $this->sectionFocus?->identifier],
            'event' => [['period' => [
                'start' => $this->isoDate($this->eventPeriod?->getRawOriginal('start')),
                'end' => $this->isoDate($this->eventPeriod?->getRawOriginal('end')),
            ]]],
            'extension' => $extensions,
        ];
        foreach (['encounter', 'author', 'custodian', 'subject'] as $reference) {
            $detail[$reference] = $this->{$reference}?->identifier;
        }
        if ($this->relatesToCode !== null) {
            $detail['relatesTo'] = ['code' => $this->relatesToCode, 'targetIdentifier' => $this->relatesToTarget?->identifier];
        }

        return $detail;
    }

    private function isoDate(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString() : null;
    }

    private function erlnIntegration(): ?CompositionIntegration
    {
        return $this->integrations->first(fn (CompositionIntegration $item): bool =>
            $item->component === 'ERLN' && $item->type === 'CREATE_ERLN_RECORD');
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
            'typeConcept.coding',
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

}

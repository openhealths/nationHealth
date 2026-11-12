<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\SubmittedToEHealth;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Period;
use App\Models\MedicalEvents\Sql\Quantity;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CarePlanActivity extends Model implements SubmittedToEHealth
{
    use HasFactory;
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'care_plan_id',
        'author_id',
        'status',
        'do_not_perform',
        'kind',
        'product_reference',
        'product_codeable_concept',
        'quantity',
        'quantity_system',
        'quantity_code',
        'daily_amount',
        'daily_amount_system',
        'daily_amount_code',
        'remaining_quantity',
        'remaining_quantity_system',
        'remaining_quantity_code',
        'reason_code',
        'reason_reference',
        'goal',
        'description',
        'program',
        'scheduled_period_start',
        'scheduled_period_end',
        'status_reason',
        'outcome_reference',
        'outcome_codeable_concept',
        'kind_id',
        'product_codeable_concept_id',
        'reason_code_id',
        'outcome_codeable_concept_id',
        'product_reference_id',
        'quantity_id',
        'daily_amount_id',
        'remaining_quantity_id',
        'remaining_quantity_type',
        'location_identifier_id',
        'performer_identifier_id',
        'program_identifier_id',
        'status_reason_concept_id',
        'scheduled_timing',
        'scheduled_string',

        'details_synced_at',
        'care_plan_reference_id',
    ];

    protected $casts = [
        'do_not_perform' => 'boolean',
        'quantity' => 'float',
        'daily_amount' => 'float',
        'remaining_quantity' => 'float',
        'reason_reference' => 'array',
        'goal' => 'array',
        'scheduled_period_start' => 'date',
        'scheduled_period_end' => 'date',
        'scheduled_timing' => 'array',

        'details_synced_at' => 'datetime',
    ];

    public function quantityQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'quantity_id');
    }

    public function remainingQuantityQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'remaining_quantity_id');
    }

    public function locationIdentifier(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'location_identifier_id');
    }

    public function performerIdentifier(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_identifier_id');
    }

    public function programIdentifier(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'program_identifier_id');
    }

    public function statusReasonConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_concept_id');
    }

    public function authorReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'care_plan_activity_authors', 'activity_id', 'identifier_id')->withPivot('position')->orderByPivot('position');
    }

    public function reasonConcepts(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'care_plan_activity_reason_codes', 'activity_id', 'concept_id')->withPivot('position')->orderByPivot('position');
    }

    public function goalConcepts(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'care_plan_activity_goal_concepts', 'activity_id', 'concept_id')->withPivot('position')->orderByPivot('position');
    }

    public function outcomeConcepts(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'care_plan_activity_outcome_concepts', 'activity_id', 'concept_id')->withPivot('position')->orderByPivot('position');
    }

    public function dailyAmountQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'daily_amount_id');
    }

    public function scheduledPeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function carePlanReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'care_plan_reference_id');
    }

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function kindConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'kind_id');
    }

    public function productConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'product_codeable_concept_id');
    }

    public function reasonConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'reason_code_id');
    }

    public function outcomeConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'outcome_codeable_concept_id');
    }

    public function productReferenceIdentifier(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'product_reference_id');
    }

    public function reasonReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'care_plan_activity_reasons', 'activity_id', 'identifier_id');
    }

    public function goalReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'care_plan_activity_goals', 'activity_id', 'identifier_id');
    }

    public function outcomeReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'care_plan_activity_outcomes', 'activity_id', 'identifier_id');
    }

    /**
     * Canonical activity kind used for UI and business rules.
     * The `kind` column is the source of truth; kindConcept may be stale after manual edits.
     */
    public function resolvedKind(): string
    {
        $kind = strtolower(trim((string) ($this->kind ?? '')));

        if ($kind !== '') {
            return match ($kind) {
                'servicerequest' => 'service_request',
                'medicationrequest' => 'medication_request',
                'devicerequest' => 'device_request',
                default => $kind,
            };
        }

        $this->loadMissing('kindConcept.coding');
        $conceptCode = strtolower(trim((string) ($this->kindConcept?->coding?->first()?->code ?? '')));

        return match ($conceptCode) {
            'servicerequest', 'service_request' => 'service_request',
            'medicationrequest', 'medication_request' => 'medication_request',
            'devicerequest', 'device_request' => 'device_request',
            default => $conceptCode,
        };
    }

    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
    }
    public function getProductReferenceAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->productReferenceIdentifier?->value;
    }

    public function getProductReferenceTypeAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->productReferenceIdentifier?->type->first()?->coding->first()?->code;
    }

    public function getProductCodeableConceptAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->productConcept?->coding->first()?->code;
    }

    public function getProgramAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->programIdentifier?->value;
    }

    public function getReasonCodeAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->reasonConcepts->first()?->coding->first()?->code;
    }

    public function getStatusReasonAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->statusReasonConcept?->coding->first()?->code;
    }

    public function getScheduledPeriodStartAttribute(?string $value): ?CarbonImmutable
    {
        $date = $this->detailsSyncedAt === null ? $value : $this->scheduledPeriod?->start;

        return filled($date) ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    public function getScheduledPeriodEndAttribute(?string $value): ?CarbonImmutable
    {
        $date = $this->detailsSyncedAt === null ? $value : $this->scheduledPeriod?->end;

        return filled($date) ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    public function getQuantityAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? ($value === null ? null : (float) $value) : ($this->quantityQuantity?->value === null ? null : (float) $this->quantityQuantity?->value);
    }

    public function getQuantitySystemAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->quantityQuantity?->system;
    }

    public function getQuantityCodeAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->quantityQuantity?->code;
    }

    public function getDailyAmountAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? ($value === null ? null : (float) $value) : ($this->dailyAmountQuantity?->value === null ? null : (float) $this->dailyAmountQuantity?->value);
    }

    public function getDailyAmountSystemAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->dailyAmountQuantity?->system;
    }

    public function getDailyAmountCodeAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->dailyAmountQuantity?->code;
    }

    public function getRemainingQuantityAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? ($value === null ? null : (float) $value) : ($this->remainingQuantityQuantity?->value === null ? null : (float) $this->remainingQuantityQuantity?->value);
    }

    public function getRemainingQuantitySystemAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->remainingQuantityQuantity?->system;
    }

    public function getRemainingQuantityCodeAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->remainingQuantityQuantity?->code;
    }

    public function getGoalAttribute(mixed $value): array
    {
        return $this->detailsSyncedAt === null
            ? (is_array($value) ? $value : ($value ? $this->fromJson($value) : []))
            : $this->goalConcepts->map(fn ($concept): string => $concept->coding->first()->code)->all();
    }

    public function getReasonReferenceAttribute(mixed $value): array
    {
        if ($this->detailsSyncedAt === null) {
            return is_array($value) ? $value : ($value ? $this->fromJson($value) : []);
        }

        return $this->reasonReferences->map(static function ($reference): string {
            $type = match ($reference->type->first()?->coding->first()?->code) {
                'condition' => 'Condition',
                'observation' => 'Observation',
                'diagnostic_report' => 'DiagnosticReport',
                default => $reference->type->first()?->coding->first()?->code,
            };

            return $type.'/'.$reference->value;
        })->all();
    }

}

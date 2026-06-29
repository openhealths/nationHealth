<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Quantity;
use App\Models\MedicalEvents\Sql\Period;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CarePlanActivity extends Model
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
    ];

    protected $casts = [
        'do_not_perform' => 'boolean',
        'quantity' => 'integer',
        'daily_amount' => 'decimal:4',
        'reason_reference' => 'array',
        'goal' => 'array',
        'scheduled_period_start' => 'date',
        'scheduled_period_end' => 'date',
    ];

    public function quantityQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'quantity_id');
    }

    public function dailyAmountQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'daily_amount_id');
    }

    public function scheduledPeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
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

        $conceptCode = strtolower(trim((string) ($this->kindConcept?->coding?->first()?->code ?? '')));

        return match ($conceptCode) {
            'servicerequest', 'service_request' => 'service_request',
            'medicationrequest', 'medication_request' => 'medication_request',
            'devicerequest', 'device_request' => 'device_request',
            default => $conceptCode,
        };
    }
}

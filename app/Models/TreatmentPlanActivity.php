<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Employee\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentPlanActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'treatment_plan_id',
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
    ];

    protected $casts = [
        'do_not_perform' => 'boolean',
        'quantity' => 'integer',
        'daily_amount' => 'decimal:4',
        'reason_reference' => 'array',
        'scheduled_period_start' => 'date',
        'scheduled_period_end' => 'date',
    ];

    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }
}

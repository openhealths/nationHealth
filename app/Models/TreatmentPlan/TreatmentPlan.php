<?php

declare(strict_types=1);

namespace App\Models\TreatmentPlan;

use App\Enums\TreatmentPlan\Category;
use App\Enums\TreatmentPlan\Intention;
use App\Enums\TreatmentPlan\Status;
use App\Enums\TreatmentPlan\TermsService;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class TreatmentPlan extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'ehealth_id',
        'patient_id',
        'category',
        'intention',
        'terms_service',
        'name_treatment_plan',
        'period_start',
        'period_end',
        'status',
        'job_id',
        'validation_details',
        'inserted_by',
        'updated_by',
    ];

    protected $casts = [
        'category' => Category::class,
        'intention' => Intention::class,
        'terms_service' => TermsService::class,
        'status' => Status::class,
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'validation_details' => 'array',
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}

<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Value extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'observation_id',
        'observation_component_id',
        'value_quantity_id',
        'value_ratio_id',
        'value_range_id',
        'value_codeable_concept_id',
        'value_sampled_data_id',
        'value_string',
        'value_boolean',
        'value_date_time',
        'value_time'
    ];

    protected $hidden = [
        'id',
        'observation_id',
        'observation_component_id',
        'value_quantity_id',
        'value_ratio_id',
        'value_range_id',
        'value_codeable_concept_id',
        'value_sampled_data_id',
        'created_at',
        'updated_at'
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function observationComponent(): BelongsTo
    {
        return $this->belongsTo(ObservationComponent::class);
    }

    public function valueQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'value_quantity_id');
    }

    public function valueRatio(): BelongsTo
    {
        return $this->belongsTo(Ratio::class, 'value_ratio_id');
    }

    public function valueRange(): BelongsTo
    {
        return $this->belongsTo(Range::class, 'value_range_id');
    }

    public function valueSampledData(): BelongsTo
    {
        return $this->belongsTo(SampledData::class, 'value_sampled_data_id');
    }

    public function valueCodeableConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'value_codeable_concept_id');
    }
}

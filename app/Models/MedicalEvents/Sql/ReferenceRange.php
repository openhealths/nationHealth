<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceRange extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'observation_id',
        'observation_component_id',
        'low_id',
        'high_id',
        'type_id',
        'applies_to_id',
        'age_id',
        'text'
    ];

    protected $hidden = [
        'id',
        'observation_id',
        'observation_component_id',
        'low_id',
        'high_id',
        'type_id',
        'applies_to_id',
        'age_id',
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

    public function low(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'low_id');
    }

    public function high(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'high_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'type_id');
    }

    public function appliesTo(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'applies_to_id');
    }

    public function age(): BelongsTo
    {
        return $this->belongsTo(Range::class, 'age_id');
    }
}

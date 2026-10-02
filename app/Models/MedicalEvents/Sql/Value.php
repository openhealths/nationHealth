<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Value extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'observation_id',
        'observation_component_id',
        'device_property_id',
        'value_quantity_id',
        'value_ratio_id',
        'value_range_id',
        'value_codeable_concept_id',
        'value_sampled_data_id',
        'value_string',
        'value_boolean',
        'value_integer',
        'value_date_time',
        'value_time'
    ];

    protected $hidden = [
        'id',
        'observation_id',
        'observation_component_id',
        'device_property_id',
        'value_quantity_id',
        'value_ratio_id',
        'value_range_id',
        'value_codeable_concept_id',
        'value_sampled_data_id',
        'created_at',
        'updated_at'
    ];

    /**
     * Readable form of the value, whichever of the plain value types it holds.
     * A coded value has no label here, its name comes from the dictionary of its coding system.
     *
     * @return Attribute
     */
    protected function label(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if ($this->valueQuantity) {
                    return collect([
                        $this->valueQuantity->comparator,
                        $this->valueQuantity->value,
                        $this->valueQuantity->unit ?? $this->valueQuantity->code
                    ])->filter(static fn (mixed $part): bool => filled($part))->implode(' ');
                }

                if (filled($this->valueString)) {
                    return $this->valueString;
                }

                if ($this->valueBoolean !== null) {
                    return $this->valueBoolean ? __('forms.yes') : __('forms.no');
                }

                if ($this->valueDateTime) {
                    return CarbonImmutable::parse($this->valueDateTime, 'UTC')
                        ->setTimezone(config('app.timezone'))
                        ->format(config('app.date_format') . ' H:i');
                }

                if ($this->valueTime) {
                    return substr($this->valueTime, 0, 5);
                }

                return null;
            }
        );
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function observationComponent(): BelongsTo
    {
        return $this->belongsTo(ObservationComponent::class);
    }

    public function deviceProperty(): BelongsTo
    {
        return $this->belongsTo(DeviceProperty::class);
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

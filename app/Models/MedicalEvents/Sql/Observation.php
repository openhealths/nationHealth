<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Person\ObservationStatus;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Observation extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'based_on_id',
        'status',
        'diagnostic_report_id',
        'code_id',
        'effective_date_time',
        'issued',
        'primary_source',
        'performer_id',
        'report_origin_id',
        'interpretation_id',
        'comment',
        'body_site_id',
        'method_id',
        'reaction_on_id',
        'context_id',
        'specimen_id',
        'device_id',
        'ehealth_inserted_at',
        'ehealth_updated_at',
        'explanatory_letter'
    ];

    protected $casts = [
        'issued' => EHealthTimestampCast::class,
        'effective_date_time' => EHealthTimestampCast::class,
        'status' => ObservationStatus::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    protected $appends = [
        'issued_date',
        'issued_time',
        'effective_date',
        'effective_time'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'diagnostic_report_id',
        'code_id',
        'effective_date_time',
        'issued',
        'performer_id',
        'report_origin_id',
        'interpretation_id',
        'body_site_id',
        'method_id',
        'reaction_on_id',
        'context_id',
        'specimen_id',
        'device_id',
        'based_on_id',
        'created_at',
        'updated_at'
    ];

    protected function issuedDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->issued ? substr($this->issued, 0, 10) : ''
        );
    }

    protected function issuedTime(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->issued ? substr($this->issued, 11, 5) : ''
        );
    }

    protected function effectiveDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->effectiveDateTime ? substr($this->effectiveDateTime, 0, 10) : ''
        );
    }

    protected function effectiveTime(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->effectiveDateTime ? substr($this->effectiveDateTime, 11, 5) : ''
        );
    }

    public function diagnosticReport(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'diagnostic_report_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'observation_categories')->withTimestamps();
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function effectivePeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function interpretation(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'interpretation_id');
    }

    public function bodySite(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'body_site_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'method_id');
    }

    public function value(): HasOne
    {
        return $this->hasOne(Value::class);
    }

    public function reactionOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'reaction_on_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function specimen(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'specimen_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'device_id');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function referenceRanges(): HasMany
    {
        return $this->hasMany(ReferenceRange::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(ObservationComponent::class);
    }

    /**
     * Scope to eager load all observation relationships.
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'code.coding',
            'categories.coding',
            'diagnosticReport.type.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'interpretation.coding',
            'bodySite.coding',
            'method.coding',
            'value.valueQuantity',
            'value.valueCodeableConcept.coding',
            'value.valueRange.low',
            'value.valueRange.high',
            'value.valueRatio.numerator',
            'value.valueRatio.denominator',
            'value.valueSampledData',
            'effectivePeriod',
            'context.type.coding',
            'specimen.type.coding',
            'device.type.coding',
            'basedOn.type.coding',
            'referenceRanges',
            'components.code.coding',
            'components.interpretation.coding',
            'components.value.valueQuantity',
            'components.value.valueCodeableConcept.coding',
            'components.value.valueRange.low',
            'components.value.valueRange.high',
            'components.value.valueRatio.numerator',
            'components.value.valueRatio.denominator',
            'components.value.valueSampledData',
            'components.referenceRanges'
        ]);
    }
}

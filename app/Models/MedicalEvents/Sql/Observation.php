<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Person\ObservationStatus;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Observation extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'encounter_id',
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
        'value_codeable_concept_id',
        'value_string',
        'value_boolean',
        'value_date_time',
        'reaction_on_id',
        'context_id',
        'specimen_id',
        'device_id',
        'ehealth_inserted_at',
        'ehealth_updated_at',
        'explanatory_letter'
    ];

    protected $casts = [
        'issued' => 'immutable_datetime',
        'effective_date_time' => 'immutable_datetime',
        'status' => ObservationStatus::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'encounter_id',
        'diagnostic_report_id',
        'code_id',
        'effective_date_time',
        'issued',
        'performer_id',
        'report_origin_id',
        'interpretation_id',
        'value_codeable_concept_id',
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

    protected $appends = [
        'issued_date',
        'issued_time',
        'effective_date',
        'effective_time'
    ];

    protected function issuedDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->issued->toDateString()
        );
    }

    protected function issuedTime(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->issued->toTimeString()
        );
    }

    protected function effectiveDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->effective_date_time->toDateString()
        );
    }

    protected function effectiveTime(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->effective_date_time->toTimeString()
        );
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
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

    public function valueQuantity(): MorphOne
    {
        return $this->morphOne(Quantity::class, 'quantifiable');
    }

    public function valueCodeableConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'value_codeable_concept_id');
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

    public function referenceRanges(): MorphMany
    {
        return $this->morphMany(ReferenceRange::class, 'referenceable');
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
            'valueCodeableConcept.coding',
            'context.type.coding',
            'specimen.type.coding',
            'device.type.coding',
            'basedOn.type.coding',
            'referenceRanges',
            'components.code.coding',
            'components.interpretation.coding',
            'components.valueCodeableConcept.coding',
            'components.referenceRanges'
        ]);
    }
}

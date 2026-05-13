<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Procedure extends Model
{
    use HasCamelCasing;

    protected $guarded = [];

    protected $hidden = [
        'id',
        'based_on_id',
        'code_id',
        'recorded_by_id',
        'performer_id',
        'report_origin_id',
        'division_id',
        'managing_organization_id',
        'outcome_id',
        'category_id',
        'encounter_id',
        'created_at',
        'updated_at'
    ];

    protected $appends = [
        'performed_period_start_date',
        'performed_period_start_time',
        'performed_period_end_date',
        'performed_period_end_time'
    ];

    protected function performedPeriodStartDate(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['start'])
                ? CarbonImmutable::parse($this->performedPeriod['start'])->toDateString()
                : null
        );
    }

    protected function performedPeriodStartTime(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['start'])
                ? CarbonImmutable::parse($this->performedPeriod['start'])->toTimeString()
                : null
        );
    }

    protected function performedPeriodEndDate(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['end'])
                ? CarbonImmutable::parse($this->performedPeriod['end'])->toDateString()
                : null
        );
    }

    protected function performedPeriodEndTime(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->performedPeriod['end'])
                ? CarbonImmutable::parse($this->performedPeriod['end'])->toTimeString()
                : null
        );
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'code_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'encounter_id');
    }

    public function performedPeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'recorded_by_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'division_id');
    }

    public function managingOrganization(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'managing_organization_id');
    }

    public function reasonReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'procedure_reason_references');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'outcome_id');
    }

    public function complicationDetails(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'procedure_complication_details');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function paperReferral(): MorphOne
    {
        return $this->morphOne(PaperReferral::class, 'paper_referralable');
    }

    public function usedCodes(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'procedure_used_codes')->withTimestamps();
    }
}

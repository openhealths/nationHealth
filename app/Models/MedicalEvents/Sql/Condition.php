<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthDateCast;
use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\ConditionVerificationStatus;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Condition extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'primary_source',
        'asserter_id',
        'report_origin_id',
        'context_id',
        'code_id',
        'clinical_status',
        'verification_status',
        'severity_id',
        'onset_date',
        'asserted_date',
        'explanatory_letter',
        'ehealth_inserted_at',
        'ehealth_updated_at',
        'stage_summary_id'
    ];

    protected $casts = [
        'clinical_status' => ConditionClinicalStatus::class,
        'verification_status' => ConditionVerificationStatus::class,
        'onset_date' => EHealthDateCast::class,
        'asserted_date' => EHealthDateCast::class,
        'ehealth_inserted_at' => EHealthDateCast::class,
        'ehealth_updated_at' => EHealthDateCast::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'asserter_id',
        'report_origin_id',
        'context_id',
        'code_id',
        'severity_id',
        'stage_summary_id',
        'created_at',
        'updated_at'
    ];

    protected $appends = [
        'evidences',
        'stage'
    ];

    public function asserter(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'asserter_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'severity_id');
    }

    public function bodySites(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'condition_body_sites')->withTimestamps();
    }

    public function stageSummary(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'stage_summary_id');
    }

    public function evidencesRelation(): HasMany
    {
        return $this->hasMany(ConditionEvidence::class, 'condition_id');
    }

    public function stage(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->stageSummary ? [
                'summary' => $this->stageSummary->toArray()
            ] : null
        );
    }

    public function evidences(): Attribute
    {
        return Attribute::make(
            get: fn () => [
                [
                    'codes' => $this->evidencesRelation()
                        ->with(['codes.coding'])
                        ->get()
                        ->filter(fn (ConditionEvidence $evidence) => $evidence->codes !== null)
                        ->map(fn (ConditionEvidence $evidence) => $evidence->codes->toArray())
                        ->toArray(),
                    'details' => $this->evidencesRelation()
                        ->with(['details.type.coding'])
                        ->get()
                        ->filter(fn (ConditionEvidence $evidence) => $evidence->details !== null)
                        ->map(fn (ConditionEvidence $evidence) => $evidence->details->toArray())
                        ->toArray()
                ]
            ]
        );
    }

    /**
     * Scope to eager load all condition relationships.
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'asserter.type.coding',
            'reportOrigin.coding',
            'context.type.coding',
            'code.coding',
            'severity.coding',
            'bodySites.coding',
            'stageSummary.coding',
            'evidencesRelation.codes.coding',
            'evidencesRelation.details.type.coding'
        ]);
    }
}

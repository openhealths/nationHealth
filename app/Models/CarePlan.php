<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Period;
use App\Models\Person\Person;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CarePlan extends Model
{
    use HasFactory, HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'author_id',
        'legal_entity_id',
        'status',
        'category',
        'clinical_protocol',
        'context',
        'title',
        'period_start',
        'period_end',
        'terms_of_service',
        'encounter_id',
        'addresses',
        'description',
        'supporting_info',
        'note',
        'inform_with',
        'requisition',
        'category_id',
        'encounter_identifier_id',
        'care_manager_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'addresses' => 'array',
        'supporting_info' => 'array',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CarePlanActivity::class);
    }

    public function categoryConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function encounterIdentifier(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'encounter_identifier_id');
    }

    public function careManager(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'care_manager_id');
    }

    public function supportingInfoReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'care_plan_supporting_info');
    }

    public function effectivePeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function getStatusDisplayAttribute(): string
    {
        // Simple translation check, fallback to english or original
        $statusStr = strtolower($this->status ?? 'new');
        $translated = __('care-plan.status.' . $statusStr);
        return $translated === 'care-plan.status.' . $statusStr ? ucfirst($statusStr) : $translated;
    }

    public function getEhealthIdAttribute(): ?string
    {
        return $this->uuid;
    }

    public function getEpisodeIdAttribute(): ?string
    {
        if (is_array($this->supporting_info) && isset($this->supporting_info['episodes']) && !empty($this->supporting_info['episodes'])) {
            return $this->supporting_info['episodes'][0]['name'] ?? null;
        }
        return null;
    }

    public function getNotesAttribute(): ?string
    {
        return $this->note;
    }

    public function getExtendedDescriptionAttribute(): ?string
    {
        return $this->description;
    }

    public function getAdditionalInfoAttribute(): ?string
    {
        return $this->context;
    }

    public function getCareProvisionConditionsAttribute(): ?string
    {
        return collect(config('ehealth.dictionaries.care_provision_condition') ?? [])->get($this->terms_of_service, $this->terms_of_service);
    }

    public function getMedicalConditionAttribute(): ?string
    {
        if ($this->relationLoaded('encounter') && $this->encounter) {
            if ($this->encounter->relationLoaded('diagnoses') && $this->encounter->diagnoses && $this->encounter->diagnoses->isNotEmpty()) {
                $diagnosis = $this->encounter->diagnoses->first();
                if ($diagnosis->relationLoaded('condition') && $diagnosis->condition) {
                    $condition = $diagnosis->condition;
                    return $condition->code . ' - ' . $condition->code_display;
                }
            }
        }
        return null;
    }
}

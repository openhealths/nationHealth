<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\SubmittedToEHealth;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Approval;
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
use Illuminate\Support\Str;
use App\Repositories\Repository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;

class CarePlan extends Model implements SubmittedToEHealth
{
    use HasFactory;
    use HasCamelCasing;
    public const string RESOURCE_TYPE = 'care_plan';

    protected $fillable = [
        'uuid',
        'person_id',
        'author_id',
        'legal_entity_id',
        'status',
        'category',
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
        'author_reference_id',
        'managing_organization_id',
        'terms_of_service_concept_id',
        'intent',
        'status_reason_id',
        'subject_reference_id',
        'based_on_id',
        'part_of_id',

        'details_synced_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'addresses' => 'array',
        'supporting_info' => 'array',

        'details_synced_at' => 'datetime',
    ];

    public function displayTitle(): string
    {
        $title = trim((string) $this->title);
        if ($title !== '' && !Str::isUuid($title)) {
            return $title;
        }

        return filled($this->requisition)
            ? __('care-plan.care_plan_with_number', ['number' => $this->requisition])
            : __('care-plan.care_plan');
    }

    public function requiresFhirImport(): bool
    {
        // Local conversion marker: legacy rows need a detail import before they can be edited or signed.
        return $this->detailsSyncedAt === null
            || ($this->relationLoaded('activities') && $this->activities->contains(
                static fn (CarePlanActivity $activity): bool => $activity->detailsSyncedAt === null
            ));
    }

    public function subjectReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'subject_reference_id');
    }

    public function statusReasonConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_id');
    }

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

    public function episodeUuid(): ?string
    {
        return Repository::carePlan()->episodeUuid($this);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CarePlanActivity::class);
    }

    public function categoryConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function authorReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'author_reference_id');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function partOf(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'part_of_id');
    }

    public function managingOrganization(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'managing_organization_id');
    }

    public function termsOfServiceConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'terms_of_service_concept_id');
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'care_plan_contributors')->withPivot('position')->orderByPivot('position');
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

    public function hasGrantedApprovalForEmployeeUuid(?string $employeeUuid): bool
    {
        return Repository::carePlan()->hasGrantedApproval($this, $employeeUuid);
    }

    public function termsOfServiceCode(): ?string
    {
        $code = $this->termsOfServiceConcept?->coding?->first()?->code;
        $legacy = $this->termsOfService;

        return $code ?? (is_array($legacy) ? data_get($legacy, 'coding.0.code') : $legacy);
    }

    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
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
        foreach ($this->supportingInfo['episodes'] ?? [] as $episode) {
            return $episode['uuid'] ?? $episode['id'] ?? null;
        }

        return $this->episodeUuid();
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
        return match ($this->termsOfService) {
            'OUTPATIENT' => __('care-plan.terms_outpatient'),
            'INPATIENT' => __('care-plan.terms_inpatient'),
            default => $this->termsOfService,
        };
    }

    public function getMedicalConditionAttribute(): ?string
    {
        if (!$this->relationLoaded('encounter') || $this->encounter === null) {
            return '—';
        }
        $condition = MedicalEventsRepository::encounter()
            ->primaryDiagnosisConditions($this->encounter)->first();
        $concept = $condition?->code;
        $coding = $concept?->coding->first();

        return trim(($coding?->code ?? '').' - '.($coding?->display ?? $concept?->text ?? ''), ' -') ?: '—';
    }

    public function getAuthorNameAttribute(): string
    {
        if ($this->relationLoaded('author')) {
            return $this->author?->party?->fullName ?? '—';
        }

        return '—';
    }
}

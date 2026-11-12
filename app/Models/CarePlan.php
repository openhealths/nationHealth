<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\SubmittedToEHealth;
use App\Enums\CarePlanTermsOfService;
use App\Models\CarePlanActivity;
use App\Models\EhealthLink;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Period;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

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
    public function skipsPatientOtp(?LegalEntity $legalEntity = null): bool
    {
        $legalEntity ??= legalEntity();
        if ($legalEntity === null) {
            return false;
        }

        $terms = $this->termsOfServiceCode();
        if (is_array($terms)) {
            $terms = $terms['coding'][0]['code'] ?? ($terms['code'] ?? '');
        }

        if (strtoupper(trim((string) $terms)) !== CarePlanTermsOfService::INPATIENT->value) {
            return false;
        }

        $managingOrganizationUuid = $this->managingOrganization?->value;
        if (filled($managingOrganizationUuid)) {
            return $managingOrganizationUuid === $legalEntity->uuid;
        }

        $planLegalEntityId = $this->legalEntityId;
        if ($planLegalEntityId === null || $planLegalEntityId === '') {
            return false;
        }

        return (int) $planLegalEntityId === (int) $legalEntity->id;
    }

    public function resolveAccessLevel(?LegalEntity $legalEntity = null): string
    {
        $legalEntity ??= legalEntity();

        $managingOrganizationUuid = $this->managingOrganization?->value;
        $isManagingOrganization = filled($managingOrganizationUuid)
            ? $managingOrganizationUuid === $legalEntity?->uuid
            : (int) $this->legalEntityId === (int) $legalEntity?->id;

        return $isManagingOrganization ? 'write' : 'read';
    }

    protected const string REHABILITATION_SPECIALITY = 'PHYSICAL_MEDICINE_AND_REHABILITATION';

    public const REHAB_CATEGORIES = [
        'CLASS 23',
        'CLASS 24',
        'CLASS 25',
        'CLASS_23',
        'CLASS_24',
        'CLASS_25',
        'CLASS23',
        'CLASS24',
        'CLASS25',
    ];
    public function allowsServiceMinutes(): bool
    {
        if (!$this->isRehabCategory() || $this->termsOfServiceCode() !== 'OUTPATIENT') {
            return false;
        }
        $repository = Repository::carePlan();
        $resource = $repository->getResource($this);
        $encounterUuid = data_get($resource, 'encounter.identifier.value');
        if (!is_string($encounterUuid) || $encounterUuid === '') {
            return false;
        }
        $encounter = $repository->findEncounter($encounterUuid, (int) $this->personId);
        $author = $this->author;
        // Unit eligibility follows the original plan/encounter author, even when another
        // eligible physician now creates an activity under their own write approval.
        if ($author === null || (int) $author->legalEntityId !== (int) $this->legalEntityId
            || data_get($resource, 'author.identifier.value') !== $author->uuid
            || $encounter?->performer?->value !== $author->uuid) {
            return false;
        }

        return $author->specialities()->whereSpeciality(self::REHABILITATION_SPECIALITY)
            ->whereSpecialityOfficio(true)
            ->where(static fn ($query) => $query->whereNull('valid_to_date')->orWhereDate('valid_to_date', '>=', today()))
            ->exists();
    }

    public function providingConditionsBlockReason(?array $program): ?string
    {
        if ($program === null || $program === []) {
            return null;
        }

        $allowed = $this->normalizeAllowedList(
            data_get($program, 'medical_program_settings.providing_conditions_allowed')
                ?? (data_get($program, 'medical_program_settings.PROVIDING_CONDITIONS_ALLOWED')
                    ?? data_get($program, 'providing_conditions_allowed'))
        );

        if ($allowed === []) {
            return null;
        }

        $termsOfService = $this->resolveTermsOfService();
        if ($termsOfService === null || $termsOfService === '') {
            return __('care-plan.providing_conditions_mismatch', [
                'allowed' => implode(', ', $allowed),
                'current' => '—',
            ]);
        }

        $normalizedTerms = strtoupper($termsOfService);
        $normalizedAllowed = array_map('strtoupper', $allowed);

        if (!in_array($normalizedTerms, $normalizedAllowed, true)) {
            return __('care-plan.providing_conditions_mismatch', [
                'allowed' => implode(', ', $allowed),
                'current' => $termsOfService,
            ]);
        }

        return null;
    }

    public function rehabReasonReferenceBlockReason(array $reasonReferences): ?string
    {
        if (!$this->isRehabCategory()) {
            return null;
        }

        if (Repository::carePlan()->hasRehabilitationObservation($this, $reasonReferences)) {
            return null;
        }

        return __('care-plan.rehab_reason_reference_required');
    }

    public function isRehabCategory(): bool
    {
        $category = $this->resolveCategoryCode();
        if ($category === null || $category === '') {
            return false;
        }

        $normalized = $this->normalizeCategoryCode($category);

        foreach (self::REHAB_CATEGORIES as $rehab) {
            if ($normalized === $this->normalizeCategoryCode($rehab)) {
                return true;
            }
        }

        return false;
    }

    public function resolveCategoryCode(): ?string
    {
        $category = $this->category;
        if (is_array($category)) {
            $category = $category['coding'][0]['code'] ?? ($category['text'] ?? null);
        }

        if (is_string($category) && trim($category) !== '') {
            return trim($category);
        }

        $conceptCode = $this->categoryConcept?->coding?->first()?->code;
        if (is_string($conceptCode) && trim($conceptCode) !== '') {
            return trim($conceptCode);
        }

        return null;
    }

    public function resolveTermsOfService(): ?string
    {
        $tos = $this->termsOfService;

        if (is_array($tos)) {
            $tos = $tos['coding'][0]['code'] ?? ($tos['text'] ?? null);
        }

        if (!is_string($tos) || trim($tos) === '') {
            return null;
        }

        return trim($tos);
    }

    private function normalizeAllowedList(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $code = $item['code'] ?? ($item['coding'][0]['code'] ?? null);
                if (is_string($code) && trim($code) !== '') {
                    $out[] = trim($code);
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeCategoryCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s_\-]+/', '', $code) ?? $code);
    }
    public function getCategoryAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->categoryConcept?->coding->first()?->code;
    }

    public function getTermsOfServiceAttribute(mixed $value): mixed
    {
        return $this->detailsSyncedAt === null ? $value : $this->termsOfServiceConcept?->coding->first()?->code;
    }

    public function getPeriodStartAttribute(?string $value): ?CarbonImmutable
    {
        $date = $this->detailsSyncedAt === null ? $value : $this->effectivePeriod?->start;

        return filled($date) ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    public function getPeriodEndAttribute(?string $value): ?CarbonImmutable
    {
        $date = $this->detailsSyncedAt === null ? $value : $this->effectivePeriod?->end;

        return filled($date) ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    public function getSupportingInfoAttribute(mixed $value): array
    {
        if ($this->detailsSyncedAt === null) {
            return is_array($value) ? $value : ($value ? $this->fromJson($value) : []);
        }
        $this->loadMissing('supportingInfoReferences.type.coding');
        $references = $this->supportingInfoReferences->map(
            fn ($reference): array => MedicalEventsRepository::identifier()->toReference($reference)
        );

        return [
            'episodes' => $references->filter(fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care')
                ->map(fn (array $reference): array => ['uuid' => $reference['identifier']['value']])->values()->all(),
            'medical_records' => $references->reject(fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care')->values()->all(),
        ];
    }

}

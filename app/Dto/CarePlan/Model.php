<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Dto\CodeableConcept\Model as CodeableConceptData;
use App\Dto\Identifier\Model as IdentifierData;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlan;
use App\Models\CarePlanRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * A local form maps to CarePlanRequest attributes. Validated details map to CarePlan
 * attributes and related DTOs. Relations are saved by the repository, as in HealthcareService.
 */
#[Map(source: CarePlanForm::class)]
#[Map(source: CarePlanDetails::class)]
#[Map(target: CarePlanRequest::class)]
#[Map(target: CarePlan::class)]
class Model
{
    #[Map(source: '[id]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $uuid = null;

    #[Map(source: 'title', if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[title]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $title;

    #[Map(source: '[status]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $status;

    #[Map(source: '[intent]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $intent;

    #[Map(source: 'category', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $category;

    #[Map(source: '[category]', if: new SourceClass(CarePlanDetails::class), transform: [CodeableConceptData::class, 'map'])]
    public CodeableConceptData $categoryConcept;

    #[Map(source: 'termsOfService', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $termsOfService;

    #[Map(source: '[termsOfService]', if: new SourceClass(CarePlanDetails::class), transform: [CodeableConceptData::class, 'map'])]
    public CodeableConceptData $termsOfServiceConcept;

    #[Map(source: '[statusReason?]', if: new SourceClass(CarePlanDetails::class), transform: [CodeableConceptData::class, 'map'])]
    public ?CodeableConceptData $statusReasonConcept = null;

    #[Map(source: '[period]', if: new SourceClass(CarePlanDetails::class))]
    public array $period;

    #[Map(source: 'periodStart', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodStart;

    #[Map(source: 'periodEnd', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodEnd = '';

    #[Map(source: 'periodStartTime', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodStartTime;

    #[Map(source: 'periodEndTime', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodEndTime;

    #[Map(source: 'encounter', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $encounter;

    #[Map(source: '[encounter][identifier]', if: new SourceClass(CarePlanDetails::class), transform: [IdentifierData::class, 'map'])]
    public IdentifierData $encounterIdentifier;

    #[Map(source: '[author][identifier]', if: new SourceClass(CarePlanDetails::class), transform: [IdentifierData::class, 'map'])]
    public IdentifierData $authorReference;

    #[Map(source: '[subject][identifier]', if: new SourceClass(CarePlanDetails::class), transform: [IdentifierData::class, 'map'])]
    public IdentifierData $subjectReference;

    #[Map(source: '[managingOrganization][identifier]', if: new SourceClass(CarePlanDetails::class), transform: [IdentifierData::class, 'map'])]
    public IdentifierData $managingOrganization;

    #[Map(source: '[basedOn?][identifier?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'optionalIdentifier'])]
    public ?IdentifierData $basedOn = null;

    #[Map(source: '[partOf?][identifier?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'optionalIdentifier'])]
    public ?IdentifierData $partOf = null;

    #[Map(source: '[careManager?][identifier?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'optionalIdentifier'])]
    public ?IdentifierData $careManager = null;

    #[Map(source: '[contributor?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'identifiers'])]
    public array $contributors = [];

    #[Map(source: '[supportingInfo?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'identifiers'])]
    public array $supportingInfoReferences = [];

    #[Map(source: '[addresses]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public array $addresses = [];

    #[Map(source: 'context', if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[context?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $context = '';

    #[Map(source: 'description', if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[description?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $description = '';

    #[Map(source: 'note', if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[note?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $note = '';

    #[Map(source: 'informWith', if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[informWith?][authMethodId?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $informWith = '';

    #[Map(source: 'coAuthors', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $coAuthors = [];

    #[Map(source: 'episodes', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $episodes = [];

    #[Map(source: 'medicalRecords', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $medicalRecords = [];

    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public ?string $conditionUuid = null;

    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public ?string $authorUuid = null;

    #[Map(if: false)]
    public ?int $version = null;

    #[Map(source: '[requisition]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $requisition;

    #[Map(source: '[statusHistory]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public array $statusHistory;

    #[Map(source: '[insertedAt]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthInsertedAt;

    #[Map(source: '[updatedAt]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthUpdatedAt;

    #[Map(source: '[insertedBy]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthInsertedBy;

    #[Map(source: '[updatedBy]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthUpdatedBy;

    public function optionalIdentifier(?array $identifier): ?IdentifierData
    {
        return $identifier === null ? null : IdentifierData::map($identifier);
    }

    public function identifiers(?array $references): array
    {
        return array_map(fn (array $reference): IdentifierData => IdentifierData::map($reference['identifier']), $references ?? []);
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Core\Arr;
use App\Dto\CodeableConcept\Model as CodeableConceptData;
use App\Dto\Identifier\Model as IdentifierData;
use App\Models\CarePlan;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

#[Map(source: CarePlanDetails::class, target: CarePlan::class)]
class Model
{
    #[Map(source: '[id]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $uuid;

    #[Map(source: '[title]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $title;

    #[Map(source: '[status]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $status;

    #[Map(source: '[intent]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $intent;

    #[Map(source: '[category]', if: new SourceClass(CarePlanDetails::class), transform: [CodeableConceptData::class, 'map'])]
    public CodeableConceptData $categoryConcept;

    #[Map(source: '[terms_of_service]', if: new SourceClass(CarePlanDetails::class), transform: [CodeableConceptData::class, 'map'])]
    public CodeableConceptData $termsOfServiceConcept;

    #[Map(source: '[status_reason?]', if: new SourceClass(CarePlanDetails::class), transform: [CodeableConceptData::class, 'map'])]
    public ?CodeableConceptData $statusReasonConcept = null;

    #[Map(source: '[period]', if: new SourceClass(CarePlanDetails::class))]
    public array $period;

    #[Map(source: '[encounter]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'reference'])]
    public Reference $encounterIdentifier;

    #[Map(source: '[author]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'reference'])]
    public Reference $authorReference;

    #[Map(source: '[subject]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'reference'])]
    public Reference $subjectReference;

    #[Map(source: '[managing_organization]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'reference'])]
    public Reference $managingOrganization;

    #[Map(source: '[based_on?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'reference'])]
    public ?Reference $basedOn = null;

    #[Map(source: '[part_of?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'reference'])]
    public ?Reference $partOf = null;

    #[Map(source: '[care_manager?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'reference'])]
    public ?Reference $careManager = null;

    /** @var list<Reference> */
    #[Map(source: '[contributor?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'references'])]
    public array $contributors = [];

    /** @var list<Reference> */
    #[Map(source: '[supporting_info?]', if: new SourceClass(CarePlanDetails::class), transform: [new self(), 'references'])]
    public array $supportingInfoReferences = [];

    #[Map(source: '[addresses]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public array $addresses;

    #[Map(source: '[context?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $context = null;

    #[Map(source: '[description?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $description = null;

    #[Map(source: '[note?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $note = null;

    #[Map(source: '[inform_with?][auth_method_id?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $informWith = null;

    #[Map(source: '[requisition]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $requisition;

    #[Map(source: '[status_history]', if: new SourceClass(CarePlanDetails::class), transform: [Arr::class, 'toCamelCase'])]
    #[Map(if: new TargetClass(CarePlan::class))]
    public array $statusHistory;

    #[Map(source: '[inserted_at]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthInsertedAt;

    #[Map(source: '[updated_at]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthUpdatedAt;

    #[Map(source: '[inserted_by]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthInsertedBy;

    #[Map(source: '[updated_by]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $ehealthUpdatedBy;

    public function reference(?array $reference): ?Reference
    {
        return $reference === null
            ? null
            : new Reference(IdentifierData::map($reference['identifier']), $reference['display_value'] ?? null);
    }

    /** @return list<Reference> */
    public function references(?array $references): array
    {
        return array_map($this->reference(...), $references ?? []);
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanSummary;
use App\Dto\CodeableConcept\Model as CodeableConceptModel;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\IsNotNull;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/**
 * Validated care plan projected to stored attributes and clinical relations.
 * Summary sync uses only the supplied list fields and never replaces full clinical data.
 * Explicit outbound mappings are required for Eloquent's dynamic attributes.
 */
class Model
{
    #[Map(source: '[id]')]
    public string $uuid;

    #[Map(source: '[status]')]
    public string $status;

    #[Map(source: '[title?]')]
    public ?string $title = null;

    #[Map(source: '[requisition?]')]
    public ?string $requisition = null;

    #[Map(source: '[intent?]')]
    public ?string $intent = null;

    #[Map(source: '[context?]')]
    public ?string $context = null;

    #[Map(source: '[description?]')]
    public ?string $description = null;

    #[Map(source: '[note?]')]
    public ?string $note = null;

    #[Map(source: '[inform_with?][auth_method_id?]')]
    public ?string $informWith = null;

    #[Map(source: '[author?][identifier?][value?]', if: new SourceClass(CarePlanSummary::class))]
    public ?string $authorUuid = null;

    #[Map(source: '[encounter?][identifier?][value?]', if: new SourceClass(CarePlanSummary::class))]
    public ?string $encounterUuid = null;

    #[Map(source: '[subject?][identifier?][value?]', if: new SourceClass(CarePlanSummary::class))]
    public ?string $patientUuid = null;

    #[Map(source: '[category?]', if: new SourceClass(CarePlanSummary::class), transform: [CodeableConceptModel::class, 'map'])]
    public ?CodeableConceptModel $category = null;

    #[Map(source: '[terms_of_service?]', if: new SourceClass(CarePlanSummary::class), transform: [CodeableConceptModel::class, 'map'])]
    public ?CodeableConceptModel $termsOfService = null;

    #[Map(source: '[status_reason?]', if: new SourceClass(CarePlanSummary::class), transform: [CodeableConceptModel::class, 'map'])]
    public ?CodeableConceptModel $statusReason = null;

    /** @var list<CodeableConceptModel> */
    #[Map(source: '[addresses]', if: new SourceClass(CarePlanDetails::class), transform: new MapCollection(targetClass: CodeableConceptModel::class))]
    public array $addresses = [];

    #[Map(source: '[period?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $period = null;

    #[Map(source: '[author?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $author = null;

    #[Map(source: '[based_on?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $basedOn = null;

    #[Map(source: '[part_of?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $partOf = null;

    #[Map(source: '[encounter?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $encounter = null;

    #[Map(source: '[care_manager?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $careManager = null;

    #[Map(source: '[managing_organization?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $managingOrganization = null;

    #[Map(source: '[subject?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $subject = null;

    #[Map(source: '[supporting_info?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $supportingInfo = null;

    #[Map(source: '[contributor?]', if: new SourceClass(CarePlanSummary::class))]
    public ?array $contributor = null;

    #[Map(source: '[status_history?]', if: new IsNotNull())]
    public array $statusHistory;

    #[Map(source: '[inserted_at?]', if: new IsNotNull())]
    public string $ehealthInsertedAt;

    #[Map(source: '[inserted_by?]', if: new IsNotNull())]
    public string $ehealthInsertedBy;

    #[Map(source: '[updated_at?]', if: new IsNotNull())]
    public string $ehealthUpdatedAt;

    #[Map(source: '[updated_by?]', if: new IsNotNull())]
    public string $ehealthUpdatedBy;
}

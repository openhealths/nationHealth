<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use App\Dto\EhealthMapping;
use App\Dto\Input\FormCollection;
use App\Dto\Transform\Ehealth\CarePlanPeriod;
use App\Dto\Transform\Ehealth\CarePlanSupportingInfo;
use App\Dto\Transform\Ehealth\Concept;
use App\Dto\Transform\Ehealth\Reference;
use App\Dto\Transform\Ehealth\References;
use App\Enums\ResourceType;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

/**
 * Care Plan create content to sign, projected from editor fields or a stored full resource.
 * FormCollection wraps the authorized stored request fields before the same attribute mapping.
 */
class Ehealth
{
    use EhealthMapping {
        toArray as protected mappedContent;
    }

    #[Map(source: '[id]', if: new SourceClass(CarePlanDetails::class))]
    public string $id;

    #[Map(source: '[title]', if: new SourceClass([FormCollection::class, CarePlanDetails::class]))]
    #[Map(source: 'title', if: new SourceClass(CarePlanForm::class))]
    public string $title = '';

    #[Map(source: '[description?]', if: new SourceClass([FormCollection::class, CarePlanDetails::class]))]
    #[Map(source: 'description', if: new SourceClass(CarePlanForm::class))]
    public ?string $description = null;

    #[Map(source: '[note?]', if: new SourceClass([FormCollection::class, CarePlanDetails::class]))]
    #[Map(source: 'note', if: new SourceClass(CarePlanForm::class))]
    public ?string $note = null;

    #[Map(source: '[category]', if: new SourceClass([FormCollection::class, CarePlanDetails::class]), transform: new Concept('eHealth/care_plan_categories'))]
    #[Map(source: 'category', if: new SourceClass(CarePlanForm::class), transform: new Concept('eHealth/care_plan_categories'))]
    public ?CodeableConceptEhealth $category = null;

    #[Map(source: '[terms_of_service]', if: new SourceClass(CarePlanDetails::class), transform: new Concept('PROVIDING_CONDITION'))]
    #[Map(source: '[termsOfService]', if: new SourceClass(FormCollection::class), transform: new Concept('PROVIDING_CONDITION'))]
    #[Map(source: 'termsOfService', if: new SourceClass(CarePlanForm::class), transform: new Concept('PROVIDING_CONDITION'))]
    public ?CodeableConceptEhealth $termsOfService = null;

    #[Map(source: '[author?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: '[authorUuid]', if: new SourceClass(FormCollection::class), transform: new Reference(ResourceType::EMPLOYEE))]
    public ?array $author = null;

    #[Map(source: '[encounter?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: '[encounter]', if: new SourceClass(FormCollection::class), transform: new Reference(ResourceType::ENCOUNTER))]
    #[Map(source: 'encounter', if: new SourceClass(CarePlanForm::class), transform: new Reference(ResourceType::ENCOUNTER))]
    public ?array $encounter = null;

    #[Map(source: '[supporting_info?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: '[episodes]', if: new SourceClass(FormCollection::class), transform: new CarePlanSupportingInfo())]
    #[Map(source: 'episodes', if: new SourceClass(CarePlanForm::class), transform: new CarePlanSupportingInfo())]
    public ?array $supportingInfo = null;

    #[Map(source: '[contributor?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: '[coAuthors]', if: new SourceClass(FormCollection::class), transform: new References(ResourceType::EMPLOYEE))]
    #[Map(source: 'coAuthors', if: new SourceClass(CarePlanForm::class), transform: new References(ResourceType::EMPLOYEE))]
    public ?array $contributor = null;

    #[Map(source: '[period?]', if: new SourceClass(CarePlanDetails::class))]
    #[Map(source: '[periodStart]', if: new SourceClass(FormCollection::class), transform: new CarePlanPeriod())]
    #[Map(source: 'periodStart', if: new SourceClass(CarePlanForm::class), transform: new CarePlanPeriod())]
    public array $period = [];

    #[Map(source: '[addresses?]', if: new SourceClass(FormCollection::class))]
    public array $addresses = [];

    #[Map(source: '[inform_with?]', if: new SourceClass(CarePlanDetails::class))]
    public ?array $informWith = null;

    /** The create contract always signs order intent and new status. */
    public function toArray(): array
    {
        return $this->mappedContent() + ['intent' => 'order', 'status' => 'new'];
    }
}


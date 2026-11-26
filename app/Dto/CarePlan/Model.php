<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\CodeableConcept\Model as CodeableConceptData;
use App\Dto\Identifier\Model as IdentifierData;
use App\Enums\CarePlanStatus;
use App\Enums\ResourceType;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlan;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Map the validated form to the attributes and relations of a local CarePlan draft.
 *
 * Persisted attributes need explicit target mappings because Eloquent exposes them
 * through attribute access instead of declared properties. The remaining values
 * are used to build the related concepts, identifiers and period.
 */
#[Map(source: CarePlanForm::class, target: CarePlan::class)]
class Model
{
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $status = CarePlanStatus::DRAFT->value;

    #[Map(if: new TargetClass(CarePlan::class))]
    public string $intent = 'order';

    #[Map(if: new TargetClass(CarePlan::class))]
    public string $title = '';

    public string $category = '';

    public string $termsOfService = '';

    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $context = null;

    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $description = null;

    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $note = null;

    #[Map(source: 'localPeriodStart', if: new SourceClass(CarePlanForm::class), transform: 'convertToEHealthISO8601')]
    public string $periodStart;

    #[Map(source: 'localPeriodEnd', if: new SourceClass(CarePlanForm::class), transform: 'convertToEHealthISO8601')]
    public string $periodEnd = '';

    public string $encounter = '';

    /** @var list<array<string, mixed>> */
    #[Map(if: new TargetClass(CarePlan::class))]
    public array $addresses = [];

    public CodeableConceptData $categoryConcept;
    public CodeableConceptData $termsOfServiceConcept;
    public IdentifierData $authorReference;
    public IdentifierData $subjectReference;
    public IdentifierData $encounterIdentifier;
    public IdentifierData $managingOrganization;

    /** @var Collection<int, IdentifierData> */
    public Collection $supportingInfoReferences;

    /** @var array<string, string> */
    public array $medicalRecordLabels;

    /** @var Collection<int, IdentifierData> */
    public Collection $contributors;

    /**
     * Map validated local selections into the relations stored with the draft.
     *
     * @param list<string> $coAuthors
     * @param list<string> $episodeUuids
     * @param array<string, string> $medicalRecordLabels
     */
    public function prepareLocal(
        string $authorUuid,
        string $patientUuid,
        string $legalEntityUuid,
        array $coAuthors,
        array $episodeUuids,
        array $medicalRecordLabels,
    ): void {
        $this->categoryConcept = CodeableConceptData::map([
            'coding' => [['system' => 'eHealth/care_plan_categories', 'code' => $this->category]],
        ]);
        $this->termsOfServiceConcept = CodeableConceptData::map([
            'coding' => [['system' => 'PROVIDING_CONDITION', 'code' => $this->termsOfService]],
        ]);
        $this->authorReference = $this->identifier($authorUuid, ResourceType::EMPLOYEE->value);
        $this->subjectReference = $this->identifier($patientUuid, 'patient');
        $this->encounterIdentifier = $this->identifier($this->encounter, 'encounter');
        $this->managingOrganization = $this->identifier($legalEntityUuid, ResourceType::LEGAL_ENTITY->value);
        $this->medicalRecordLabels = $medicalRecordLabels;
        $this->contributors = collect($coAuthors)->map(
            fn (string $uuid): IdentifierData => $this->identifier($uuid, ResourceType::EMPLOYEE->value)
        );
        $this->supportingInfoReferences = collect($episodeUuids)->map(
            fn (string $uuid): IdentifierData => $this->identifier($uuid, 'episode_of_care')
        )->concat(collect(array_keys($medicalRecordLabels))->map(function (string $key): IdentifierData {
            [$type, $uuid] = explode(':', $key, 2);

            return $this->identifier($uuid, $type);
        }));
        foreach (['context', 'description', 'note'] as $field) {
            $this->{$field} = $this->{$field} === '' ? null : $this->{$field};
        }
    }

    protected function identifier(string $uuid, string $type): IdentifierData
    {
        return IdentifierData::map([
            'value' => $uuid,
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]],
        ]);
    }
}

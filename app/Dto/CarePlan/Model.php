<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\CodeableConcept\Model as CodeableConceptData;
use App\Dto\Identifier\Model as IdentifierData;
use App\Dto\Transform\Model\Reference;
use App\Enums\CarePlanStatus;
use App\Enums\ResourceType;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlan;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Map the validated draft form to CarePlan attributes and relations.
 *
 * Persisted attributes need explicit target mappings because Eloquent exposes them
 * through attribute access instead of declared properties. The concepts, identifiers
 * and period are built from the form while mapping, so no value is assigned afterwards.
 */
#[Map(source: CarePlanForm::class)]
#[Map(target: CarePlan::class)]
class Model
{
    #[Map(source: 'uuid', if: new SourceClass(CarePlanForm::class))]
    public string $uuid;

    #[Map(if: new TargetClass(CarePlan::class))]
    public string $status = CarePlanStatus::DRAFT->value;

    #[Map(source: 'intent', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $intent = 'order';

    #[Map(source: 'title', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public string $title = '';

    public string $category = '';

    public string $termsOfService = '';

    #[Map(source: 'context', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $context = null;

    #[Map(source: 'description', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $description = null;

    #[Map(source: 'note', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public ?string $note = null;

    #[Map(source: 'periodStart', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'periodStart'])]
    public string $periodStart;

    #[Map(source: 'periodEnd', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'periodEnd'])]
    public ?string $periodEnd = null;

    public string $encounter = '';

    /** @var list<array<string, mixed>> */
    #[Map(source: 'addresses', if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new TargetClass(CarePlan::class))]
    public array $addresses = [];

    #[Map(source: 'category', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'category'])]
    public CodeableConceptData $categoryConcept;

    #[Map(source: 'termsOfService', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'termsOfService'])]
    public CodeableConceptData $termsOfServiceConcept;

    #[Map(source: 'authorUuid', if: new SourceClass(CarePlanForm::class), transform: new Reference(ResourceType::EMPLOYEE))]
    public IdentifierData $authorReference;

    #[Map(source: 'patientUuid', if: new SourceClass(CarePlanForm::class), transform: new Reference(ResourceType::PATIENT))]
    public IdentifierData $subjectReference;

    #[Map(source: 'encounter', if: new SourceClass(CarePlanForm::class), transform: new Reference(ResourceType::ENCOUNTER))]
    public IdentifierData $encounterIdentifier;

    #[Map(source: 'legalEntityUuid', if: new SourceClass(CarePlanForm::class), transform: new Reference(ResourceType::LEGAL_ENTITY))]
    public IdentifierData $managingOrganization;

    /** @var Collection<int, IdentifierData> */
    #[Map(source: 'episodes', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'supportingInfo'])]
    public Collection $supportingInfoReferences;

    /** @var array<string, string> */
    #[Map(source: 'medicalRecordLabels', if: new SourceClass(CarePlanForm::class))]
    public array $medicalRecordLabels = [];

    /** @var Collection<int, IdentifierData> */
    #[Map(source: 'coAuthors', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'contributors'])]
    public Collection $contributors;

    /**
     * Care plan category code from the form as a codeable concept of the care plan categories dictionary.
     *
     * @param  string  $category
     * @return CodeableConceptData
     */
    public static function category(string $category): CodeableConceptData
    {
        return CodeableConceptData::map(['coding' => [['system' => 'eHealth/care_plan_categories', 'code' => $category]]]);
    }

    /**
     * Terms of service code from the form as a codeable concept of the providing condition dictionary.
     *
     * @param  string  $termsOfService
     * @return CodeableConceptData
     */
    public static function termsOfService(string $termsOfService): CodeableConceptData
    {
        return CodeableConceptData::map(['coding' => [['system' => 'PROVIDING_CONDITION', 'code' => $termsOfService]]]);
    }

    /**
     * Start date and time from the form as an ISO 8601 date-time in UTC; without a time the day starts at midnight.
     *
     * @param  string  $periodStart
     * @param  CarePlanForm  $form
     * @return string
     */
    public static function periodStart(string $periodStart, CarePlanForm $form): string
    {
        return convertToEHealthISO8601($periodStart.' '.($form->periodStartTime ?: '00:00'));
    }

    /**
     * End date and time from the form as an ISO 8601 date-time in UTC; without a time the period lasts to the end of the day.
     *
     * @param  string  $periodEnd
     * @param  CarePlanForm  $form
     * @return string|null
     */
    public static function periodEnd(string $periodEnd, CarePlanForm $form): ?string
    {
        return $periodEnd === '' ? null : convertToEHealthISO8601($periodEnd.' '.($form->periodEndTime ?: '23:59:59'));
    }

    /**
     * Co-author UUIDs from the form as identifiers of employees, skipping the empty and repeated ones.
     * The form is passed along with the mapped value, since a transform receives the source too.
     *
     * @param  array  $coAuthors
     * @param  CarePlanForm  $form
     * @return Collection<int, IdentifierData>
     */
    public static function contributors(array $coAuthors, CarePlanForm $form): Collection
    {
        $reference = new Reference(ResourceType::EMPLOYEE);

        return collect($coAuthors)->filter()->unique()->values()->map(
            static fn (string $uuid): IdentifierData => $reference($uuid, $form, null)
        );
    }

    /**
     * Attached episodes and medical records from the form as identifiers of them.
     * The medical records are read from the form, and each of them names its own resource type.
     *
     * @param  array  $episodes
     * @param  CarePlanForm  $form
     * @return Collection<int, IdentifierData>
     */
    public static function supportingInfo(array $episodes, CarePlanForm $form): Collection
    {
        $episodeReferences = collect($episodes)->map(
            static fn (array $episode): IdentifierData => (new Reference(ResourceType::EPISODE_OF_CARE))($episode['uuid'], $form, null)
        );

        $recordReferences = collect($form->medicalRecords)->mapWithKeys(static function (array $record) use ($form): array {
            $type = $record['type'] ?? data_get($record, 'identifier.type.coding.0.code');
            $uuid = $record['uuid'] ?? data_get($record, 'identifier.value');

            return ["$type:$uuid" => (new Reference(ResourceType::from($type)))($uuid, $form, null)];
        });

        return $episodeReferences->values()->concat($recordReferences->values());
    }
}

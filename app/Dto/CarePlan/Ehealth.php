<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use App\Dto\EhealthMapping;
use App\Dto\Identifier\Ehealth as IdentifierEhealth;
use App\Dto\Transform\Ehealth\Reference;
use App\Enums\CarePlanStatus;
use App\Enums\ResourceType;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Care plan create payload in the format expected by the eHealth API.
 * Properties without #[Map] are taken from the form property with the same name.
 * A care plan is sent as a new one, so the status is not taken from the form.
 */
#[Map(source: CarePlanForm::class)]
class Ehealth
{
    use EhealthMapping;

    #[Map(source: 'uuid')]
    public string $id;

    public string $intent;

    public string $status = CarePlanStatus::PENDING->value;

    public string $title;

    #[Map(source: 'category', transform: [self::class, 'category'])]
    public CodeableConceptEhealth $category;

    #[Map(source: 'termsOfService', transform: [self::class, 'termsOfService'])]
    public CodeableConceptEhealth $termsOfService;

    public ?string $description = null;

    public ?string $note = null;

    /** @var array{start: string, end?: string} */
    #[Map(source: 'periodStart', transform: [self::class, 'period'])]
    public array $period;

    /** @var array{identifier: IdentifierEhealth} */
    #[Map(source: 'encounter', transform: new Reference(ResourceType::ENCOUNTER))]
    public array $encounter;

    /** @var array{identifier: IdentifierEhealth} */
    #[Map(source: 'authorUuid', transform: new Reference(ResourceType::EMPLOYEE))]
    public array $author;

    /** @var list<array{identifier: IdentifierEhealth}> */
    #[Map(source: 'coAuthors', transform: [self::class, 'contributor'])]
    public array $contributor = [];

    /** @var list<array{identifier: IdentifierEhealth}> */
    #[Map(source: 'episodes', transform: [self::class, 'supportingInfo'])]
    public array $supportingInfo = [];

    /** @var list<array<string, mixed>> */
    public array $addresses;

    /**
     * Care plan category code from the form as a codeable concept of the care plan categories dictionary.
     *
     * @param  string  $category
     * @return CodeableConceptEhealth
     */
    public static function category(string $category): CodeableConceptEhealth
    {
        return CodeableConceptEhealth::map(['coding' => [['system' => 'eHealth/care_plan_categories', 'code' => $category]]]);
    }

    /**
     * Terms of service code from the form as a codeable concept of the providing condition dictionary.
     *
     * @param  string  $termsOfService
     * @return CodeableConceptEhealth
     */
    public static function termsOfService(string $termsOfService): CodeableConceptEhealth
    {
        return CodeableConceptEhealth::map(['coding' => [['system' => 'PROVIDING_CONDITION', 'code' => $termsOfService]]]);
    }

    /**
     * Start and end date and time from the form as the period, ISO 8601 date-times in UTC.
     * The start time defaults to the beginning of the day and the end time to its end.
     * The rest of the period is read from the form, since a transform receives the source along with the mapped value.
     *
     * @param  string  $periodStart
     * @param  CarePlanForm  $form
     * @return array{start: string, end?: string}
     */
    public static function period(string $periodStart, CarePlanForm $form): array
    {
        $start = ['start' => Model::periodStart($periodStart, $form)];
        $end = Model::periodEnd($form->periodEnd, $form);

        return $end === null ? $start : $start + ['end' => $end];
    }

    /**
     * Co-author UUIDs from the form as references to employees, skipping the empty and repeated ones.
     * The form is passed along with the mapped value, since a transform receives the source too.
     *
     * @param  array  $coAuthors
     * @param  CarePlanForm  $form
     * @return list<array{identifier: IdentifierEhealth}>
     */
    public static function contributor(array $coAuthors, CarePlanForm $form): array
    {
        $reference = new Reference(ResourceType::EMPLOYEE);

        return array_map(
            static fn (string $uuid): array => $reference($uuid, $form, null),
            array_values(array_unique(array_filter($coAuthors)))
        );
    }

    /**
     * Attached episodes and medical records from the form as references to them.
     * The medical records are read from the form, and each of them names its own resource type.
     *
     * @param  array  $episodes
     * @param  CarePlanForm  $form
     * @return list<array{identifier: IdentifierEhealth}>
     */
    public static function supportingInfo(array $episodes, CarePlanForm $form): array
    {
        $episodeReferences = array_map(
            static fn (array $episode): array => (new Reference(ResourceType::EPISODE_OF_CARE))($episode['uuid'], $form, null),
            array_values($episodes)
        );

        $recordReferences = collect($form->medicalRecords)
            ->mapWithKeys(static function (array $record) use ($form): array {
                $type = $record['type'] ?? data_get($record, 'identifier.type.coding.0.code');
                $uuid = $record['uuid'] ?? data_get($record, 'identifier.value');

                return ["$type:$uuid" => (new Reference(ResourceType::from($type)))($uuid, $form, null)];
            })
            ->values()
            ->all();

        return [...$episodeReferences, ...$recordReferences];
    }
}

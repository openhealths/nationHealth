<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\EhealthMapping;
use App\Dto\Shared\EhealthReference;
use App\Enums\CarePlanStatus;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirIdentifier;
use App\Mapping\Transforms\FhirReference;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** Validated form plus server-resolved signing context; it does not resolve employees or encounters. */
#[Map(source: CarePlanForm::class)]
final class Ehealth
{
    use EhealthMapping;

    #[Map(if: false)]
    public string $id;

    #[Map(if: false)]
    public string $intent = 'order';

    #[Map(if: false)]
    public string $status = CarePlanStatus::PENDING->value;

    #[Map(transform: new FhirCodeableConcept('eHealth/care_plan_categories'))]
    public array $category;

    public string $title;

    #[Map(source: 'periodStart', transform: [self::class, 'mapPeriod'])]
    public array $period;

    #[Map(if: false)]
    public array $addresses;

    #[Map(source: 'episodes', transform: [[self::class, 'episodeSources'], new MapCollection(targetClass: EhealthReference::class)])]
    public array $supporting_info;

    #[Map(transform: [[self::class, 'optionalString'], new FhirReference('encounter')])]
    public ?array $encounter = null;

    #[Map(if: false)]
    public array $author;

    #[Map(transform: [self::class, 'optionalString'])]
    public ?string $description = null;

    #[Map(transform: [self::class, 'optionalString'])]
    public ?string $note = null;

    #[Map(source: 'termsOfService', transform: new FhirCodeableConcept('PROVIDING_CONDITION'))]
    public array $terms_of_service;

    #[Map(source: 'informWith')]
    public string $inform_with;

    public function __construct(
        string $id,
        ?string $employeeUuid,
        #[Map(if: false)] private readonly array $encounterData,
        #[Map(if: false)] private readonly string $timezone,
    ) {
        $this->id = $id;
        $this->addresses = array_values($encounterData['addresses'] ?? []);
        $this->author = ['identifier' => new FhirIdentifier('employee')($employeeUuid, $this, $this)];
    }

    public static function optionalString(string $value): ?string
    {
        return $value ?: null;
    }

    public static function episodeSources(array $episodes): array
    {
        $sources = [];
        foreach ($episodes as $episode) {
            if (!empty($episode['uuid']) || !empty($episode['id'])) {
                $sources[] = (object) ['uuid' => $episode['uuid'] ?? $episode['id'], 'type' => 'episode_of_care'];
            }
        }

        return $sources;
    }

    public static function mapPeriod(string $value, CarePlanForm $source, self $target): array
    {
        $start = CarbonImmutable::parse($value, $target->timezone)->startOfDay()->utc();
        if (!empty($target->encounterData['period_start'])) {
            $encounterStart = CarbonImmutable::parse($target->encounterData['period_start'], 'UTC');
            if ($start->lt($encounterStart)) {
                $start = $encounterStart->addMinute();
            }
        }

        return array_filter([
            'start' => $start->toIso8601ZuluString(),
            'end' => $source->periodEnd
                ? CarbonImmutable::parse($source->periodEnd, $target->timezone)->endOfDay()->utc()->toIso8601ZuluString()
                : null,
        ]);
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        // Keep this contract's existing recursive empty-value policy and signing field order.
        return removeEmptyKeys($data);
    }
}

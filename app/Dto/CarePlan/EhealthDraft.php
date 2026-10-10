<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\EhealthMapping;
use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use Carbon\CarbonInterface;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** The existing model-draft contract differs from the form create contract; do not merge their wire shapes. */
#[Map(source: CarePlan::class)]
final class EhealthDraft
{
    use EhealthMapping;

    #[Map(if: false)]
    public string $intent = 'order';
    #[Map(if: false)]
    public string $status = CarePlanStatus::DRAFT->value;

    #[Map(source: '[category?]', transform: [self::class, 'categoryCode'])]
    public ?string $category = null;

    #[Map(source: '[context?]', transform: [self::class, 'contextReference'])]
    public ?array $context = null;

    #[Map(source: '[title?]')]
    public ?string $title = null;

    #[Map(source: '[period_start?]', transform: [self::class, 'mapPeriod'])]
    public array $period;

    #[Map(source: '[addresses?]')]
    public ?array $addresses = null;

    #[Map(source: '[supporting_info?]', transform: [[self::class, 'displaySources'], new MapCollection(targetClass: DisplayReference::class)])]
    public array $supporting_info;

    #[Map(source: '[encounter?][uuid?]', transform: [self::class, 'encounterReference'])]
    public ?array $encounter = null;

    #[Map(if: false)]
    public array $care_manager;

    #[Map(source: '[description?]')]
    public ?string $description = null;
    #[Map(source: '[note?]')]
    public ?string $note = null;
    #[Map(source: '[inform_with?]')]
    public ?string $inform_with = null;

    public function __construct(?string $employeeUuid)
    {
        $this->care_manager = ['identifier' => [
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]],
            'value' => $employeeUuid,
        ]];
    }

    public static function categoryCode(mixed $value): ?string
    {
        return is_array($value) ? ($value['coding'][0]['code'] ?? null) : $value;
    }

    public static function contextReference(?string $value): ?array
    {
        return $value ? ['identifier' => ['type_code' => $value]] : null;
    }

    public static function encounterReference(?string $value): ?array
    {
        return $value ? ['identifier' => ['value' => $value]] : null;
    }

    public static function mapPeriod(?CarbonInterface $value, CarePlan $source): array
    {
        return array_filter(['start' => $value?->format('Y-m-d'), 'end' => $source->period_end?->format('Y-m-d')]);
    }

    public static function displaySources(?array $value): array
    {
        return array_map(static fn (array $row): object => (object) $row, array_merge($value['episodes'] ?? [], $value['medical_records'] ?? []));
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        return removeEmptyKeys($data);
    }
}

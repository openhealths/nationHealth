<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Dto\EhealthMapping;
use App\Dto\Shared\EhealthReference;
use App\Mapping\Transforms\FhirIdentifier;
use App\Models\CarePlanActivity;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

#[Map(source: CarePlanActivity::class)]
final class Ehealth
{
    use EhealthMapping;

    #[Map(source: '[uuid?]')]
    public ?string $id;

    #[Map(source: '[author?][uuid?]', transform: [[self::class, 'authors'], new MapCollection(targetClass: EhealthReference::class)])]
    public array $author;

    #[Map(source: '[carePlan?][uuid?]', transform: [self::class, 'carePlan'])]
    public array $care_plan;

    public function __construct(#[Map(if: false)] public readonly EhealthDetail $detail)
    {
    }

    public static function authors(?string $uuid): array
    {
        return [(object) ['uuid' => $uuid, 'type' => 'employee']];
    }

    public static function carePlan(?string $uuid, CarePlanActivity $source): array
    {
        return ['identifier' => new FhirIdentifier('care_plan')($uuid, $source, null)];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        // Keep the existing activity contract, including its empty-value removal policy.
        $order = array_flip([
            'kind', 'status', 'do_not_perform', 'description', 'product_reference', 'product_codeable_concept',
            'scheduled_period', 'quantity', 'daily_amount', 'reason_code', 'reason_reference', 'goal', 'program',
        ]);
        $data['detail'] = array_replace(array_intersect_key($order, $data['detail']), $data['detail']);

        return removeEmptyKeys($data);
    }
}

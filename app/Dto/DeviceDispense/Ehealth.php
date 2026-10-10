<?php

declare(strict_types=1);

namespace App\Dto\DeviceDispense;

use App\Dto\Concerns\MapsCollectionRows;
use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Enums\DeviceDispense\Status;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/** Validated encounter row; the caller supplies the generated ID and encounter context. */
#[Map(source: FormCollection::class)]
final class Ehealth
{
    use MapsCollectionRows;
    use PreservesEhealthDocumentValues;

    #[Map(if: false)]
    public readonly string $id;

    #[Map(source: '[status?]', transform: [self::class, 'statusValue'])]
    public mixed $status;

    #[Map(if: false)]
    public bool $primarySource = true;

    #[Map(source: '[performerId]', transform: new FhirReference('employee', includeText: true))]
    public array $performer;

    #[Map(source: '[locationId]', transform: new FhirReference('division', includeText: true))]
    public array $location;

    #[Map(source: '[whenHandedOverDate]', transform: [self::class, 'handedOver'])]
    public string $whenHandedOver;

    /** @var list<EhealthDetail> */
    #[Map(source: '[quantity]', transform: [[self::class, 'detailRows'], new MapCollection(targetClass: EhealthDetail::class)])]
    public array $details;

    #[Map(if: false)]
    public readonly array $encounter;

    #[Map(source: '[basedOnId?]', if: [self::class, 'filled'], transform: new FhirReference('device_request', includeText: true))]
    public array $basedOn;

    #[Map(source: '[partOfId?]', if: [self::class, 'filled'], transform: new FhirReference('procedure', includeText: true))]
    public array $partOf;

    /** @var list<EhealthSupportingInfo> */
    #[Map(source: '[supportingInfo?]', if: [self::class, 'filled'], transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: EhealthSupportingInfo::class)])]
    public array $supportingInfo;

    #[Map(source: '[note?]', if: [self::class, 'filled'])]
    public mixed $note;

    public function __construct(string $id, string $encounter)
    {
        $this->id = $id;
        $this->encounter = new FhirReference('encounter', includeText: true)($encounter, $this, null);
    }

    public static function statusValue(mixed $value): mixed
    {
        return $value ?? Status::COMPLETED->value;
    }

    public static function handedOver(string $value, FormCollection $source): string
    {
        return convertToEHealthISO8601($value.' '.$source['whenHandedOverTime']);
    }

    public static function detailRows(mixed $value, FormCollection $source): array
    {
        return [new Collection($source->all())];
    }

    public static function filled(mixed $value): bool
    {
        return !empty($value);
    }
}

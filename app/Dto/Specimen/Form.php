<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Dto\Concerns\MapsCollectionRows;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class Form
{
    use MapsCollectionRows;

    #[Map(source: '[uuid?]', transform: [self::class, 'identity'])]
    public mixed $uuid;

    #[Map(source: '[type?][coding?][0?][code?]', if: new SourceHasPath('type.coding.0.code'))]
    public mixed $typeCode = '';

    #[Map(source: '[condition?][coding?][0?][code?]', if: new SourceHasPath('condition.coding.0.code'))]
    public mixed $conditionCode = '';

    #[Map(source: '[receivedTime?]', transform: [self::class, 'date'])]
    public string $receivedDate;

    #[Map(source: '[receivedTime?]', transform: [self::class, 'time'])]
    public string $receivedTime;

    #[Map(source: '[note?]', if: new SourceHasPath('note'))]
    public mixed $note = '';

    /** @var list<FormParent> */
    #[Map(source: '[parent?]', transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: FormParent::class)])]
    public array $parentIds;

    #[Map(source: '[registeredBy?][identifier?][value?]', if: new SourceHasPath('registeredBy.identifier.value'))]
    public mixed $registeredById = '';

    #[Map(source: '[collection?][collector?][identifier?][type?][coding?][0?][code?]', transform: [self::class, 'collectorTypeValue'])]
    public string $collectorType;

    #[Map(source: '[collection?][collector?][identifier?][value?]', if: new SourceHasPath('collection.collector.identifier.value'))]
    public mixed $collectorId = '';

    #[Map(source: '[collection?][collectedPeriod?][start?]', transform: [self::class, 'collectedTypeValue'])]
    public string $collectedType;

    #[Map(source: '[collection?][collectedDateTime?]', transform: [self::class, 'date'])]
    public string $collectedDate;

    #[Map(source: '[collection?][collectedDateTime?]', transform: [self::class, 'time'])]
    public string $collectedTime;

    #[Map(source: '[collection?][collectedPeriod?]', transform: [self::class, 'periodRange'])]
    public string $collectedPeriodRange;

    #[Map(source: '[collection?][collectedPeriod?][start?]', transform: [self::class, 'time'])]
    public string $collectedPeriodStartTime;

    #[Map(source: '[collection?][collectedPeriod?][end?]', transform: [self::class, 'time'])]
    public string $collectedPeriodEndTime;

    #[Map(source: '[collection?][duration?][value?]', if: new SourceHasPath('collection.duration.value'))]
    public mixed $durationValue = '';

    #[Map(source: '[collection?][duration?][code?]', if: new SourceHasPath('collection.duration.code'))]
    public mixed $durationCode = '';

    #[Map(source: '[collection?][quantity?][value?]', if: new SourceHasPath('collection.quantity.value'))]
    public mixed $quantityValue = '';

    #[Map(source: '[collection?][quantity?][code?]', if: new SourceHasPath('collection.quantity.code'))]
    public mixed $quantityCode = '';

    #[Map(source: '[collection?][method?][coding?][0?][code?]', if: new SourceHasPath('collection.method.coding.0.code'))]
    public mixed $methodCode = '';

    #[Map(source: '[collection?][bodySite?][coding?][0?][code?]', if: new SourceHasPath('collection.bodySite.coding.0.code'))]
    public mixed $bodySiteCode = '';

    #[Map(source: '[collection?][fastingStatusCodeableConcept?][coding?][0?][code?]', if: new SourceHasPath('collection.fastingStatusCodeableConcept.coding.0.code'))]
    public mixed $fastingStatusCode = '';

    #[Map(source: '[collection?][procedure?][identifier?][value?]', if: new SourceHasPath('collection.procedure.identifier.value'))]
    public mixed $procedureId = '';

    /** @var list<FormContainer> */
    #[Map(source: '[container?]', transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: FormContainer::class)])]
    public array $containers;

    public static function identity(mixed $value, Collection $source): mixed
    {
        return $source->has('id') ? $source['id'] : $value;
    }

    public static function collectorTypeValue(mixed $value): string
    {
        return $value === 'patient' ? 'patient' : 'other';
    }

    public static function collectedTypeValue(mixed $value): string
    {
        return $value ? 'period' : 'date_time';
    }

    public static function date(mixed $value): string
    {
        return $value ? convertToAppDateFormat($value) : '';
    }

    public static function time(mixed $value): string
    {
        return $value ? CarbonImmutable::parse($value)->format('H:i') : '';
    }

    public static function periodRange(?array $value): string
    {
        return implode(' — ', array_filter([self::date($value['start'] ?? null), self::date($value['end'] ?? null)]));
    }

    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['parentIds'] = array_values(array_filter(array_map(static fn (FormParent $row): string => $row->value, $this->parentIds)));
        $data['containers'] = array_map(get_object_vars(...), $this->containers);

        return $data;
    }
}

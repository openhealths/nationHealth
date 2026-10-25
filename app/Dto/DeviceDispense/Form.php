<?php

declare(strict_types=1);

namespace App\Dto\DeviceDispense;

use App\Enums\DeviceDispense\Status;
use App\Mapping\Conditions\SourceHasPath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[uuid?]')]
    public mixed $uuid;

    #[Map(source: '[basedOn?][identifier?][value?]', if: new SourceHasPath('basedOn.identifier.value'))]
    public mixed $basedOnId = '';

    #[Map(source: '[partOf?][identifier?][value?]', if: new SourceHasPath('partOf.identifier.value'))]
    public mixed $partOfId = '';

    #[Map(source: '[performer?][identifier?][value?]', if: new SourceHasPath('performer.identifier.value'))]
    public mixed $performerId = '';

    #[Map(source: '[location?][identifier?][value?]', if: new SourceHasPath('location.identifier.value'))]
    public mixed $locationId = '';

    #[Map(source: '[whenHandedOver?]', transform: 'convertToAppDateFormat')]
    public string $whenHandedOverDate;

    #[Map(source: '[whenHandedOver?]', transform: [self::class, 'time'])]
    public string $whenHandedOverTime;

    #[Map(source: '[details?][0?][quantity?][value?]', if: new SourceHasPath('details.0.quantity.value'), transform: [self::class, 'integer'])]
    public int $quantity = 1;

    #[Map(source: '[details?][0?][quantity?][code?]', if: new SourceHasPath('details.0.quantity.code'))]
    public mixed $quantityCode = 'piece';

    #[Map(source: '[details?][0?][device?][identifier?][value?]', transform: [self::class, 'selection'])]
    public string $deviceSelectionType;

    #[Map(source: '[details?][0?][deviceCode?][coding?][0?][code?]', if: new SourceHasPath('details.0.deviceCode.coding.0.code'))]
    public mixed $deviceCode = '';

    #[Map(source: '[details?][0?][device?][identifier?][value?]', if: new SourceHasPath('details.0.device.identifier.value'))]
    public mixed $deviceDefinitionId = '';

    #[Map(source: '[note?]', if: new SourceHasPath('note'))]
    public mixed $note = '';

    /** @var list<FormSupportingInfo> */
    #[Map(source: '[supportingInfo?]', transform: [[self::class, 'supportingRows'], new MapCollection(targetClass: FormSupportingInfo::class)])]
    public array $supportingInfo;

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = Status::COMPLETED->value;

    #[Map(source: '[originEpisodeId?]', if: new SourceHasPath('originEpisodeId'))]
    public mixed $originEpisodeId = '';

    #[Map(source: '[contextEpisodeId?]', if: new SourceHasPath('contextEpisodeId'))]
    public mixed $contextEpisodeId = '';

    #[Map(source: '[performerLegalEntity?][displayValue?]', if: new SourceHasPath('performerLegalEntity.displayValue'))]
    public mixed $legalEntityName = '';

    #[Map(source: '[performer?][displayValue?]', if: new SourceHasPath('performer.displayValue'))]
    public mixed $performerName = '';

    #[Map(source: '[ehealthInsertedAt?]', transform: 'convertToAppDateFormat')]
    public string $createdDate;

    public function __construct(#[Map(if: false)] private readonly array $detailsMap = [])
    {
    }

    public static function time(mixed $value): string
    {
        return $value ? CarbonImmutable::parse($value)->format('H:i') : '';
    }

    public static function integer(mixed $value): int
    {
        return (int) $value;
    }

    public static function selection(mixed $value): string
    {
        return $value ? 'model' : 'type';
    }

    public static function supportingRows(?array $rows, Collection $source, self $target): array
    {
        return array_map(static function (array $row) use ($target): Collection {
            $uuid = $row['identifier']['value'] ?? null;

            return new Collection([...$row, 'resolvedDetails' => $target->detailsMap[$uuid] ?? []]);
        }, array_values($rows ?? []));
    }

    public function toArray(): array
    {
        $data = get_object_vars($this);
        unset($data['detailsMap']);
        $data['supportingInfo'] = array_map(get_object_vars(...), $this->supportingInfo);

        return $data;
    }
}

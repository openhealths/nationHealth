<?php

declare(strict_types=1);

namespace App\Dto\Observation;

use App\Enums\Person\ObservationStatus;
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

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = ObservationStatus::VALID->value;

    #[Map(source: '[categories?][0?][coding?][0?][system?]', transform: [self::class, 'codingSystemValue'])]
    public string $codingSystem;

    #[Map(source: '[categories?][0?][coding?][0?][system?]')]
    public mixed $categorySystem;

    #[Map(source: '[code?][coding?][0?][system?]')]
    public mixed $codeSystem;

    #[Map(source: '[primarySource?]')]
    public mixed $primarySource;

    #[Map(source: '[performer?]', transform: [self::class, 'performerValue'])]
    public mixed $performerEmployeeId;

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    #[Map(source: '[categories?][0?][coding?][0?][code?]')]
    public mixed $categoryCode;

    #[Map(source: '[code?][coding?][0?][code?]')]
    public mixed $codeCode;

    #[Map(source: '[method?][coding?][0?][code?]', if: new SourceHasPath('method.coding.0.code'))]
    public mixed $methodCode = '';

    #[Map(source: '[interpretation?][coding?][0?][code?]', if: new SourceHasPath('interpretation.coding.0.code'))]
    public mixed $interpretationCode = '';

    #[Map(source: '[bodySite?][coding?][0?][code?]', if: new SourceHasPath('bodySite.coding.0.code'))]
    public mixed $bodySiteCode = '';

    #[Map(source: '[value?][valueQuantity?][value?]', if: new SourceHasPath('value.valueQuantity.value'))]
    public mixed $valueQuantityValue = '';

    #[Map(source: '[value?][valueQuantity?][comparator?]', if: new SourceHasPath('value.valueQuantity.comparator'))]
    public mixed $valueQuantityComparator = '';

    #[Map(source: '[value?][valueQuantity?][unit?]', if: new SourceHasPath('value.valueQuantity.unit'))]
    public mixed $valueQuantityUnit = '';

    #[Map(source: '[value?][valueQuantity?][system?]', if: new SourceHasPath('value.valueQuantity.system'))]
    public mixed $valueQuantitySystem = '';

    #[Map(source: '[value?][valueQuantity?][code?]', if: new SourceHasPath('value.valueQuantity.code'))]
    public mixed $valueQuantityCode = '';

    #[Map(source: '[comment?]', if: new SourceHasPath('comment'))]
    public mixed $comment = '';

    #[Map(source: '[issuedDate?]')]
    public mixed $issuedDate;

    #[Map(source: '[issuedTime?]')]
    public mixed $issuedTime;

    #[Map(source: '[effectivePeriodStartDate?]', transform: [self::class, 'effectiveTypeValue'])]
    public string $effectiveType;

    #[Map(source: '[effectiveDate?]', if: new SourceHasPath('effectiveDate'))]
    public mixed $effectiveDate = '';

    #[Map(source: '[effectiveTime?]', if: new SourceHasPath('effectiveTime'))]
    public mixed $effectiveTime = '';

    #[Map(source: '[effectivePeriodStartDate?]', transform: [self::class, 'rangeValue'])]
    public string $effectivePeriodRange;

    #[Map(source: '[effectivePeriodStartTime?]', if: new SourceHasPath('effectivePeriodStartTime'))]
    public mixed $effectivePeriodStartTime = '';

    #[Map(source: '[effectivePeriodEndTime?]', if: new SourceHasPath('effectivePeriodEndTime'))]
    public mixed $effectivePeriodEndTime = '';

    #[Map(source: '[reactionOn?][identifier?][value?]', if: new SourceHasPath('reactionOn.identifier.value'))]
    public mixed $reactionOn = '';

    #[Map(source: '[device?][identifier?][value?]', if: new SourceHasPath('device.identifier.value'))]
    public mixed $deviceId = '';

    #[Map(source: '[specimen?][identifier?][value?]', if: new SourceHasPath('specimen.identifier.value'))]
    public mixed $specimenId = '';

    #[Map(source: '[components?]', transform: [[self::class, 'componentRows'], new MapCollection(targetClass: FormComponent::class)])]
    public array $components;

    #[Map(source: '[value?][valueCodeableConcept?][coding?][0?][code?]')]
    public mixed $valueCodeableConcept;

    #[Map(source: '[value?][valueCodeableConcept?][coding?][0?][system?]', if: new SourceHasPath('value.valueCodeableConcept.coding.0.system'))]
    public mixed $dictionaryName = '';

    #[Map(source: '[value?][valueString?]')]
    public mixed $valueString;

    #[Map(source: '[value?][valueBoolean?]')]
    public mixed $valueBoolean;

    #[Map(source: '[value?][valueDateTime?]', transform: [self::class, 'valueDateValue'])]
    public ?string $valueDate;

    #[Map(source: '[value?]', transform: [self::class, 'valueTimeValue'])]
    public ?string $valueTime;

    public static function codingSystemValue(mixed $value, Collection $source): string
    {
        return str_contains($value ?? '', 'ICF') ? 'icf' : (data_get($source->all(), 'code.coding.0.system') === 'eHealth/custom/observation_codes' ? 'custom' : 'loinc');
    }

    public static function performerValue(?array $value): mixed
    {
        return data_get($value, '0.identifier.value', data_get($value, 'identifier.value', ''));
    }

    public static function effectiveTypeValue(mixed $value): string
    {
        return $value ? 'period' : 'date_time';
    }

    public static function rangeValue(mixed $value, Collection $source): string
    {
        return implode(' — ', array_filter([$value ?? '', $source['effectivePeriodEndDate'] ?? '']));
    }

    public static function componentRows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), $value ?: [['value' => ['valueCodeableConcept' => ['coding' => [['code' => '', 'system' => '']]]]]]);
    }

    public static function valueDateValue(mixed $value): ?string
    {
        return $value === null ? null : convertToAppDateFormat($value);
    }

    public static function valueTimeValue(?array $value): ?string
    {
        $time = $value['valueTime'] ?? $value['valueDateTime'] ?? null;

        return $time === null ? null : CarbonImmutable::parse($time)->format('H:i');
    }

    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['components'] = array_map(get_object_vars(...), $this->components);
        foreach (['valueCodeableConcept', 'valueString', 'valueBoolean', 'valueDate', 'valueTime'] as $key) {
            if ($data[$key] === null) {
                unset($data[$key]);
            }
        }
        if ($this->valueCodeableConcept === null) {
            unset($data['dictionaryName']);
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Immunization;

use App\Enums\Person\ImmunizationStatus;
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
    public mixed $status = ImmunizationStatus::COMPLETED->value;

    #[Map(source: '[primarySource?]')]
    public mixed $primarySource;

    #[Map(source: '[performer?][identifier?][value?]', if: new SourceHasPath('performer.identifier.value'))]
    public mixed $performerEmployeeId = '';

    #[Map(source: '[notGiven?]', if: new SourceHasPath('notGiven'))]
    public mixed $notGiven = false;

    #[Map(source: '[vaccineCode?][coding?][0?][code?]')]
    public mixed $vaccineCode;

    #[Map(source: '[date?]', transform: [self::class, 'dateValue'])]
    public string $date;

    #[Map(source: '[time?]')]
    public mixed $time;

    #[Map(source: '[explanation?][reasons?]', transform: [[self::class, 'reasonRows'], new MapCollection(targetClass: \App\Dto\Shared\FormConceptCode::class)])]
    public array $reasons;

    #[Map(source: '[explanation?][reasonsNotGiven?][0?][coding?][0?][code?]', if: new SourceHasPath('explanation.reasonsNotGiven.0.coding.0.code'))]
    public mixed $reasonNotGivenCode = '';

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    #[Map(source: '[reportOrigin?][text?]', if: new SourceHasPath('reportOrigin.text'))]
    public mixed $reportOriginText = '';

    #[Map(source: '[manufacturer?]', if: new SourceHasPath('manufacturer'))]
    public mixed $manufacturer = '';

    #[Map(source: '[lotNumber?]', if: new SourceHasPath('lotNumber'))]
    public mixed $lotNumber = '';

    #[Map(source: '[expirationDate?]', transform: [self::class, 'expirationDateValue'])]
    public string $expirationDate;

    #[Map(source: '[expirationDate?]', transform: [self::class, 'expirationTimeValue'])]
    public string $expirationTime;

    #[Map(source: '[site?][coding?][0?][code?]', if: new SourceHasPath('site.coding.0.code'))]
    public mixed $siteCode = '';

    #[Map(source: '[route?][coding?][0?][code?]', if: new SourceHasPath('route.coding.0.code'))]
    public mixed $routeCode = '';

    #[Map(source: '[doseQuantity?][value?]')]
    public mixed $doseQuantityValue;

    #[Map(source: '[doseQuantity?][code?]', if: new SourceHasPath('doseQuantity.code'))]
    public mixed $doseQuantityCode = '';

    #[Map(source: '[doseQuantity?][unit?]', if: new SourceHasPath('doseQuantity.unit'))]
    public mixed $doseQuantityUnit = '';

    #[Map(source: '[vaccinationProtocols?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: FormProtocol::class)])]
    public array $vaccinationProtocols;

    public static function dateValue(string $value): string
    {
        return CarbonImmutable::createFromFormat(config('app.date_format').' H:i', $value)->format(config('app.date_format'));
    }

    public static function expirationDateValue(mixed $value): string
    {
        return $value ? self::dateValue($value) : '';
    }

    public static function expirationTimeValue(mixed $value): string
    {
        return $value ? CarbonImmutable::createFromFormat(config('app.date_format').' H:i', $value)->format('H:i') : '';
    }

    public static function reasonRows(?array $value, Collection $source): array
    {
        if ($source['notGiven'] ?? false) {
            return [];
        }
        $rows = array_values(array_filter($value ?? [], static fn (array $row): bool => !empty(data_get($row, 'coding.0.code'))));

        return self::rows($rows ?: [[]]);
    }

    public static function rows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), $value ?? []);
    }

    public function toArray(): array
    {
        $data = get_object_vars($this);
        unset($data['detailsMap'], $data['fallbackTime']);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = array_map(static fn (mixed $row): mixed => is_object($row) ? (method_exists($row, 'toArray') ? $row->toArray() : get_object_vars($row)) : $row, $value);
            }
        }

        return $data;
    }
}

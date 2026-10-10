<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Dto\Shared\EhealthReference;
use App\Mapping\Transforms\FhirReference;
use App\Models\CarePlanActivity;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/** Pure contract mapping. Relations, dictionary metadata and the operation clock are supplied before mapping. */
#[Map(source: CarePlanActivity::class)]
final class EhealthDetail
{
    #[Map(source: '[kind?]')]
    public ?string $kind;

    #[Map(if: false)]
    public string $status = 'scheduled';

    #[Map(source: '[do_not_perform?]', transform: [self::class, 'boolean'])]
    public bool $do_not_perform;

    #[Map(source: '[description?]', transform: [self::class, 'optionalText'])]
    public ?string $description;

    #[Map(source: '[product_reference?]', transform: [self::class, 'productReference'])]
    public ?array $product_reference;

    #[Map(source: '[product_codeable_concept?]', transform: [self::class, 'productConcept'])]
    public ?array $product_codeable_concept;

    #[Map(source: '[scheduled_period_start?]', transform: [self::class, 'scheduledPeriod'])]
    public array $scheduled_period;

    #[Map(source: '[quantity?]', transform: [self::class, 'quantity'])]
    public ?array $quantity;

    #[Map(source: '[daily_amount?]', transform: [self::class, 'dailyAmount'])]
    public ?array $daily_amount;

    #[Map(source: '[reason_code?]', transform: [self::class, 'reasonCodes'])]
    public ?array $reason_code;

    #[Map(source: '[reason_reference?]', transform: [[self::class, 'references'], new MapCollection(targetClass: EhealthReference::class)])]
    public array $reason_reference;

    #[Map(source: '[goal?]', transform: [[self::class, 'goals'], new MapCollection(targetClass: Goal::class)])]
    public array $goal;

    #[Map(source: '[program?]', transform: [self::class, 'program'])]
    public ?array $program;

    public function __construct(
        #[Map(if: false)] private readonly ?string $quantityCode,
        #[Map(if: false)] private readonly ?string $dailyAmountCode,
        #[Map(if: false)] private readonly array $allowedDeviceCodeTypes,
        #[Map(if: false)] private readonly CarbonInterface $mappedAt,
        #[Map(if: false)] private readonly ?CarbonInterface $planStart = null,
    ) {
    }

    public static function boolean(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function optionalText(?string $value): ?string
    {
        return $value ?: null;
    }

    public static function productReference(mixed $value, CarePlanActivity $source, self $target): ?array
    {
        return $target->productFields($source)['product_reference'];
    }

    public static function productConcept(mixed $value, CarePlanActivity $source, self $target): ?array
    {
        return $target->productFields($source)['product_codeable_concept'];
    }

    private function productFields(CarePlanActivity $source): array
    {
        $kind = strtolower((string) $source->kind);
        $reference = $source->product_reference;
        $concept = $source->product_codeable_concept;
        if (!str_contains($kind, 'device')) {
            $type = str_contains($kind, 'medication') ? 'medication' : 'service';

            return ['product_reference' => !empty($reference) ? new FhirReference($type)($reference, $source, $this) : null, 'product_codeable_concept' => null];
        }
        $types = $this->allowedDeviceCodeTypes;
        $allowsClassification = in_array('CLASSIFICATION_TYPE', $types, true);
        $allowsDefinition = in_array('DEVICE_DEFINITION', $types, true);
        $isUuid = is_string($reference) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $reference) === 1;
        $useConcept = ($types !== [] && $allowsClassification && !$allowsDefinition && !empty($concept))
            || ($types !== [] && $allowsClassification && !($allowsDefinition && $isUuid) && !empty($concept))
            || (!$isUuid && !empty($concept));
        if ($useConcept) {
            return ['product_reference' => null, 'product_codeable_concept' => ['coding' => [['system' => 'device_definition_classification_type', 'code' => $concept]]]];
        }

        return ['product_reference' => $isUuid ? new FhirReference('device_definition')($reference, $source, $this) : null, 'product_codeable_concept' => null];
    }

    public static function scheduledPeriod(mixed $value, CarePlanActivity $source, self $target): array
    {
        $period = $source->scheduledPeriod;
        $start = $period ? $period->getRawOriginal('start') : $source->scheduled_period_start;
        $end = $period ? $period->getRawOriginal('end') : $source->scheduled_period_end;
        $formattedStart = null;
        $formattedEnd = null;
        if (!empty($start)) {
            if ($source->uuid && $period) {
                $formattedStart = Carbon::parse($start, 'UTC')->utc()->toIso8601ZuluString();
            } else {
                $date = Carbon::parse($start);
                $status = strtolower((string) ($source->status ?? ''));
                $time = $status === '' || $status === 'draft' || $date->isSameDay($target->mappedAt)
                    ? $target->mappedAt->format('H:i:s') : '12:00:00';
                $formattedStart = convertToEHealthISO8601($date->format('Y-m-d').' '.$time);
                if ($target->planStart && Carbon::parse($formattedStart)->utc()->lt($target->planStart)) {
                    $nowUtc = $target->mappedAt->copy()->utc();
                    $formattedStart = ($nowUtc->lt($target->planStart) ? $target->planStart : $nowUtc)->toIso8601ZuluString();
                }
            }
        }
        if ($end) {
            $formattedEnd = $source->uuid && $period
                ? Carbon::parse($end, 'UTC')->utc()->toIso8601ZuluString()
                : convertToEHealthISO8601(Carbon::parse($end)->format('Y-m-d').' 23:59:59');
        }

        return ['start' => $formattedStart, 'end' => $formattedEnd];
    }

    public static function quantity(mixed $value, CarePlanActivity $source, self $target): ?array
    {
        $quantity = $source->quantityQuantity;
        $value = $quantity ? $quantity->value : $value;

        return $value ? [
            'value' => self::quantityValue($value, (string) $source->kind),
            'system' => $quantity ? $quantity->system : $source->quantity_system,
            'code' => $target->quantityCode,
            'unit' => $quantity ? ($quantity->unit ?: null) : null,
        ] : null;
    }

    public static function dailyAmount(mixed $value, CarePlanActivity $source, self $target): ?array
    {
        $quantity = $source->dailyAmountQuantity;
        $total = $source->quantityQuantity;
        $isMedication = str_contains(strtolower((string) $source->kind), 'medication');
        $value = $quantity ? $quantity->value : $value;

        return $value ? [
            'value' => self::quantityValue($value, (string) $source->kind),
            'system' => $isMedication ? ($quantity ? $quantity->system : ($source->daily_amount_system ?? ($total ? $total->system : $source->quantity_system))) : null,
            'code' => $isMedication ? $target->dailyAmountCode : null,
            'unit' => $isMedication && $quantity ? ($quantity->unit ?: null) : null,
        ] : null;
    }

    private static function quantityValue(mixed $value, string $kind): int|float
    {
        return str_contains(strtolower($kind), 'device') ? (int) $value : (float) $value;
    }

    public static function reasonCodes(mixed $value): ?array
    {
        return $value ? [['coding' => [['code' => $value]]]] : null;
    }

    public static function references(?array $values): array
    {
        return array_map(static function (string $reference): object {
            $parts = explode('/', $reference);
            $type = count($parts) === 2 ? strtolower(trim($parts[0])) : 'condition';

            return (object) ['type' => $type === 'diagnosticreport' ? 'diagnostic_report' : $type, 'uuid' => trim(count($parts) === 2 ? $parts[1] : $reference)];
        }, $values ?? []);
    }

    public static function goals(?array $values): array
    {
        return array_map(static fn (string $value): object => (object) ['code' => $value], $values ?? []);
    }

    public static function program(?string $value, CarePlanActivity $source): ?array
    {
        return $value ? new FhirReference('medical_program')($value, $source, null) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Models\CarePlanActivity;
use Carbon\CarbonInterface;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: CarePlanActivity::class)]
final class Form
{
    #[Map(source: '[id?]')]
    public ?int $id;
    #[Map(source: '[kind?]', transform: [self::class, 'mapKind'])]
    public mixed $kind;
    #[Map(source: '[program?]', transform: [self::class, 'blank'])]
    public mixed $program;
    #[Map(source: '[quantity?]', transform: [self::class, 'mapQuantity'])]
    public mixed $quantity;
    #[Map(source: '[quantity?]', transform: [self::class, 'mapQuantitySystem'])]
    public mixed $quantity_system;
    #[Map(source: '[quantityCode?]', transform: [self::class, 'blank'])]
    public mixed $quantity_code;
    #[Map(source: '[dailyAmount?]', transform: [self::class, 'blank'])]
    public mixed $daily_amount;
    #[Map(source: '[dailyAmountSystem?]', transform: [self::class, 'blank'])]
    public mixed $daily_amount_system;
    #[Map(source: '[dailyAmountCode?]', transform: [self::class, 'blank'])]
    public mixed $daily_amount_code;
    #[Map(source: '[reasonCode?]', transform: [self::class, 'blank'])]
    public mixed $reason_code;
    #[Map(source: '[reasonReference?]', transform: [self::class, 'blank'])]
    public mixed $reason_reference;
    #[Map(source: '[goal?]', transform: [self::class, 'mapGoal'])]
    public string $goal;
    #[Map(source: '[description?]', transform: [self::class, 'blank'])]
    public mixed $description;
    #[Map(source: '[scheduledPeriodStart?]', transform: [self::class, 'date'])]
    public string $scheduled_period_start;
    #[Map(source: '[scheduledPeriodEnd?]', transform: [self::class, 'date'])]
    public string $scheduled_period_end;
    #[Map(source: '[productReference?]', transform: [self::class, 'blank'])]
    public mixed $product_reference;
    #[Map(source: '[productCodeableConcept?]', transform: [self::class, 'blank'])]
    public mixed $product_codeable_concept;

    public static function blank(mixed $value): mixed
    {
        return $value ?? '';
    }

    public static function mapKind(mixed $value, CarePlanActivity $source): mixed
    {
        return is_array($value) ? ($value['coding'][0]['code'] ?? $value['text'] ?? '') : ($source->kindConcept?->coding?->first()?->code ?? $value);
    }

    public static function mapQuantity(mixed $value): mixed
    {
        return is_array($value) ? ($value['value'] ?? '') : $value;
    }

    public static function mapQuantitySystem(mixed $value, CarePlanActivity $source): mixed
    {
        return is_array($value) ? ($value['unit'] ?? '') : $source->quantitySystem;
    }

    public static function mapGoal(mixed $value): string
    {
        return is_array($value) ? (string) ($value[0] ?? '') : (string) ($value ?? '');
    }

    public static function date(?CarbonInterface $value): string
    {
        return $value?->format('d.m.Y') ?? '';
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

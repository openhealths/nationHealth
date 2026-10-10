<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanActivitySync;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Mapping\Transforms\FallbackValue;
use Carbon\Carbon;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: CarePlanComponent::class, if: new SourceClass(CarePlanComponent::class))]
#[Map(source: CarePlanActivitySync::class, if: new SourceClass(CarePlanActivitySync::class))]
final class Model
{
    #[Map(source: 'activityForm[kind]', if: new SourceClass(CarePlanComponent::class))]
    #[Map(source: '[detail?][kind?]', if: new SourceClass(CarePlanActivitySync::class), transform: [self::class, 'mapKind'])]
    public ?string $kind;

    #[Map(source: '[status?]', if: new SourceClass(CarePlanActivitySync::class))]
    public ?string $status;

    #[Map(source: 'activityForm[quantity?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][quantity?][value?]', if: new SourceClass(CarePlanActivitySync::class))]
    public int|float|string|null $quantity;

    #[Map(source: 'activityForm[quantity_system?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][quantity?][system?]', if: new SourceClass(CarePlanActivitySync::class))]
    public ?string $quantity_system;

    #[Map(source: 'activityForm[quantity_code?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][quantity?][code?]', if: new SourceClass(CarePlanActivitySync::class))]
    public ?string $quantity_code;

    #[Map(source: 'activityForm[daily_amount?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][dailyAmount?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.daily_amount'), [self::class, 'amountValue']])]
    public int|float|string|null $daily_amount;

    #[Map(source: 'activityForm[quantity_code?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'localDailySystem'])]
    #[Map(source: '[detail?][dailyAmount?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.daily_amount'), [self::class, 'amountSystem']])]
    public ?string $daily_amount_system;

    #[Map(source: 'activityForm[quantity_code?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'localDailyCode'])]
    #[Map(source: '[detail?][dailyAmount?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.daily_amount'), [self::class, 'amountCode']])]
    public ?string $daily_amount_code;

    #[Map(source: 'activityForm[description?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][description?]', if: new SourceClass(CarePlanActivitySync::class))]
    public ?string $description;

    #[Map(source: 'activityForm[product_reference?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][product_reference?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.productReference'), [self::class, 'referenceValue']])]
    public ?string $product_reference;

    #[Map(source: 'activityForm[product_codeable_concept?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][product_codeable_concept?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.productCodeableConcept'), [self::class, 'mapConceptCode']])]
    public ?string $product_codeable_concept;

    #[Map(source: 'activityForm[program?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'localProgram'])]
    #[Map(source: '[detail?][program?]', if: new SourceClass(CarePlanActivitySync::class), transform: [self::class, 'mapProgram'])]
    public mixed $program;

    #[Map(source: 'activityForm[reason_code?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'emptyToNull'])]
    #[Map(source: '[detail?][reason_code?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.reasonCode'), [self::class, 'mapReasonCode']])]
    public ?string $reason_code;

    #[Map(source: 'linkedGrounds', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'localReferences'])]
    #[Map(source: '[detail?][reason_reference?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.reasonReference'), [self::class, 'remoteReferences']])]
    public ?array $reason_reference;

    #[Map(source: 'activityForm[goal?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'localGoal'])]
    #[Map(source: '[detail?][goal?]', if: new SourceClass(CarePlanActivitySync::class), transform: [self::class, 'remoteGoals'])]
    public ?array $goal;

    #[Map(source: 'activityForm[scheduled_period_start?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'localStart'])]
    #[Map(source: '[detail?][scheduledPeriod?][start?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.scheduled_period.start'), [self::class, 'remoteDate']])]
    public mixed $scheduled_period_start;

    #[Map(source: 'activityForm[scheduled_period_end?]', if: new SourceClass(CarePlanComponent::class), transform: [self::class, 'localEnd'])]
    #[Map(source: '[detail?][scheduledPeriod?][end?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.scheduled_period.end'), [self::class, 'remoteDate']])]
    public mixed $scheduled_period_end;

    #[Map(source: '[detail?][status_reason?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.statusReason'), [self::class, 'mapStatusReason']])]
    public mixed $status_reason;

    #[Map(source: '[detail?][remaining_quantity?][value?]', if: new SourceClass(CarePlanActivitySync::class), transform: new FallbackValue('detail.remainingQuantity.value'))]
    public int|float|string|null $remaining_quantity;

    #[Map(source: '[detail?][remaining_quantity?][system?]', if: new SourceClass(CarePlanActivitySync::class), transform: new FallbackValue('detail.remainingQuantity.system'))]
    public ?string $remaining_quantity_system;

    #[Map(source: '[detail?][remaining_quantity?][code?]', if: new SourceClass(CarePlanActivitySync::class), transform: new FallbackValue('detail.remaining_quantity.unit', 'detail.remainingQuantity.code', 'detail.remainingQuantity.unit'))]
    public ?string $remaining_quantity_code;

    #[Map(source: '[detail?][outcome_reference?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.outcomeReference'), [self::class, 'outcomeReferences']])]
    public ?string $outcome_reference;

    #[Map(source: '[detail?][outcome_codeable_concept?]', if: new SourceClass(CarePlanActivitySync::class), transform: [new FallbackValue('detail.outcomeCodeableConcept'), [self::class, 'mapConceptCode']])]
    public ?string $outcome_codeable_concept;

    public function __construct(
        #[Map(if: false)] private readonly ?string $resolvedProgram = null,
        #[Map(if: false)] private readonly mixed $start = null,
        #[Map(if: false)] private readonly mixed $end = null,
    ) {
    }

    public static function emptyToNull(mixed $value): mixed
    {
        return !empty($value) ? $value : null;
    }

    public static function mapKind(mixed $value): ?string
    {
        return is_array($value) ? ($value['coding'][0]['code'] ?? $value['text'] ?? null) : ($value !== null ? (string) $value : null);
    }

    public static function mapConceptCode(mixed $value): ?string
    {
        return is_array($value) ? ($value['coding'][0]['code'] ?? null) : (is_string($value) ? $value : null);
    }

    public static function mapReasonCode(mixed $value): ?string
    {
        return is_array($value) ? ($value[0]['coding'][0]['code'] ?? $value['coding'][0]['code'] ?? null) : (is_string($value) ? $value : null);
    }

    public static function mapStatusReason(mixed $value): mixed
    {
        return is_array($value) ? ($value['coding'][0]['code'] ?? $value['text'] ?? null) : $value;
    }

    public static function mapProgram(mixed $value): mixed
    {
        return data_get($value, 'identifier.value') ?? $value;
    }

    public static function referenceValue(mixed $value): ?string
    {
        return data_get($value, 'identifier.value');
    }

    public static function amountValue(?array $value): int|float|string|null
    {
        return $value['value'] ?? null;
    }

    public static function amountSystem(?array $value): ?string
    {
        return $value['system'] ?? null;
    }

    public static function amountCode(?array $value): ?string
    {
        return $value['code'] ?? null;
    }

    public static function localProgram(mixed $value, CarePlanComponent $source, self $target): ?string
    {
        return $target->resolvedProgram;
    }

    public static function localDailyCode(?string $value, CarePlanComponent $source): ?string
    {
        return str_contains(strtolower($source->activityForm['kind']), 'medication') ? $value : null;
    }

    public static function localDailySystem(?string $value, CarePlanComponent $source): ?string
    {
        return self::localDailyCode($value, $source) ? 'MEDICATION_UNIT' : null;
    }

    public static function localStart(mixed $value, CarePlanComponent $source, self $target): mixed
    {
        return $target->start;
    }

    public static function localEnd(mixed $value, CarePlanComponent $source, self $target): mixed
    {
        return $target->end;
    }

    public static function remoteDate(mixed $value): ?Carbon
    {
        return $value !== null ? Carbon::parse($value) : null;
    }

    public static function localReferences(array $values): ?array
    {
        return $values !== [] ? collect($values)->map(static fn ($reference) => $reference['type'].'/'.$reference['uuid'])->toArray() : null;
    }

    public static function remoteReferences(?array $values): array
    {
        $references = [];
        foreach ($values ?? [] as $reference) {
            $value = $reference['identifier']['value'] ?? null;
            if (!$value) {
                continue;
            }
            if (!str_contains($value, '/')) {
                $code = $reference['identifier']['type']['coding'][0]['code'] ?? '';
                $type = match (strtolower($code)) {
                    'observation' => 'Observation',
                    'diagnostic_report' => 'DiagnosticReport',
                    default => 'Condition',
                };
                $value = $type.'/'.$value;
            }
            $references[] = $value;
        }

        return $references;
    }

    public static function localGoal(mixed $value): ?array
    {
        return !empty($value) ? [(string) $value] : null;
    }

    public static function remoteGoals(?array $values): array
    {
        $goals = [];
        foreach ($values ?? [] as $goal) {
            $value = $goal['coding'][0]['code'] ?? $goal['identifier']['value'] ?? null;
            if ($value) {
                $goals[] = $value;
            }
        }

        return $goals;
    }

    public static function outcomeReferences(?array $values): ?string
    {
        return collect($values ?? [])->map(static fn ($reference) => $reference['identifier']['value'] ?? null)->filter()->implode(', ') ?: null;
    }

    public function toArray(): array
    {
        return array_diff_key(get_object_vars($this), array_flip(['resolvedProgram', 'start', 'end']));
    }
}

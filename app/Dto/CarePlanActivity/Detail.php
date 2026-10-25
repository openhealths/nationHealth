<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Dto\Fhir\CodeableConcept;
use App\Dto\Fhir\Reference;
use App\Dto\Fhir\ResourceData;
use App\Dto\FormCollection;
use App\Enums\CarePlanActivityKind;
use App\Mapping\Conditions\SourceHasPath;
use App\Mapping\Conditions\SourceHasAnyPath;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[Map(source: FormCollection::class)]
final class Detail implements Arrayable
{
    use ResourceData;
    #[Map(source: '[kind?]', if: new SourceHasPath('kind'))]
    public mixed $kind;

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status;

    #[Map(source: '[doNotPerform?]', if: new SourceHasPath('doNotPerform'))]
    public mixed $doNotPerform;

    #[Map(source: '[description?]', if: new SourceHasPath('description'))]
    public mixed $description;

    #[Map(source: '[scheduledTiming?]', if: new SourceHasPath('scheduledTiming'))]
    public mixed $scheduledTiming;

    #[Map(source: '[scheduledString?]', if: new SourceHasPath('scheduledString'))]
    public mixed $scheduledString;

    #[Map(source: '[remainingQuantityType?]', if: new SourceHasPath('remainingQuantityType'))]
    public mixed $remainingQuantityType;

    #[Map(source: '[productCodeableConcept?]', if: new SourceHasPath('productCodeableConcept'), transform: [self::class, 'mapProductCodeableConcept'])]
    public mixed $productCodeableConcept;

    #[Map(source: '[statusReason?]', if: new SourceHasPath('statusReason'), transform: [self::class, 'mapStatusReason'])]
    public mixed $statusReason;

    #[Map(source: '[reasonCode?]', if: new SourceHasPath('reasonCode'), transform: [self::class, 'mapReasonCode'])]
    public mixed $reasonCode;

    #[Map(source: '[goal?]', if: new SourceHasPath('goal'), transform: [self::class, 'mapGoal'])]
    public mixed $goal;

    #[Map(source: '[productReference?]', if: new SourceHasPath('productReference'), transform: [self::class, 'mapProductReference'])]
    public mixed $productReference;

    #[Map(source: '[location?]', if: new SourceHasPath('location'), transform: [self::class, 'mapLocation'])]
    public mixed $location;

    #[Map(source: '[performer?]', if: new SourceHasPath('performer'), transform: [self::class, 'mapPerformer'])]
    public mixed $performer;

    #[Map(source: '[program?]', if: new SourceHasPath('program'), transform: [self::class, 'mapProgram'])]
    public mixed $program;

    #[Map(source: '[quantity?]', if: new SourceHasPath('quantity'), transform: [self::class, 'mapQuantity'])]
    public mixed $quantity;

    #[Map(source: '[dailyAmount?]', if: new SourceHasPath('dailyAmount'), transform: [self::class, 'mapDailyAmount'])]
    public mixed $dailyAmount;

    #[Map(source: '[remainingQuantity?]', if: new SourceHasPath('remainingQuantity'), transform: [self::class, 'mapRemainingQuantity'])]
    public mixed $remainingQuantity;

    #[Map(source: '[reasonReference?]', if: new SourceHasPath('reasonReference'), transform: [self::class, 'mapReasonReference'])]
    public mixed $reasonReference;

    #[Map(source: '[scheduledPeriod?]', if: new SourceHasAnyPath(['scheduledPeriod', 'scheduledPeriodStart', 'scheduledPeriodEnd']), transform: [self::class, 'mapScheduledPeriod'])]
    public mixed $scheduledPeriod;
    public function __construct(
        #[Map(if: false)] private readonly array $options = [],
        #[Map(if: false)] private readonly array $input = [],
    ) {
        $this->original = array_replace($options['resource']['detail'] ?? [], $input['detail'] ?? []);
    }

    public static function fromSource(FormCollection $source, array $context = []): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($source, new self($context, $source->all()));
    }

    public static function mapProductCodeableConcept(mixed $value): ?CodeableConcept
    {
        return CodeableConcept::fromValue($value, 'device_definition_classification_type');
    }

    public static function mapStatusReason(mixed $value): ?CodeableConcept
    {
        return CodeableConcept::fromValue($value, null);
    }

    public static function mapReasonCode(mixed $value): array
    {
        return self::mapConcepts($value, 'eHealth/ICD10_AM/condition_codes');
    }

    public static function mapGoal(mixed $value): array
    {
        return self::mapConcepts($value, 'eHealth/care_plan_activity_goals');
    }

    private static function mapConcepts(mixed $value, string $system): array
    {
        $values = $value === null || $value === '' ? [] : (is_array($value) && array_is_list($value) ? $value : [$value]);
        $rows = array_map(static fn (mixed $item): array => CodeableConcept::value($item, $system), $values);

        return self::mapRows($rows, CodeableConcept::class);
    }

    public static function mapProductReference(mixed $value, FormCollection $source, self $target): ?Reference
    {
        return $value === null || $value === '' ? null : Reference::fromValue($value, $target->productType($source), typedPath: true);
    }

    public static function mapLocation(mixed $value, FormCollection $source, self $target): ?Reference
    {
        return $value === null || $value === '' ? null : Reference::fromValue($value, 'division', typedPath: true);
    }

    public static function mapPerformer(mixed $value, FormCollection $source, self $target): ?Reference
    {
        return $value === null || $value === '' ? null : Reference::fromValue($value, 'employee', typedPath: true);
    }

    public static function mapProgram(mixed $value, FormCollection $source, self $target): ?Reference
    {
        return $value === null || $value === '' ? null : Reference::fromValue($value, 'medical_program', typedPath: true);
    }

    private function productType(FormCollection $source): string
    {
        $kind = CarePlanActivityKind::tryFrom($source['kind'] ?? $this->original['kind'] ?? '')
            ?? throw new InvalidArgumentException('An activity requires a supported kind.');

        return $this->options['productType'] ?? match ($kind) {
            CarePlanActivityKind::MEDICATION_REQUEST => 'medication',
            CarePlanActivityKind::DEVICE_REQUEST => 'device_definition',
            CarePlanActivityKind::SERVICE_REQUEST => 'service',
        };
    }

    public static function mapQuantity(mixed $value, FormCollection $source, self $target): mixed
    {
        return $target->normalizeQuantity($value, $source, 'quantity');
    }

    public static function mapDailyAmount(mixed $value, FormCollection $source, self $target): mixed
    {
        return $target->normalizeQuantity($value, $source, 'dailyAmount');
    }

    public static function mapRemainingQuantity(mixed $value, FormCollection $source, self $target): mixed
    {
        return $target->normalizeQuantity($value, $source, 'remainingQuantity');
    }

    private function normalizeQuantity(mixed $value, FormCollection $source, string $field): mixed
    {
        $quantity = is_array($value) || $value === null ? $value : ($value === '' ? null : array_replace($this->original[$field] ?? [], ['value' => is_numeric($value) ? $value + 0 : $value]));
        if (is_array($quantity)) {
            foreach (['system', 'code', 'unit', 'comparator'] as $part) {
                $key = $field.ucfirst($part);
                if ($source->has($key)) {
                    $quantity[$part] = $source[$key];
                }
            }
        }

        return $quantity;
    }

    public static function mapReasonReference(mixed $value, FormCollection $source, self $target): array
    {
        $existing = $target->original['reasonReference'] ?? [];

        return self::mapRows(array_map(static fn (mixed $reference): array => Reference::value($reference, null, $existing, true), $value ?? []), Reference::class);
    }

    public static function mapScheduledPeriod(mixed $value, FormCollection $source, self $target): mixed
    {
        if ($source->has('scheduledPeriod')) {
            return $value;
        }
        $period = $target->original['scheduledPeriod'] ?? [];
        foreach (['start', 'end'] as $boundary) {
            $field = 'scheduledPeriod'.ucfirst($boundary);
            if ($source->has($field)) {
                $period[$boundary] = $source[$field];
            }
        }

        return $period;
    }

    public function toArray(): array
    {
        $detail = array_replace($this->original, $this->mappedFields());
        if (CarePlanActivityKind::tryFrom($detail['kind'] ?? '') === null) {
            throw new InvalidArgumentException('An activity requires a supported kind.');
        }
        if (isset($detail['productReference'], $detail['productCodeableConcept'])) {
            throw new InvalidArgumentException('An activity cannot contain both product variants.');
        }
        $scheduled = array_filter(['scheduledPeriod', 'scheduledTiming', 'scheduledString'], static fn (string $field): bool => isset($detail[$field]));
        if (count($scheduled) > 1) {
            throw new InvalidArgumentException('An activity must use a single scheduled variant.');
        }

        return $detail;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Dto\Fhir\ResourceData;
use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[Map(source: FormCollection::class)]
final class Form implements Arrayable
{
    use ResourceData;
    #[Map(source: '[detail][kind?]', if: new SourceHasPath('detail.kind'))]
    public mixed $kind;

    #[Map(source: '[detail][status?]', if: new SourceHasPath('detail.status'))]
    public mixed $status;

    #[Map(source: '[detail][doNotPerform?]', if: new SourceHasPath('detail.doNotPerform'))]
    public mixed $doNotPerform;

    #[Map(source: '[detail][description?]', if: new SourceHasPath('detail.description'))]
    public mixed $description;

    #[Map(source: '[detail][reasonCode?]', if: new SourceHasPath('detail.reasonCode'))]
    public mixed $reasonCode;

    #[Map(source: '[detail][goal?]', if: new SourceHasPath('detail.goal'))]
    public mixed $goal;

    #[Map(source: '[detail][reasonReference?]', if: new SourceHasPath('detail.reasonReference'))]
    public mixed $reasonReference;

    #[Map(source: '[detail][scheduledPeriod?]', if: new SourceHasPath('detail.scheduledPeriod'))]
    public mixed $scheduledPeriod;

    #[Map(source: '[detail][scheduledTiming?]', if: new SourceHasPath('detail.scheduledTiming'))]
    public mixed $scheduledTiming;

    #[Map(source: '[detail][scheduledString?]', if: new SourceHasPath('detail.scheduledString'))]
    public mixed $scheduledString;

    #[Map(source: '[detail][remainingQuantityType?]', if: new SourceHasPath('detail.remainingQuantityType'))]
    public mixed $remainingQuantityType;

    #[Map(source: '[detail][statusReason?]', if: new SourceHasPath('detail.statusReason'))]
    public mixed $statusReason;

    #[Map(source: '[detail][productCodeableConcept?]', if: new SourceHasPath('detail.productCodeableConcept'))]
    public mixed $productCodeableConcept;

    #[Map(source: '[detail][productReference?]', if: new SourceHasPath('detail.productReference'))]
    public mixed $productReference;

    #[Map(source: '[detail][location?]', if: new SourceHasPath('detail.location'))]
    public mixed $location;

    #[Map(source: '[detail][performer?]', if: new SourceHasPath('detail.performer'))]
    public mixed $performer;

    #[Map(source: '[detail][program?]', if: new SourceHasPath('detail.program'))]
    public mixed $program;

    #[Map(source: '[detail][quantity?]', if: new SourceHasPath('detail.quantity'))]
    public mixed $quantity;

    #[Map(source: '[detail][dailyAmount?]', if: new SourceHasPath('detail.dailyAmount'))]
    public mixed $dailyAmount;

    #[Map(source: '[detail][remainingQuantity?]', if: new SourceHasPath('detail.remainingQuantity'))]
    public mixed $remainingQuantity;

    #[Map(source: '[id]')]
    public mixed $uuid;

    #[Map(source: '[author?]', transform: [self::class, 'mapList'])]
    public array $author;

    #[Map(source: '[carePlan?]')]
    public mixed $carePlan;

    #[Map(source: '[outcomeReference?]', transform: [self::class, 'mapList'])]
    public array $outcomeReference;

    #[Map(source: '[outcomeCodeableConcept?]', transform: [self::class, 'mapList'])]
    public array $outcomeCodeableConcept;
    public function __construct(#[Map(if: false)] private readonly array $input = [])
    {
    }

    public static function fromSource(FormCollection $source): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($source, new self($source->all()));
    }

    public static function mapList(mixed $value): array
    {
        return $value ?? [];
    }
    /** Compatibility attributes consumed by the existing activity editor; never persisted as a resource. */
    public function toUiArray(): array
    {
        $fields = $this->mappedFields();
        $projection = [
            'productReference' => data_get($fields, 'productReference.identifier.value'),
            'productReferenceType' => data_get($fields, 'productReference.identifier.type.coding.0.code'),
            'productCodeableConcept' => data_get($fields, 'productCodeableConcept.coding.0.code'),
            'program' => data_get($fields, 'program.identifier.value'),
            'reasonCode' => data_get($fields, 'reasonCode.0.coding.0.code'),
            'goal' => array_map(static fn (array $concept): string => $concept['coding'][0]['code'], $fields['goal'] ?? []),
            'reasonReference' => array_map(static function (array $reference): string {
                $type = $reference['identifier']['type']['coding'][0]['code'];
                $type = match ($type) {
                    'condition' => 'Condition', 'observation' => 'Observation', 'diagnostic_report' => 'DiagnosticReport', default => $type,
                };

                return $type.'/'.$reference['identifier']['value'];
            }, $fields['reasonReference'] ?? []),
            'scheduledPeriodStart' => data_get($fields, 'scheduledPeriod.start'),
            'scheduledPeriodEnd' => data_get($fields, 'scheduledPeriod.end'),
            'statusReason' => data_get($fields, 'statusReason.coding.0.code'),
        ];
        foreach (['quantity', 'dailyAmount', 'remainingQuantity'] as $field) {
            $projection[$field] = data_get($fields, $field.'.value');
            $projection[$field.'System'] = data_get($fields, $field.'.system');
            $projection[$field.'Code'] = data_get($fields, $field.'.code');
        }

        return $projection;
    }

    public function toArray(): array
    {
        $fields = $this->mappedFields();
        $copy = ['kind', 'status', 'doNotPerform', 'description', 'reasonCode', 'goal', 'reasonReference', 'scheduledPeriod', 'scheduledTiming', 'scheduledString', 'remainingQuantityType', 'statusReason', 'productCodeableConcept'];
        $order = [...array_intersect(array_keys($this->input['detail']), $copy), 'uuid', 'author', 'carePlan', 'productReference', 'location', 'performer', 'program', 'quantity', 'dailyAmount', 'remainingQuantity', 'outcomeReference', 'outcomeCodeableConcept'];
        $result = [];
        foreach ($order as $field) {
            if (array_key_exists($field, $fields)) {
                $result[$field] = $fields[$field];
            }
        }

        return $result;
    }
}

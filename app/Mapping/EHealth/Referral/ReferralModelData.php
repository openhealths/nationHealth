<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use App\Mapping\Transforms\FallbackValue;
use ArrayObject;
use stdClass;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/**
 * Validated local form (ArrayObject) or eHealth JSON (stdClass) to repository fields.
 * Author and Identifier relationships are supplied by the caller, never imported here.
 */
abstract class ReferralModelData
{
    #[Map(source: 'id?', if: new SourceClass(stdClass::class), transform: new FallbackValue('uuid'))]
    public ?string $uuid = null;

    #[Map(source: 'status?', if: new SourceClass(stdClass::class))]
    public ?string $status = null;

    #[Map(source: 'request_number?', if: new SourceClass(stdClass::class), transform: new FallbackValue('requisition', 'requestNumber'))]
    public ?string $request_number = null;

    #[Map(source: '[started_at?]', if: new SourceClass(ArrayObject::class), transform: [self::class, 'date'])]
    #[Map(source: 'occurrence_period?[start?]', if: new SourceClass(stdClass::class), transform: [new FallbackValue('occurrencePeriod.start', 'started_at'), [self::class, 'date']])]
    public ?string $started_at = null;

    #[Map(source: '[ended_at?]', if: new SourceClass(ArrayObject::class), transform: [self::class, 'date'])]
    #[Map(source: 'occurrence_period?[end?]', if: new SourceClass(stdClass::class), transform: [new FallbackValue('occurrencePeriod.end', 'ended_at'), [self::class, 'date']])]
    public ?string $ended_at = null;

    #[Map(source: '[quantity?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'quantity?[value?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('quantityInteger'))]
    public int|float|string|null $quantity = null;

    #[Map(source: '[program_id?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'program?[identifier?][value?]', if: new SourceClass(stdClass::class))]
    public ?string $program_id = null;

    #[Map(source: '[intent?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'intent?', if: new SourceClass(stdClass::class))]
    public ?string $intent = null;

    #[Map(source: '[category?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'category?[coding?][0?][code?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('category.0.coding.0.code'))]
    public ?string $category = null;

    #[Map(source: '[priority?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'priority?', if: new SourceClass(stdClass::class))]
    public ?string $priority = null;

    #[Map(source: '[note?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'note?', if: new SourceClass(stdClass::class), transform: [self::class, 'noteText'])]
    public ?string $note = null;

    #[Map(source: '[patient_instruction?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'patient_instruction?', if: new SourceClass(stdClass::class))]
    public ?string $patient_instruction = null;

    #[Map(source: '[inform_with?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'inform_with?', if: new SourceClass(stdClass::class))]
    public mixed $inform_with = null;

    #[Map(source: '[supporting_info?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'supporting_info?', if: new SourceClass(stdClass::class), transform: [new FallbackValue('supportingInfo'), [self::class, 'completeReferenceSources'], new MapCollection(targetClass: ReferralReferenceData::class)])]
    public ?array $supporting_info = null;

    #[Map(source: '[reason_reference?]', if: new SourceClass(ArrayObject::class))]
    public ?array $reason_reference = null;

    public function toArray(): array
    {
        $data = get_object_vars($this);
        foreach (['supporting_info', 'reason_reference'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = array_map(static fn (ReferralReferenceData|array $row): array => is_array($row) ? $row : get_object_vars($row), $data[$field]);
            }
        }

        return $data;
    }

    /** Preserve the current import contract: null/empty remote values do not clear local fields. */
    public function toSyncPatch(): array
    {
        $data = $this->toArray();
        foreach (['supporting_info', 'reason_reference'] as $field) {
            if ($data[$field] === []) {
                unset($data[$field]);
            }
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public static function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : convertToYmd($value);
    }

    public static function noteText(mixed $value): ?string
    {
        return is_string($value) ? $value : data_get($value, '0.text');
    }

    /** Nested JSON remains arrays; only collection rows require object adaptation. */
    public static function referenceSources(mixed $value): array
    {
        return array_map(static fn (array $row): stdClass => (object) $row, array_values(array_filter(is_array($value) ? $value : [], is_array(...))));
    }

    public static function completeReferenceSources(mixed $value): array
    {
        return array_values(array_filter(self::referenceSources($value), static fn (stdClass $row): bool => (bool) (data_get($row, 'identifier.value') && data_get($row, 'identifier.type.coding.0.code'))));
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Referral;

use App\Mapping\Transforms\FallbackValue;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestUse;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestSearch;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Dto\FormCollection;
use stdClass;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/**
 * Validated local form (FormCollection) or eHealth JSON (stdClass) to repository fields.
 * Author is supplied by the caller; repositories resolve mapped Identifier values.
 */
abstract class Model
{
    #[Map(source: 'id?', if: new SourceClass(stdClass::class), transform: new FallbackValue('uuid'))]
    #[Map(source: '[uuid]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    #[Map(source: '[uuid?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('id'))]
    public ?string $uuid = null;

    #[Map(source: 'status?', if: new SourceClass(stdClass::class))]
    #[Map(source: '[status?]', if: new SourceClass(ServiceRequestSearch::class))]
    public ?string $status = null;

    #[Map(source: 'request_number?', if: new SourceClass(stdClass::class), transform: new FallbackValue('requisition', 'requestNumber'))]
    #[Map(source: '[requisition?]', if: new SourceClass(ServiceRequestUse::class))]
    #[Map(source: '[requisition?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('requestNumber', 'request_number'))]
    public ?string $request_number = null;

    #[Map(source: '[started_at?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'date'])]
    #[Map(source: 'occurrence_period?[start?]', if: new SourceClass(stdClass::class), transform: [new FallbackValue('occurrencePeriod.start', 'started_at'), [self::class, 'date']])]
    #[Map(source: '[started_at?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]), transform: [self::class, 'date'])]
    #[Map(source: '[occurrencePeriod?][start?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('started_at'))]
    public ?string $started_at = null;

    #[Map(source: '[ended_at?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'date'])]
    #[Map(source: 'occurrence_period?[end?]', if: new SourceClass(stdClass::class), transform: [new FallbackValue('occurrencePeriod.end', 'ended_at'), [self::class, 'date']])]
    #[Map(source: '[ended_at?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]), transform: [self::class, 'date'])]
    #[Map(source: '[occurrencePeriod?][end?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('ended_at'))]
    public ?string $ended_at = null;

    #[Map(source: '[quantity?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'quantity?[value?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('quantityInteger'))]
    #[Map(source: '[quantity?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    #[Map(source: '[quantity?][value?]', if: new SourceClass(ServiceRequestUse::class), transform: [self::class, 'mapUseQuantity'])]
    #[Map(source: '[quantity?][value?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('quantityInteger'))]
    public int|float|string|null $quantity = null;

    #[Map(source: '[program_id?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'program?[identifier?][value?]', if: new SourceClass(stdClass::class))]
    #[Map(source: '[program_id?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    #[Map(source: '[program?][identifier?][value?]', if: new SourceClass(ServiceRequestUse::class), transform: new FallbackValue('program.id'))]
    #[Map(source: '[program?][identifier?][value?]', if: new SourceClass(ServiceRequestSearch::class))]
    public ?string $program_id = null;

    #[Map(source: '[intent?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'intent?', if: new SourceClass(stdClass::class))]
    #[Map(source: '[intent?][code?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    #[Map(source: '[intent?]', if: new SourceClass(ServiceRequestUse::class), transform: [self::class, 'mapUseIntent'])]
    #[Map(source: '[intent?]', if: new SourceClass(ServiceRequestSearch::class))]
    public ?string $intent = null;

    #[Map(source: '[category?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'category?[coding?][0?][code?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('category.0.coding.0.code'))]
    #[Map(source: '[category?][text?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    #[Map(source: '[category?][coding?][0?][code?]', if: new SourceClass(ServiceRequestUse::class))]
    #[Map(source: '[category?][0?][coding?][0?][code?]', if: new SourceClass(ServiceRequestSearch::class))]
    public ?string $category = null;

    #[Map(source: '[priority?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'priority?', if: new SourceClass(stdClass::class))]
    #[Map(source: '[priority?][text?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    #[Map(source: '[priority?]', if: new SourceClass(ServiceRequestSearch::class))]
    public ?string $priority = null;

    #[Map(source: '[note?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'note?', if: new SourceClass(stdClass::class), transform: [self::class, 'noteText'])]
    #[Map(source: '[note?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    #[Map(source: '[note?]', if: new SourceClass(ServiceRequestSearch::class), transform: [self::class, 'firstNoteText'])]
    public ?string $note = null;

    #[Map(source: '[patient_instruction?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'patient_instruction?', if: new SourceClass(stdClass::class))]
    public ?string $patient_instruction = null;

    #[Map(source: '[inform_with?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'inform_with?', if: new SourceClass(stdClass::class))]
    public mixed $inform_with = null;

    #[Map(source: '[supporting_info?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'supporting_info?', if: new SourceClass(stdClass::class), transform: [new FallbackValue('supportingInfo'), [self::class, 'completeReferenceSources'], new MapCollection(targetClass: Reference::class)])]
    #[Map(source: '[supporting_info?]', if: new SourceClass([ServiceRequestRequest::class, DeviceRequestRequest::class]))]
    public ?array $supporting_info = null;

    #[Map(source: '[reason_reference?]', if: new SourceClass(FormCollection::class))]
    public ?array $reason_reference = null;

    public function toArray(): array
    {
        $data = get_object_vars($this);
        foreach (['supporting_info', 'reason_reference'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = array_map(static fn (Reference|array $row): array => is_array($row) ? $row : get_object_vars($row), $data[$field]);
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
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === null || $value === '' ? null : convertToYmd($value);
    }

    public static function mapUseQuantity(mixed $value): int|float|string
    {
        return $value ?? 1;
    }

    public static function mapUseIntent(mixed $value): string
    {
        return $value ?? 'order';
    }

    public static function noteText(mixed $value): ?string
    {
        return is_string($value) ? $value : data_get($value, '0.text');
    }

    public static function firstNoteText(mixed $value): ?string
    {
        return data_get($value, '0.text');
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

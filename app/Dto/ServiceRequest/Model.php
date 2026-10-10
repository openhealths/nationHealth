<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestUse;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestSearch;

use App\Dto\Referral\Model as ReferralModelData;
use App\Dto\Referral\Reference as ReferralReferenceData;

use App\Mapping\Transforms\FallbackValue;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Dto\FormCollection;
use stdClass;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: stdClass::class, if: new SourceClass(stdClass::class))]
#[Map(source: ServiceRequestRequest::class, if: new SourceClass(ServiceRequestRequest::class))]
#[Map(source: ServiceRequestUse::class, if: new SourceClass(ServiceRequestUse::class))]
#[Map(source: ServiceRequestSearch::class, if: new SourceClass(ServiceRequestSearch::class))]
final class Model extends ReferralModelData
{
    #[Map(source: '[service_id?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'code?[identifier?][value?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('code.coding.0.code', 'service.id'))]
    #[Map(source: '[service_id?]', if: new SourceClass(ServiceRequestRequest::class))]
    #[Map(source: '[code?][identifier?][value?]', if: new SourceClass(ServiceRequestUse::class), transform: [new FallbackValue('code.coding.0.code'), [self::class, 'mapUseProduct']])]
    #[Map(source: '[code?][identifier?][value?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('code.coding.0.code', 'service.id'))]
    public ?string $service_id = null;

    #[Map(source: '[patient_instruction?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'patientInstruction?', if: new SourceClass(stdClass::class), transform: new FallbackValue('patient_instruction'))]
    #[Map(source: '[patient_instruction?]', if: new SourceClass(ServiceRequestRequest::class))]
    #[Map(source: '[patientInstruction?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('patient_instruction'))]
    public ?string $patient_instruction = null;

    #[Map(source: '[inform_with?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'informWith?', if: new SourceClass(stdClass::class), transform: new FallbackValue('inform_with'))]
    #[Map(source: '[inform_with?]', if: new SourceClass(ServiceRequestRequest::class))]
    #[Map(source: '[informWith?]', if: new SourceClass(ServiceRequestSearch::class), transform: new FallbackValue('inform_with'))]
    public mixed $inform_with = null;

    #[Map(source: '[reason_reference?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'reasonReference?', if: new SourceClass(stdClass::class), transform: [new FallbackValue('reason_reference'), [self::class, 'referenceSources'], new MapCollection(targetClass: ReferralReferenceData::class)])]
    #[Map(source: '[reason_reference?]', if: new SourceClass(ServiceRequestRequest::class))]
    #[Map(source: '[reasonReference?]', if: new SourceClass(ServiceRequestSearch::class), transform: [new FallbackValue('reason_reference'), [self::class, 'externalReasonSources'], new MapCollection(targetClass: ReferralReferenceData::class)])]
    public ?array $reason_reference = null;

    #[Map(source: '[supporting_info?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'supporting_info?', if: new SourceClass(stdClass::class), transform: [new FallbackValue('supportingInfo'), [self::class, 'completeReferenceSources'], new MapCollection(targetClass: ReferralReferenceData::class)])]
    #[Map(source: '[supporting_info?]', if: new SourceClass(ServiceRequestRequest::class))]
    #[Map(source: '[supportingInfo?]', if: new SourceClass(ServiceRequestSearch::class), transform: [[self::class, 'externalSupportingSources'], new MapCollection(targetClass: ReferralReferenceData::class)])]
    public ?array $supporting_info = null;

    #[Map(source: '[basedOn?][0?][identifier?][value?]', if: new SourceClass(ServiceRequestSearch::class))]
    public ?string $based_on_uuid = null;

    #[Map(source: '[context?][identifier?][value?]', if: new SourceClass(ServiceRequestSearch::class))]
    public ?string $context_uuid = null;

    /** Full search import preserves nulls and incomplete references; it must not use the sync patch policy. */
    public function toExternalRecord(): array
    {
        $data = [...$this->toArray(), 'based_on_uuid' => $this->based_on_uuid, 'context_uuid' => $this->context_uuid];
        $fields = ['uuid', 'status', 'request_number', 'started_at', 'ended_at', 'service_id', 'quantity',
            'program_id', 'intent', 'category', 'based_on_uuid', 'context_uuid', 'priority', 'note',
            'patient_instruction', 'reason_reference', 'inform_with', 'supporting_info'];

        return array_replace(array_fill_keys($fields, null), array_intersect_key($data, array_flip($fields)));
    }

    /** Identifier relationships are imported only through the complete search contract. */
    public function toArray(): array
    {
        $data = parent::toArray();
        unset($data['based_on_uuid'], $data['context_uuid']);

        return $data;
    }

    /** Preserve incomplete rows while excluding local uuid/type aliases from this API contract. */
    public static function externalSupportingSources(mixed $value): array
    {
        return array_map(static fn (mixed $row): stdClass => (object) ['identifier' => data_get($row, 'identifier')], array_values(is_array($value) ? $value : []));
    }

    public static function externalReasonSources(mixed $value): array
    {
        return self::externalSupportingSources(array_filter(is_array($value) ? $value : [], is_array(...)));
    }

    /** The use response creates only the minimum executor record; GET sync keeps its own policy. */
    public function toUseRecord(): array
    {
        return array_intersect_key($this->toArray(), array_flip(['request_number', 'program_id', 'service_id', 'quantity', 'category', 'intent']));
    }

    public static function mapUseProduct(mixed $value): string
    {
        return $value ?? '';
    }

}

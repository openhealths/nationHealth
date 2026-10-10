<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\Shared\EhealthReference as EHealthReference;
use App\Mapping\Transforms\AuthMethodId;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\IsNotNull;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

#[Map(source: Input::class)]
class Ehealth
{
    use PreservesEhealthDocumentValues;

    private const array FIELD_ORDER = [
        'status', 'intent', 'priority', 'code', 'requester_employee', 'requester_legal_entity',
        'based_on', 'context', 'category', 'quantity', 'occurrence_period', 'supporting_info',
        'reason_reference', 'patient_instruction', 'inform_with', 'id', 'program',
    ];

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        // Inherited properties must keep the byte order of the established KEP document.
        return array_replace(array_intersect_key(array_fill_keys(self::FIELD_ORDER, null), $data), $data);
    }

    public string $status = 'active';
    public string $intent;
    public string $priority;

    #[Map(source: 'serviceId', transform: new FhirReference('service'))]
    public array $code;

    #[Map(source: 'employeeUuid', transform: new FhirReference('employee'))]
    public array $requester_employee;

    #[Map(source: 'legalEntityUuid', transform: new FhirReference('legal_entity'))]
    public array $requester_legal_entity;

    #[Map(source: 'basedOn', if: 'count', transform: new MapCollection(targetClass: EHealthReference::class))]
    public ?array $based_on = null;

    #[Map(transform: new FhirReference())]
    public ?array $context = null;

    #[Map(transform: new FhirCodeableConcept('eHealth/SNOMED/service_request_categories'))]
    public ?array $category = null;

    #[Map(transform: [self::class, 'mapQuantity'])]
    public ?array $quantity = null;

    #[Map(source: 'mappedAt', transform: [self::class, 'mapOccurrence'])]
    public ?array $occurrence_period = null;

    #[Map(source: 'supportingInfo', if: new IsNotNull(), transform: new MapCollection(targetClass: EHealthReference::class))]
    public ?array $supporting_info = null;

    #[Map(source: 'reasonReference', if: new IsNotNull(), transform: new MapCollection(targetClass: EHealthReference::class))]
    public ?array $reason_reference = null;

    #[Map(source: 'patientInstruction')]
    public ?string $patient_instruction = null;

    #[Map(source: 'informWith', transform: [new AuthMethodId(), [self::class, 'mapInformWith']])]
    public ?array $inform_with = null;

    public static function mapQuantity(?float $value, Input $source): ?array
    {
        return $value === null ? null : ['value' => $value, 'system' => $source->quantitySystem, 'code' => $source->quantityCode];
    }

    public static function mapInformWith(?string $value): ?array
    {
        return $value === null ? null : ['auth_method_id' => $value];
    }

    public static function mapOccurrence(CarbonImmutable $now, Input $source): ?array
    {
        if ($source->startedAt === null && $source->endedAt === null) {
            return null;
        }

        $minStart = $now->addHour();
        $start = $source->startedAt !== null ? CarbonImmutable::parse($source->startedAt, $now->getTimezone()) : $minStart;
        if ($start->lessThan($minStart)) {
            $start = $minStart;
        }

        $end = $source->endedAt !== null ? CarbonImmutable::parse($source->endedAt, $now->getTimezone()) : $start->addMonths(3);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addDay();
        }

        return [
            'start' => $start->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'end' => $end->endOfDay()->utc()->format('Y-m-d\TH:i:s.000\Z'),
        ];
    }
}

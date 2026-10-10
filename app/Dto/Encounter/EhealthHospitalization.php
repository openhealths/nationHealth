<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthHospitalization
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[preAdmissionIdentifier?]', transform: [self::class, 'optionalValue'])]
    public mixed $preAdmissionIdentifier;

    #[Map(source: '[admitSource?]', transform: [self::class, 'admitSourceValue'])]
    public ?array $admitSource;

    #[Map(source: '[reAdmission?]', transform: [self::class, 'reAdmissionValue'])]
    public ?array $reAdmission;

    #[Map(source: '[destination?]', transform: [self::class, 'destinationValue'])]
    public ?array $destination;

    #[Map(source: '[dischargeDisposition?]', transform: [self::class, 'dischargeDispositionValue'])]
    public ?array $dischargeDisposition;

    #[Map(source: '[dischargeDepartment?]', transform: [self::class, 'dischargeDepartmentValue'])]
    public ?array $dischargeDepartment;

    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }

    public static function admitSourceValue(mixed $value, Collection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/encounter_admit_source', includeText: true)($value, $source, null);
    }

    public static function reAdmissionValue(mixed $value, Collection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/encounter_re_admission', includeText: true)($value, $source, null);
    }

    public static function destinationValue(mixed $value, Collection $source): ?array
    {
        return empty($value) ? null : new FhirReference('legal_entity', includeText: true)($value, $source, null);
    }

    public static function dischargeDispositionValue(mixed $value, Collection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/encounter_discharge_disposition', includeText: true)($value, $source, null);
    }

    public static function dischargeDepartmentValue(mixed $value, Collection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/encounter_discharge_department', includeText: true)($value, $source, null);
    }
}

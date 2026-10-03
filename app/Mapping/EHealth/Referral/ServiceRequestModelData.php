<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use App\Mapping\Transforms\FallbackValue;
use ArrayObject;
use stdClass;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

final class ServiceRequestModelData extends ReferralModelData
{
    #[Map(source: '[service_id?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'code?[identifier?][value?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('code.coding.0.code', 'service.id'))]
    public ?string $service_id = null;

    #[Map(source: '[patient_instruction?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'patientInstruction?', if: new SourceClass(stdClass::class), transform: new FallbackValue('patient_instruction'))]
    public ?string $patient_instruction = null;

    #[Map(source: '[inform_with?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'informWith?', if: new SourceClass(stdClass::class), transform: new FallbackValue('inform_with'))]
    public mixed $inform_with = null;

    #[Map(source: '[reason_reference?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'reasonReference?', if: new SourceClass(stdClass::class), transform: [new FallbackValue('reason_reference'), [self::class, 'referenceSources'], new MapCollection(targetClass: ReferralReferenceData::class)])]
    public ?array $reason_reference = null;

}

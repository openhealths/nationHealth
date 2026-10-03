<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use App\Mapping\Transforms\FallbackValue;
use ArrayObject;
use stdClass;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

final class DeviceRequestModelData extends ReferralModelData
{
    #[Map(source: '[device_id?]', if: new SourceClass(ArrayObject::class))]
    #[Map(source: 'code_reference?[identifier?][value?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('code.coding.0.code', 'codeCodeableConcept.coding.0.code'))]
    public ?string $device_id = null;
}

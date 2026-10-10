<?php

declare(strict_types=1);

namespace App\Dto\DeviceRequest;

use App\Dto\Referral\Model as ReferralModelData;

use App\Mapping\Transforms\FallbackValue;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Dto\FormCollection;
use stdClass;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: stdClass::class, if: new SourceClass(stdClass::class))]
#[Map(source: DeviceRequestRequest::class, if: new SourceClass(DeviceRequestRequest::class))]
final class Model extends ReferralModelData
{
    #[Map(source: '[device_id?]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'code_reference?[identifier?][value?]', if: new SourceClass(stdClass::class), transform: new FallbackValue('code.coding.0.code', 'codeCodeableConcept.coding.0.code'))]
    #[Map(source: '[device_id?]', if: new SourceClass(DeviceRequestRequest::class))]
    public ?string $device_id = null;
}

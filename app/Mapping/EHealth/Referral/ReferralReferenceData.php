<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use App\Mapping\Transforms\FallbackValue;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** A local reference row shared by validated form rows and eHealth identifiers. */
final class ReferralReferenceData
{
    #[Map(source: 'uuid?', transform: new FallbackValue('identifier.value'))]
    public ?string $uuid = null;

    #[Map(source: 'type?', transform: new FallbackValue('identifier.type.coding.0.code'))]
    public ?string $type = null;
}

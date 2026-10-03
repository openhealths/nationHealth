<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use App\Mapping\EHealth\Shared\EHealthReference;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

final class EHealthServiceRequestPrequalify
{
    #[Map(source: 'request', transform: MapServiceRequestBody::class)]
    public EHealthServiceRequestBody $service_request;

    #[Map(if: 'count', transform: new MapCollection(targetClass: EHealthReference::class))]
    public ?array $programs = null;
}

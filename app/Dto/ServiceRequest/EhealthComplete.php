<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Dto\Shared\EhealthReference as EHealthReference;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class EhealthComplete
{
    #[Map(source: 'basedOn', transform: new MapCollection(targetClass: EHealthReference::class))]
    public array $based_on;

    public function toArray(): array
    {
        return (new Serializer([new ObjectNormalizer()]))->normalize($this);
    }
}

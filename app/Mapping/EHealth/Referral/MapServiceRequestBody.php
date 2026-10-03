<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use Symfony\Component\ObjectMapper\ObjectMapperAwareInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

final class MapServiceRequestBody implements TransformCallableInterface, ObjectMapperAwareInterface
{
    private ObjectMapperInterface $mapper;

    public function withObjectMapper(ObjectMapperInterface $objectMapper): static
    {
        $clone = clone $this;
        $clone->mapper = $objectMapper;

        return $clone;
    }

    public function __invoke(mixed $value, object $source, ?object $target): EHealthServiceRequestBody
    {
        return $this->mapper->map($value, EHealthServiceRequestBody::class);
    }
}

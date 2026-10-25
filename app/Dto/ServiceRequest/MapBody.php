<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use Symfony\Component\ObjectMapper\ObjectMapperAwareInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

final class MapBody implements TransformCallableInterface, ObjectMapperAwareInterface
{
    private ObjectMapperInterface $mapper;

    public function withObjectMapper(ObjectMapperInterface $objectMapper): static
    {
        $clone = clone $this;
        $clone->mapper = $objectMapper;

        return $clone;
    }

    public function __invoke(mixed $value, object $source, ?object $target): Ehealth
    {
        return $this->mapper->map($source, Ehealth::class);
    }
}

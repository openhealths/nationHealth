<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\ObjectMapperAwareInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Maps a nested object with the same configured mapper as its parent. */
final class MapObject implements TransformCallableInterface, ObjectMapperAwareInterface
{
    private ObjectMapperInterface $objectMapper;

    /** @param class-string $targetClass */
    public function __construct(private readonly string $targetClass)
    {
    }

    public function withObjectMapper(ObjectMapperInterface $objectMapper): static
    {
        $clone = clone $this;
        $clone->objectMapper = $objectMapper;

        return $clone;
    }

    public function __invoke(mixed $value, object $source, ?object $target): ?object
    {
        return $value === null ? null : $this->objectMapper->map($value, $this->targetClass);
    }
}

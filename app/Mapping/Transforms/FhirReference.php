<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

final class FhirReference implements TransformCallableInterface
{
    public function __construct(private readonly ?string $resourceType = null)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            $source = $value;
            $value = $value->uuid;
        }

        return ['identifier' => new FhirIdentifier($this->resourceType)($value, $source, $target)];
    }
}

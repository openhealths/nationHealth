<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

final class FhirCodeableConcept implements TransformCallableInterface
{
    public function __construct(private readonly string $system)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): ?array
    {
        return $value === null ? null : ['coding' => [['system' => $this->system, 'code' => $value]]];
    }
}

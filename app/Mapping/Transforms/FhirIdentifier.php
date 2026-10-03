<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

final class FhirIdentifier implements TransformCallableInterface
{
    public function __construct(private readonly ?string $resourceType = null)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): array
    {
        return [
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $this->resourceType ?? $source->type]]],
            'value' => $value,
        ];
    }
}

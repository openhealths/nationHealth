<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

use App\Enums\ResourceType;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Apply the existing outbound Reference transform to every selected resource UUID. */
class References implements TransformCallableInterface
{
    public function __construct(protected ResourceType $resourceType)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): array
    {
        $reference = new Reference($this->resourceType);

        return array_map(
            static fn (string|array $item): array => is_array($item) ? $item : $reference($item, $source, $target),
            $value
        );
    }
}

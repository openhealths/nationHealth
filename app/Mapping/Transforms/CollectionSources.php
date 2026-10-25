<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** ObjectMapper maps objects; retain list keys and nested native arrays. */
final class CollectionSources implements TransformCallableInterface
{
    public function __invoke(mixed $value, object $source, ?object $target): array
    {
        return array_map(static fn (array $row): FormCollection => new FormCollection($row), $value);
    }
}

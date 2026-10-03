<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Read documented aliases only when the primary field is null; retain zero and empty values. */
final readonly class FallbackValue implements TransformCallableInterface
{
    private array $paths;

    public function __construct(string ...$paths)
    {
        $this->paths = $paths;
    }

    public function __invoke(mixed $value, object $source, ?object $target): mixed
    {
        foreach ($this->paths as $path) {
            $value ??= data_get($source, $path);
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Mapping\Conditions;

use Symfony\Component\ObjectMapper\ConditionCallableInterface;

final readonly class SourceHasAnyPath implements ConditionCallableInterface
{
    public function __construct(private array $paths)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): bool
    {
        foreach ($this->paths as $path) {
            if (new SourceHasPath($path)($value, $source, $target)) {
                return true;
            }
        }

        return false;
    }
}

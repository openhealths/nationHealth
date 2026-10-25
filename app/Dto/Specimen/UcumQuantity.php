<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** The old specimen contract casts UCUM quantities to floats. */
final class UcumQuantity implements TransformCallableInterface
{
    public function __construct(private readonly string $codeField)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): array
    {
        return ['value' => (float) $value, 'system' => 'eHealth/ucum/units', 'code' => $source[$this->codeField]];
    }
}

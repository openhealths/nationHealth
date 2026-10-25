<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Read the last typed value for a native IL extension code, preserving an explicit false. */
final readonly class ExtensionValue implements TransformCallableInterface
{
    public function __construct(private string $code)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): mixed
    {
        $result = null;
        foreach ($value ?? [] as $extension) {
            if (is_array($extension) && ($extension['valueCode'] ?? null) === $this->code) {
                $result = $extension['valueBoolean'] ?? $extension['valueString'] ?? $extension['valueDate'] ?? null;
            }
        }

        return $result;
    }
}

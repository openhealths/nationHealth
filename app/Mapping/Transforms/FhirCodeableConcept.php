<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

final class FhirCodeableConcept implements TransformCallableInterface
{
    public function __construct(private readonly string $system, private readonly bool $includeText = false)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): ?array
    {
        if ($value === null) {
            return null;
        }

        $concept = ['coding' => [['system' => $this->system, 'code' => $value]]];
        if ($this->includeText) {
            $concept['text'] = '';
        }

        return $concept;
    }
}

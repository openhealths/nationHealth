<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

use App\Classes\eHealth\Api\Responses\Collections\EhealthCodeableConcept;
use App\Classes\eHealth\Api\Responses\Collections\EhealthCoding;
use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Convert an editor's dictionary code to the shared outbound CodeableConcept DTO. */
class Concept implements TransformCallableInterface
{
    public function __construct(protected string $system)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): ?CodeableConceptEhealth
    {
        if ($value instanceof EhealthCodeableConcept) {
            $concept = $value;
            $value = $concept->toArray();
            $value['coding'] = array_map(
                static fn (EhealthCoding $coding): array => $coding->toArray(),
                $concept->get('coding', [])
            );
        }

        return CodeableConceptEhealth::map(is_array($value)
            ? $value
            : ['coding' => [['system' => $this->system, 'code' => $value]]]);
    }
}

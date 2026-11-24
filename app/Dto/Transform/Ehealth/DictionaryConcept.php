<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * Codeable concept of a dictionary code for #[Map], used as `transform: new DictionaryConcept('eHealth/...')`.
 * Builds the concept from the code held by the form, with the dictionary description of the code as its text.
 *
 * @implements TransformCallableInterface<object, object>
 */
class DictionaryConcept implements TransformCallableInterface
{
    public function __construct(protected string $dictionary)
    {
    }

    /**
     * @param  mixed  $value  Code of the dictionary
     * @param  object  $source
     * @param  object|null  $target
     * @return CodeableConceptEhealth|null
     */
    public function __invoke(mixed $value, object $source, ?object $target): ?CodeableConceptEhealth
    {
        return CodeableConceptEhealth::map([
            'coding' => [['system' => $this->dictionary, 'code' => $value]],
            'text' => dictionary()->basics()->byName($this->dictionary)->asCodeDescription()->get($value)
        ]);
    }
}

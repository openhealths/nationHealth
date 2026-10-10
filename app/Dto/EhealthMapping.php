<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Converts a DTO into the eHealth request body: snake_case keys, empty values removed.
 */
trait EhealthMapping
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $converter = new CamelCaseToSnakeCaseNameConverter();

        $data = new Serializer([new ObjectNormalizer(nameConverter: $converter)])
            ->normalize($this, context: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);

        return $this->normalizeMappedData($data, $converter);
    }

    /** Contract-specific wire rules; Division retains the default empty-value and key conversion policy. */
    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $kept = array_intersect_key($data, array_flip($this->keepWhenSet()));
        $others = removeEmptyKeys(array_diff_key($data, $kept));

        return $this->snakeCaseKeys($others + $kept, $converter);
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    protected function snakeCaseKeys(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $snakeKey = is_string($key) ? $converter->normalize($key) : $key;

            $result[$snakeKey] = is_array($value) ? $this->snakeCaseKeys($value, $converter) : $value;
        }

        return $result;
    }

    /**
     * Snake_case names of the properties which stay in the request even when they hold "empty" values (0, []).
     *
     * @return list<string>
     */
    protected function keepWhenSet(): array
    {
        return [];
    }
}

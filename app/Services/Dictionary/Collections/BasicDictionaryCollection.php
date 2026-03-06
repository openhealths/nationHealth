<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Collections;

use Illuminate\Support\Collection;
use InvalidArgumentException;

class BasicDictionaryCollection extends Collection
{
    /**
     * Get dictionary values by dictionary name.
     *
     * Searches for a specific dictionary by name and returns its values
     * as a new BasicDictionaryCollection instance.
     *
     * @param  string  $name  Dictionary name to search for
     * @return self Collection containing dictionary values
     * @throws InvalidArgumentException When dictionary name is not found
     */
    public function byName(string $name): self
    {
        $dictionary = $this->firstWhere('name', $name);

        if (!$dictionary) {
            throw new InvalidArgumentException("Dictionary '{$name}' not found");
        }

        return new self($dictionary['values'] ?? []);
    }

    /**
     * Get multiple dictionaries by names with code => description mapping.
     *
     * Retrieves multiple dictionaries and formats them as code-description
     * pairs, filtering out empty dictionaries.
     *
     * @param  array<string>  $names  Array of dictionary names to retrieve
     * @return Collection<string, Collection> Mapped collection of dictionaries
     */
    public function getMultipleFormatted(array $names): Collection
    {
        return collect($names)
            ->mapWithKeys(fn (string $name) => [
                $name => $this->byName($name)->asCodeDescription()
            ])
            ->filter(fn ($dictionary) => $dictionary->isNotEmpty());
    }

    /**
     * Get simple code => description mapping from complex structure.
     *
     * @return Collection<string, string> Simple code-description mapping
     */
    public function asCodeDescription(): Collection
    {
        return $this->filter(fn (array $item) => isset($item['code'], $item['description']))
            ->mapWithKeys(fn (array $item) => [
                $item['code'] => $item['description']
            ]);
    }

    /**
     * Format as large dictionary with extended data structure.
     *
     * @return Collection<string, array> Extended dictionary with metadata
     */
    public function asLargeDictionary(): Collection
    {
        return $this->filter(fn (array $value) => isset($value['code'], $value['description']))
            ->mapWithKeys(fn (array $value) => [
                $value['code'] => [
                    'description' => $value['description'],
                    'is_active' => $value['is_active'] ?? true,
                    'child_values' => $value['child_values'] ?? []
                ]
            ]);
    }

    /**
     * Get flattened values with child values recursively processed.
     *
     * Recursively processes dictionary items and their child values,
     * creating a flat collection of all codes and descriptions including
     * nested child elements.
     *
     * @return Collection<string, string> Flattened code-description pairs
     */
    public function flattenedChildValues(): Collection
    {
        return $this->flatMap(function ($item) {
            if (!is_array($item)) {
                return collect();
            }

            $collectDescriptions = static function (array $data) use (&$collectDescriptions): Collection {
                return collect($data)->flatMap(function ($value, $key) use ($collectDescriptions) {
                    $result = collect();

                    $code = is_string($key) ? $key : ($value['code'] ?? null);

                    if ($code && isset($value['description'])) {
                        $result->put($code, $value['description']);
                    }

                    if (!empty($value['child_values'])) {
                        $childValues = $collectDescriptions($value['child_values']);
                        $result = $result->merge($childValues);
                    }

                    return $result;
                });
            };

            return $collectDescriptions([$item]);
        });
    }
}

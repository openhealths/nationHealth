<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Collections;

use Illuminate\Support\Collection;
use InvalidArgumentException;

class BasicDictionaryCollection extends Collection
{
    /**
     * Get dictionary values by dictionary name.
     * Searches for a specific dictionary by name and returns its values.
     *
     * Pass false to keep the deactivated values as well. That is needed wherever an already stored code has to be resolved or validated,
     * because it may have been deactivated in eHealth after the record was created.
     *
     * @param  string  $name  Dictionary name to search for
     * @param  bool  $onlyActive  Keep only the values still offered for selection
     * @return self Collection containing dictionary values
     * @throws InvalidArgumentException When dictionary name is not found
     */
    public function byName(string $name, bool $onlyActive = true): self
    {
        $dictionary = $this->firstWhere('name', $name);

        if (!$dictionary) {
            throw new InvalidArgumentException("Dictionary '{$name}' not found");
        }

        $values = new self($dictionary['values'] ?? []);

        return $onlyActive ? $values->onlyActive() : $values;
    }

    /**
     * Get multiple dictionaries by names with code => description mapping.
     * Retrieves multiple dictionaries and formats them as code-description pairs, filtering out empty dictionaries.
     *
     * @param  array  $names  Array of dictionary names to retrieve
     * @return Collection
     */
    public function getMultipleFormatted(array $names): Collection
    {
        return collect($names)
            ->mapWithKeys(fn (string $name) => [
                $name => $this->byName($name)->asCodeDescription()
            ])
            ->filter(fn (Collection $dictionary) => $dictionary->isNotEmpty());
    }

    /**
     * Keep only the values that are still offered for selection, nested child values included.
     *
     * @return self
     */
    public function onlyActive(): self
    {
        return $this->filter(static fn (array $value): bool => $value['is_active'] ?? true)
            ->map(static function (array $value): array {
                if (!empty($value['child_values'])) {
                    $value['child_values'] = new self($value['child_values'])->onlyActive()->all();
                }

                return $value;
            });
    }

    /**
     * Get simple code => description mapping from complex structure.
     *
     * @return Collection
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
     * @return Collection
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
     * Recursively processes dictionary items and their child values,
     * creating a flat collection of all codes and descriptions including nested child elements.
     *
     * @param  bool  $onlyLeaf  Keep only values without child values
     * @return Collection
     */
    public function flattenedChildValues(bool $onlyLeaf = false): Collection
    {
        return $this->flatMap(function (mixed $item) use ($onlyLeaf) {
            if (!is_array($item)) {
                return collect();
            }

            $collectDescriptions = static function (array $data) use (&$collectDescriptions, $onlyLeaf): Collection {
                return collect($data)->flatMap(function (array $value, int|string $key) use ($collectDescriptions, $onlyLeaf) {
                    $result = collect();

                    $code = is_string($key) ? $key : ($value['code'] ?? null);
                    $hasChildren = !empty($value['child_values']);

                    if (
                        $code
                        && isset($value['description'])
                        && (!$onlyLeaf || !$hasChildren)
                    ) {
                        $result->put($code, $value['description']);
                    }

                    if ($hasChildren) {
                        $result = $result->merge($collectDescriptions($value['child_values']));
                    }

                    return $result;
                });
            };

            return $collectDescriptions([$item]);
        });
    }
}

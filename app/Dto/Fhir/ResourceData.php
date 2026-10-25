<?php

declare(strict_types=1);

namespace App\Dto\Fhir;

use App\Dto\FormCollection;
use App\Mapping\Transforms\CollectionSources;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Preserve untouched metadata and explicit null/false/zero without global name conversion. */
trait ResourceData
{
    #[Map(if: false)]
    private array $original = [];
    public static function mapSnapshot(mixed $value, FormCollection $source): self
    {
        $target = new self();
        $target->original = $source->all();

        return $target;
    }

    protected function mappedFields(): array
    {
        $fields = get_object_vars($this);
        unset($fields['original'], $fields['options'], $fields['input']);

        return $this->normalizeFields($fields);
    }

    protected static function mapRows(?array $rows, string $targetClass): ?array
    {
        if ($rows === null) {
            return null;
        }
        $source = new FormCollection($rows);
        $mapper = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor());

        return new MapCollection(objectMapper: $mapper, targetClass: $targetClass)(
            new CollectionSources()($rows, $source, null),
            $source,
            null,
        );
    }

    private function normalizeFields(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return is_array($value) ? array_map($this->normalizeFields(...), $value) : $value;
    }

    public function toArray(): array
    {
        return array_replace($this->original, $this->mappedFields());
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Fhir;

use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Serialize only mapped create fields, in the existing signing order. */
trait CreateData
{
    use ResourceData;
    public function __construct(#[Map(if: false)] private readonly array $input = [])
    {
    }

    public static function fromSource(FormCollection $source): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($source, new self($source->all()));
    }

    public function toArray(): array
    {
        $fields = $this->mappedFields();
        $result = [];
        $trailing = $this->trailingFields();
        foreach (array_keys($this->input) as $field) {
            if (array_key_exists($field, $fields) && !in_array($field, $trailing, true)) {
                $result[$field] = $fields[$field];
            }
        }
        foreach ($trailing as $field) {
            if (array_key_exists($field, $fields)) {
                $result[$field] = $fields[$field];
            }
        }

        return $result;
    }

    protected function trailingFields(): array
    {
        return ['intent', 'status'];
    }

    public static function mapReferences(mixed $value): ?array
    {
        return self::mapRows($value, Reference::class);
    }

    public static function mapConcepts(mixed $value): ?array
    {
        return self::mapRows($value, CodeableConcept::class);
    }
}

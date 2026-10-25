<?php

declare(strict_types=1);

namespace App\Dto\Fhir;

use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[Map(source: FormCollection::class, transform: [self::class, 'mapSnapshot'])]
final class Reference implements Arrayable
{
    use ResourceData;
    #[Map(source: '[identifier?]', if: new SourceHasPath('identifier'))]
    public mixed $identifier;

    #[Map(source: '[displayValue?]', if: new SourceHasPath('displayValue'))]
    public mixed $displayValue;
    public static function value(mixed $value, ?string $type, array $existing = [], bool $typedPath = false): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($typedPath && is_string($value) && str_contains($value, '/')) {
            [$type, $value] = explode('/', $value, 2);
            $type = match (strtolower($type)) {
                'diagnosticreport' => 'diagnostic_report',
                'clinicalimpression' => 'clinical_impression',
                default => strtolower($type),
            };
        }
        if ($type === null || !is_string($value) || $value === '') {
            throw new InvalidArgumentException($typedPath ? 'An activity reference requires its resource type and UUID.' : 'A reference requires a resource UUID.');
        }
        foreach ($existing as $reference) {
            if (data_get($reference, 'identifier.value') === $value && data_get($reference, 'identifier.type.coding.0.code') === $type) {
                return $reference;
            }
        }

        return ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]], 'text' => ''], 'value' => $value]];
    }

    public static function fromValue(mixed $value, ?string $type, array $existing = [], bool $typedPath = false): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map(new FormCollection(self::value($value, $type, $existing, $typedPath)), self::class);
    }
}

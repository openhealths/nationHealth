<?php

declare(strict_types=1);

namespace App\Dto\Fhir;

use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use App\Mapping\Transforms\CollectionSources;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[Map(source: FormCollection::class, transform: [self::class, 'mapSnapshot'])]
final class CodeableConcept implements Arrayable
{
    use ResourceData;
    /** @var list<Coding> */
    #[Map(source: '[coding?]', if: new SourceHasPath('coding'), transform: [new CollectionSources(), new MapCollection(targetClass: Coding::class)])]
    public array $coding;

    #[Map(source: '[text?]', if: new SourceHasPath('text'))]
    public mixed $text;
    public static function value(mixed $value, ?string $system): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if ($system === null) {
            throw new InvalidArgumentException('A status reason requires an explicit coding system.');
        }

        return ['coding' => [['system' => $system, 'code' => (string) $value]], 'text' => ''];
    }

    public static function fromValue(mixed $value, ?string $system): ?self
    {
        $data = self::value($value, $system);

        return $data === null ? null : new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map(new FormCollection($data), self::class);
    }
}

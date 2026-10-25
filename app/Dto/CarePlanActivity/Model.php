<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Core\Arr;
use App\Dto\Fhir\CodeableConcept;
use App\Dto\Fhir\Reference;
use App\Dto\Fhir\ResourceData;
use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Canonical Activity. API owns the signed-create allowlist and raw cancellation content. */
#[Map(source: FormCollection::class)]
final class Model implements Arrayable
{
    use ResourceData;
    #[Map(source: '[uuid?]', transform: [self::class, 'mapIdentity'])]
    public string $id;

    #[Map(source: '[author?]', if: new SourceHasPath('author'), transform: [self::class, 'mapAuthors'])]
    public array $author;

    #[Map(source: '[carePlan?]', if: new SourceHasPath('carePlan'), transform: [self::class, 'mapCarePlan'])]
    public Reference $carePlan;

    #[Map(source: '[outcomeReference?]', if: new SourceHasPath('outcomeReference'), transform: [self::class, 'mapOutcomes'])]
    public array $outcomeReference;

    #[Map(source: '[outcomeCodeableConcept?]', if: new SourceHasPath('outcomeCodeableConcept'), transform: [self::class, 'mapOutcomeConcepts'])]
    public array $outcomeCodeableConcept;

    #[Map(source: '[detail?]', transform: [self::class, 'mapDetail'])]
    public Detail $detail;
    public function __construct(#[Map(if: false)] private readonly array $options = [])
    {
        $this->original = $options['resource'] ?? [];
    }

    public static function fromSource(FormCollection $source, array $context = []): self
    {
        $data = Arr::toCamelCase($source->all());
        if (!array_key_exists('author', $data) && !isset($context['resource']['author']) && isset($context['employeeUuid'])) {
            $data['author'] = $context['employeeUuid'];
        }
        if (isset($context['carePlanUuid'])) {
            $data['carePlan'] = $data['carePlan'] ?? $context['carePlanUuid'];
        }

        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map(new FormCollection($data), new self($context));
    }

    public static function mapIdentity(mixed $value, FormCollection $source, self $target): string
    {
        $id = $value ?? $target->options['id'] ?? $target->original['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('Care Plan Activity mapping requires an allocated resource UUID.');
        }

        return $id;
    }

    public static function mapAuthors(mixed $value): array
    {
        $authors = is_array($value) && array_is_list($value) ? $value : [$value];

        return self::mapRows(array_map(static fn (mixed $author): array => Reference::value($author, 'employee', typedPath: true), $authors), Reference::class);
    }

    public static function mapCarePlan(mixed $value): Reference
    {
        return Reference::fromValue($value, 'care_plan', typedPath: true);
    }

    public static function mapOutcomes(mixed $value): array
    {
        return self::mapRows(array_map(static fn (mixed $reference): array => Reference::value($reference, null, typedPath: true), $value ?? []), Reference::class);
    }

    public static function mapOutcomeConcepts(mixed $value): array
    {
        return self::mapRows($value ?? [], CodeableConcept::class);
    }

    public static function mapDetail(mixed $value, FormCollection $source, self $target): Detail
    {
        return Detail::fromSource($source, $target->options);
    }
}

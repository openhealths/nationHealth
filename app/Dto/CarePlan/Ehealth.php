<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Core\Arr;
use App\Enums\CarePlanStatus;
use ArrayObject;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Fields permitted in the signed Create Care Plan content. */
#[Map(source: ArrayObject::class)]
class Ehealth
{
    #[Map(source: '[id]')]
    public string $id;

    #[Map(source: '[basedOn?]')]
    public ?array $basedOn = null;

    #[Map(source: '[partOf?]')]
    public ?array $partOf = null;

    #[Map(source: '[category]')]
    public array $category;

    #[Map(source: '[title]')]
    public string $title;

    #[Map(source: '[description?]')]
    public ?string $description = null;

    #[Map(source: '[period]')]
    public array $period;

    #[Map(source: '[supportingInfo?]')]
    public ?array $supportingInfo = null;

    #[Map(source: '[note?]')]
    public ?string $note = null;

    #[Map(source: '[encounter]')]
    public array $encounter;

    #[Map(source: '[addresses]')]
    public array $addresses;

    #[Map(source: '[author]')]
    public array $author;

    #[Map(source: '[contributor?]')]
    public ?array $contributor = null;

    #[Map(source: '[termsOfService]')]
    public array $termsOfService;

    #[Map(source: '[informWith?]')]
    public ?array $informWith = null;

    #[Map(if: false)]
    public string $intent = 'order';

    #[Map(if: false)]
    public string $status = CarePlanStatus::PENDING->value;

    public function toPayload(): array
    {
        return $this->omitEmptyFields(Arr::toSnakeCase(get_object_vars($this)));
    }

    /** Omit empty optional values recursively and keep JSON lists consecutively indexed. */
    protected function omitEmptyFields(array $data): array
    {
        $data = array_map(fn (mixed $value): mixed => is_array($value) ? $this->omitEmptyFields($value) : $value, $data);
        $filtered = array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return array_is_list($data) ? array_values($filtered) : $filtered;
    }
}

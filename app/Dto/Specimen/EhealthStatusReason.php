<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Enums\Specimen\StatusReasonType;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Dto\Concerns\PreservesEhealthDocumentValues;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthStatusReason
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[reason]', transform: [self::class, 'reason'])]
    public array $statusReason;

    public function __construct(#[Map(if: false)] private readonly StatusReasonType $reasonType)
    {
    }

    public static function reason(string $value, Collection $source, self $target): array
    {
        return new FhirCodeableConcept($target->reasonType->value, includeText: true)($value, $source, $target);
    }
}

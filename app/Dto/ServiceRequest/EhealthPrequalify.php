<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Dto\Shared\EhealthReference as EHealthReference;
use App\Dto\Concerns\PreservesEhealthDocumentValues;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

#[Map(source: Input::class)]
final class EhealthPrequalify
{
    use PreservesEhealthDocumentValues;

    #[Map(source: 'serviceId', transform: MapBody::class)]
    public Ehealth $service_request;

    #[Map(source: 'programId', transform: [[self::class, 'programSources'], new MapCollection(targetClass: EHealthReference::class)])]
    public array $programs;

    public static function programSources(?string $value): array
    {
        return $value === null ? [] : [(object) ['type' => 'medical_program', 'uuid' => $value]];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $data['service_request'] = $this->service_request->toArray();

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\MapObject;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

final class EhealthCreate
{
    use PreservesEhealthDocumentValues;
    #[Map(source: 'data', transform: [[self::class, 'sourceObject'], new MapObject(Ehealth::class)])]
    public Ehealth $medication_request_request;

    public static function sourceObject(mixed $value, object $source): object
    {
        return $source;
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        return ['medication_request_request' => $this->medication_request_request->toArray()];
    }
}

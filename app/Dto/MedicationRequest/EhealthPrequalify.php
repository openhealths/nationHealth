<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\MapObject;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

final class EhealthPrequalify
{
    use PreservesEhealthDocumentValues;
    #[Map(source: 'data', transform: [[self::class, 'sourceObject'], new MapObject(Ehealth::class)])]
    public Ehealth $medication_request_request;
    #[Map(source: 'data[medication_program_id?]', transform: [[self::class, 'programRows'], new MapCollection(targetClass: EhealthProgram::class)])]
    public array $programs;

    public static function sourceObject(mixed $value, object $source): object
    {
        return $source;
    }

    public static function programRows(mixed $value): array
    {
        return empty($value) ? [] : [(object) ['id' => $value]];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $request = $this->medication_request_request->toArray();
        unset($request['medical_program_id']);

        return ['medication_request_request' => $request, 'programs' => $data['programs']];
    }
}

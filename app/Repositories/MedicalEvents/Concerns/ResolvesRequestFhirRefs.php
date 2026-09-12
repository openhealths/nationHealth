<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents\Concerns;

use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Repositories\MedicalEvents\Repository;

trait ResolvesRequestFhirRefs
{
    private const REQUEST_INTENT_SYSTEM = 'http://hl7.org/fhir/request-intent';

    /**
     * Resolve FHIR intent/category/priority and basedOn/context Identifier FKs.
     * Prefer based_on_uuid / context_uuid from callers over legacy int ids.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     intent_id: int|null,
     *     category_id: int|null,
     *     priority_id: int|null,
     *     based_on_id: int|null,
     *     context_id: int|null
     * }
     */
    protected function resolveRequestFhirRefs(array $data): array
    {
        return [
            'intent_id' => !empty($data['intent'])
                ? Coding::firstOrCreate([
                    'code' => (string) $data['intent'],
                    'system' => self::REQUEST_INTENT_SYSTEM,
                ])->id
                : null,
            'category_id' => !empty($data['category'])
                ? CodeableConcept::firstOrCreate(['text' => (string) $data['category']])->id
                : null,
            'priority_id' => !empty($data['priority'])
                ? CodeableConcept::firstOrCreate(['text' => (string) $data['priority']])->id
                : null,
            'based_on_id' => !empty($data['based_on_uuid'])
                ? Repository::identifier()->store((string) $data['based_on_uuid'])->id
                : null,
            'context_id' => !empty($data['context_uuid'])
                ? Repository::identifier()->store((string) $data['context_uuid'])->id
                : null,
        ];
    }
}

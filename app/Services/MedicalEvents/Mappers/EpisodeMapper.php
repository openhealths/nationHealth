<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\EpisodeStatus;
use App\Services\MedicalEvents\FhirResource;

class EpisodeMapper implements FhirMapperContract
{
    /**
     * Build a FHIR episode structure ready for the repository or eHealth API.
     *
     * @param  array  $data  Flat episode form data
     * @param  mixed  ...$context  [0] array $uuids, [1] string $periodDate, [2] string $periodStart, [3] EpisodeStatus $status
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids, $periodDate, $periodStart, $status] = $context;

        return [
            'id' => $uuids['episode'],
            'type' => FhirResource::make()->coding('eHealth/episode_types', $data['typeCode'])->toCoding(),
            'name' => $data['name'],
            'status' => $status->value,
            'managingOrganization' => FhirResource::make()->coding('eHealth/resources', 'legal_entity')->toIdentifier(legalEntity()->uuid),
            'period' => [
                'start' => convertToEHealthISO8601($periodDate . ' ' . $periodStart)
            ],
            'careManager' => FhirResource::make()->coding('eHealth/resources', 'employee')->toIdentifier($uuids['employee'])
        ];
    }

    public function fromFhir(array $data, mixed ...$context): array
    {
        return [
            'id' => data_get($data, 'episode.identifier.value', '')
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

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

    /**
     * Build the flat episode form data out of a stored episode.
     *
     * @param  array  $data  Episode record with its type, care manager and period relations
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $periodStart = (string) data_get($data, 'period.start');

        return [
            'uuid' => data_get($data, 'uuid'),
            'name' => data_get($data, 'name'),
            'typeCode' => data_get($data, 'type.code', ''),
            'careManagerId' => data_get($data, 'careManager.identifier.value', ''),
            'startDate' => Str::before($periodStart, ' '),
            'startTime' => Str::after($periodStart, ' ')
        ];
    }
}

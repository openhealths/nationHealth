<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\DeviceAssociation\Status;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DeviceAssociationMapper implements FhirMapperContract
{
    /**
     * Convert the device associations of a package, dating the ones the package adds.
     *
     * @param  array  $deviceAssociations  Flat device association form data
     * @param  array  $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhirCollection(array $deviceAssociations, array $uuids): array
    {
        return collect($this->setRecorded($deviceAssociations))
            ->map(fn (array $deviceAssociation): array => $this->toFhir($deviceAssociation, $uuids))
            ->values()
            ->toArray();
    }

    /**
     * Set the moment an association being added is written at. A connection opened and closed within the same
     * package is written in that order, so there the association opening it is dated a minute before the one
     * closing it - a whole minute because a stored association is read back without its seconds, and the two
     * have to stay apart when the package is rebuilt.
     *
     * @param  array  $deviceAssociations  Flat device association form data
     * @return array
     */
    private function setRecorded(array $deviceAssociations): array
    {
        $now = CarbonImmutable::now();
        $associationsPerDevice = array_count_values(array_column($deviceAssociations, 'deviceId'));

        foreach ($deviceAssociations as $index => $deviceAssociation) {
            if (!empty($deviceAssociation['recorded'])) {
                continue;
            }

            $opensPair = ($associationsPerDevice[$deviceAssociation['deviceId']] ?? 1) > 1
                && in_array(
                    $deviceAssociation['status'],
                    [Status::IMPLANTED->value, Status::ATTACHED->value],
                    true
                );

            $deviceAssociations[$index]['recorded'] = ($opensPair ? $now->subMinute() : $now)
                ->toIso8601ZuluString();
        }

        return $deviceAssociations;
    }

    /**
     * Convert a flat form device association to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat device association form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'device' => FhirResource::make()
                ->coding('eHealth/resources', 'device')
                ->toIdentifier($data['deviceId']),
            'status' => $data['status'],
            'primarySource' => $data['primarySource'],
            'recorded' => convertToEHealthISO8601($data['recorded']),
            // The association belongs to the encounter it is recorded in, so its context is that encounter
            'context' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'recorder' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee'])
        ];

        if (!empty($data['associationDate'])) {
            $result['associationDate'] = CarbonImmutable::parse($data['associationDate'])->toDateString();
        }

        if (!empty($data['bodySiteCode'])) {
            $result['bodySite'] = FhirResource::make()
                ->coding('eHealth/body_structures', $data['bodySiteCode'])
                ->toCodeableConcept($data['bodySiteText'] ?? '');
        }

        if (!$data['primarySource']) {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept($data['reportOriginText'] ?? '');
        }

        return $result;
    }

    /**
     * Convert a FHIR device association (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR device association data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        return [
            'uuid' => data_get($data, 'uuid'),
            'deviceId' => data_get($data, 'device.identifier.value', ''),
            'status' => data_get($data, 'status', ''),
            'associationDate' => convertToAppDateFormat(data_get($data, 'associationDate')),
            'bodySiteCode' => data_get($data, 'bodySite.coding.0.code', ''),
            'bodySiteText' => data_get($data, 'bodySite.text', ''),
            'recorded' => data_get($data, 'recorded', ''),
            'primarySource' => data_get($data, 'primarySource'),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($data, 'reportOrigin.text', '')
        ];
    }
}

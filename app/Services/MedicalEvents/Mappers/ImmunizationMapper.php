<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Enums\Person\ImmunizationStatus;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ImmunizationMapper
{
    /**
     * Convert a flat form immunization to a FHIR structure for persistence/API.
     *
     * @param  array  $immunization
     * @param  array  $uuids
     * @return array
     */
    public function toFhir(array $immunization, array $uuids): array
    {
        $data = [
            'id' => $immunization['uuid'] ?? Str::uuid()->toString(),
            'status' => ImmunizationStatus::COMPLETED->value,
            'notGiven' => $immunization['notGiven'],
            'vaccineCode' => FhirResource::make()
                ->coding('eHealth/vaccine_codes', $immunization['vaccineCode'])
                ->toCodeableConcept(),
            'context' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'date' => convertToEHealthISO8601($immunization['date'] . ' ' . $immunization['time']),
            'primarySource' => $immunization['primarySource']
        ];

        if ($immunization['primarySource']) {
            $data['performer'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']);
        } else {
            $data['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/immunization_report_origins', $immunization['reportOriginCode'])
                ->toCodeableConcept($immunization['reportOriginText'] ?? '');
        }

        if (!empty($immunization['manufacturer'])) {
            $data['manufacturer'] = $immunization['manufacturer'];
        }

        if (!empty($immunization['lotNumber'])) {
            $data['lotNumber'] = $immunization['lotNumber'];
        }

        if (!empty($immunization['expirationDate'])) {
            $data['expirationDate'] = convertToEHealthISO8601($immunization['expirationDate'] . ' ' . now()->format('H:i'));
        }

        if (!empty($immunization['siteCode'])) {
            $data['site'] = FhirResource::make()
                ->coding('eHealth/immunization_body_sites', $immunization['siteCode'])
                ->toCodeableConcept();
        }

        if (!empty($immunization['routeCode'])) {
            $data['route'] = FhirResource::make()
                ->coding('eHealth/vaccination_routes', $immunization['routeCode'])
                ->toCodeableConcept();
        }

        if (!empty($immunization['doseQuantityValue'])) {
            $data['doseQuantity'] = [
                'value' => $immunization['doseQuantityValue'],
                'unit' => $immunization['doseQuantityUnit'],
                'system' => 'eHealth/immunization_dosage_units',
                'code' => $immunization['doseQuantityCode']
            ];
        }

        if (!$immunization['notGiven']) {
            $data['explanation']['reasons'] = collect($immunization['reasons'] ?? [])
                ->filter(fn (array $reason) => !empty($reason['code']))
                ->map(
                    fn (array $reason) => FhirResource::make()
                        ->coding('eHealth/reason_explanations', $reason['code'])
                        ->toCodeableConcept()
                )
                ->values()
                ->toArray();
        } else {
            $data['explanation']['reasonsNotGiven'] = [
                FhirResource::make()
                    ->coding('eHealth/reason_not_given_explanations', $immunization['reasonNotGivenCode'])
                    ->toCodeableConcept()
            ];
        }

        if (!empty($immunization['vaccinationProtocols'])) {
            $data['vaccinationProtocols'] = collect($immunization['vaccinationProtocols'])
                ->map(fn (array $protocol) => $this->protocolToFhir($protocol))
                ->values()
                ->toArray();
        }

        return $data;
    }

    /**
     * Convert a FHIR immunization (from DB) to a flat form structure.
     *
     * @param  array  $immunization
     * @return array
     */
    public function fromFhir(array $immunization): array
    {
        $date = CarbonImmutable::parse(data_get($immunization, 'date'));
        $rawTime = data_get($immunization, 'time');
        $time = $rawTime ? CarbonImmutable::parse($rawTime) : $date;
        $notGiven = data_get($immunization, 'notGiven', false);
        $reasons = $notGiven ? [] : collect(data_get($immunization, 'explanation.reasons', []))
            ->map(fn (array $reason) => ['code' => data_get($reason, 'coding.0.code', '')])
            ->filter(fn (array $reason) => !empty($reason['code']))
            ->values()
            ->toArray();

        if (!$notGiven && empty($reasons)) {
            $reasons = [['code' => '']];
        }

        return [
            'uuid' => data_get($immunization, 'uuid'),
            'primarySource' => data_get($immunization, 'primarySource'),
            'notGiven' => $notGiven,
            'vaccineCode' => data_get($immunization, 'vaccineCode.coding.0.code'),
            'date' => $date->format('Y-m-d'),
            'time' => $time->format('H:i'),
            'reasons' => $reasons,
            'reasonNotGivenCode' => data_get($immunization, 'explanation.reasonsNotGiven.0.coding.0.code', ''),
            'reportOriginCode' => data_get($immunization, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($immunization, 'reportOrigin.text', ''),
            'manufacturer' => data_get($immunization, 'manufacturer', ''),
            'lotNumber' => data_get($immunization, 'lotNumber', ''),
            'expirationDate' => data_get($immunization, 'expirationDate', ''),
            'siteCode' => data_get($immunization, 'site.coding.0.code', ''),
            'routeCode' => data_get($immunization, 'route.coding.0.code', ''),
            'doseQuantityValue' => data_get($immunization, 'doseQuantity.value'),
            'doseQuantityCode' => data_get($immunization, 'doseQuantity.code', ''),
            'doseQuantityUnit' => data_get($immunization, 'doseQuantity.unit', ''),
            'vaccinationProtocols' => collect(data_get($immunization, 'vaccinationProtocols', []))
                ->map(fn (array $protocol) => $this->protocolFromFhir($protocol))
                ->toArray()
        ];
    }

    /**
     * Format vaccination protocols to FHIR.
     *
     * @param  array  $protocol
     * @return array
     */
    private function protocolToFhir(array $protocol): array
    {
        $data = [
            'authority' => FhirResource::make()
                ->coding('eHealth/vaccination_authorities', $protocol['authorityCode'])
                ->toCodeableConcept(),
            'targetDiseases' => collect($protocol['targetDiseaseCodes'] ?? [])
                ->filter()
                ->map(
                    fn (string $code) => FhirResource::make()
                        ->coding('eHealth/vaccination_target_diseases', $code)
                        ->toCodeableConcept()
                )
                ->values()
                ->toArray()
        ];

        if (!empty($protocol['doseSequence'])) {
            $data['doseSequence'] = $protocol['doseSequence'];
        }

        if (!empty($protocol['series'])) {
            $data['series'] = $protocol['series'];
        }

        if (!empty($protocol['seriesDoses'])) {
            $data['seriesDoses'] = $protocol['seriesDoses'];
        }

        if (!empty($protocol['description'])) {
            $data['description'] = $protocol['description'];
        }

        return $data;
    }

    /**
     * Format vaccination protocols to flat data.
     *
     * @param  array  $protocol
     * @return array
     */
    private function protocolFromFhir(array $protocol): array
    {
        return [
            'authorityCode' => data_get($protocol, 'authority.coding.0.code', ''),
            'targetDiseaseCodes' => collect(data_get($protocol, 'targetDiseases', []))
                ->map(fn (array $disease) => data_get($disease, 'coding.0.code', ''))
                ->filter()
                ->values()
                ->toArray() ?: [''],
            'doseSequence' => data_get($protocol, 'doseSequence', ''),
            'series' => data_get($protocol, 'series', ''),
            'seriesDoses' => data_get($protocol, 'seriesDoses', ''),
            'description' => data_get($protocol, 'description', '')
        ];
    }
}

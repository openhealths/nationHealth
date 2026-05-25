<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\ImmunizationStatus;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ImmunizationMapper implements FhirMapperContract
{
    /**
     * Convert a flat form immunization to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat immunization form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => ImmunizationStatus::COMPLETED->value,
            'notGiven' => $data['notGiven'],
            'vaccineCode' => FhirResource::make()
                ->coding('eHealth/vaccine_codes', $data['vaccineCode'])
                ->toCodeableConcept(),
            'context' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'date' => convertToEHealthISO8601($data['date'] . ' ' . $data['time']),
            'primarySource' => $data['primarySource']
        ];

        if ($data['primarySource']) {
            $result['performer'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']);
        } else {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/immunization_report_origins', $data['reportOriginCode'])
                ->toCodeableConcept($data['reportOriginText'] ?? '');
        }

        if (!empty($data['manufacturer'])) {
            $result['manufacturer'] = $data['manufacturer'];
        }

        if (!empty($data['lotNumber'])) {
            $result['lotNumber'] = $data['lotNumber'];
        }

        if (!empty($data['expirationDate'])) {
            $result['expirationDate'] = convertToEHealthISO8601($data['expirationDate'] . ' ' . now()->format('H:i'));
        }

        if (!empty($data['siteCode'])) {
            $result['site'] = FhirResource::make()
                ->coding('eHealth/immunization_body_sites', $data['siteCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['routeCode'])) {
            $result['route'] = FhirResource::make()
                ->coding('eHealth/vaccination_routes', $data['routeCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['doseQuantityValue'])) {
            $result['doseQuantity'] = [
                'value' => $data['doseQuantityValue'],
                'unit' => $data['doseQuantityUnit'],
                'system' => 'eHealth/immunization_dosage_units',
                'code' => $data['doseQuantityCode']
            ];
        }

        if (!$data['notGiven']) {
            $result['explanation']['reasons'] = collect($data['reasons'] ?? [])
                ->filter(fn (array $reason) => !empty($reason['code']))
                ->map(
                    fn (array $reason) => FhirResource::make()
                        ->coding('eHealth/reason_explanations', $reason['code'])
                        ->toCodeableConcept()
                )
                ->values()
                ->toArray();
        } else {
            $result['explanation']['reasonsNotGiven'] = [
                FhirResource::make()
                    ->coding('eHealth/reason_not_given_explanations', $data['reasonNotGivenCode'])
                    ->toCodeableConcept()
            ];
        }

        if (!empty($data['vaccinationProtocols'])) {
            $result['vaccinationProtocols'] = collect($data['vaccinationProtocols'])
                ->map(fn (array $protocol) => $this->protocolToFhir($protocol))
                ->values()
                ->toArray();
        }

        return $result;
    }

    /**
     * Convert a FHIR immunization (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR immunization data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $notGiven = data_get($data, 'notGiven', false);
        $reasons = $notGiven ? [] : collect(data_get($data, 'explanation.reasons', []))
            ->map(fn (array $reason) => ['code' => data_get($reason, 'coding.0.code', '')])
            ->filter(fn (array $reason) => !empty($reason['code']))
            ->values()
            ->toArray();

        if (!$notGiven && empty($reasons)) {
            $reasons = [['code' => '']];
        }

        return [
            'uuid' => data_get($data, 'uuid'),
            'primarySource' => data_get($data, 'primarySource'),
            'notGiven' => $notGiven,
            'vaccineCode' => data_get($data, 'vaccineCode.coding.0.code'),
            'date' => CarbonImmutable::createFromFormat(config('app.date_format') . ' H:i', data_get($data, 'date'))->format(config('app.date_format')),
            'time' => data_get($data, 'time'),
            'reasons' => $reasons,
            'reasonNotGivenCode' => data_get($data, 'explanation.reasonsNotGiven.0.coding.0.code', ''),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($data, 'reportOrigin.text', ''),
            'manufacturer' => data_get($data, 'manufacturer', ''),
            'lotNumber' => data_get($data, 'lotNumber', ''),
            'expirationDate' => data_get($data, 'expirationDate')
                ? convertToAppDateFormat(data_get($data, 'expirationDate'))
                : '',
            'siteCode' => data_get($data, 'site.coding.0.code', ''),
            'routeCode' => data_get($data, 'route.coding.0.code', ''),
            'doseQuantityValue' => data_get($data, 'doseQuantity.value'),
            'doseQuantityCode' => data_get($data, 'doseQuantity.code', ''),
            'doseQuantityUnit' => data_get($data, 'doseQuantity.unit', ''),
            'vaccinationProtocols' => collect(data_get($data, 'vaccinationProtocols', []))
                ->map(fn (array $protocol) => $this->protocolFromFhir($protocol))
                ->toArray()
        ];
    }

    /**
     * Convert a flat vaccination protocol to FHIR vaccinationProtocols format.
     *
     * @param  array  $protocol
     * @return array
     */
    private function protocolToFhir(array $protocol): array
    {
        $result = [
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
            $result['doseSequence'] = $protocol['doseSequence'];
        }

        if (!empty($protocol['series'])) {
            $result['series'] = $protocol['series'];
        }

        if (!empty($protocol['seriesDoses'])) {
            $result['seriesDoses'] = $protocol['seriesDoses'];
        }

        if (!empty($protocol['description'])) {
            $result['description'] = $protocol['description'];
        }

        return $result;
    }

    /**
     * Convert a FHIR vaccinationProtocols entry to a flat protocol structure.
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

<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\EncounterStatus;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;

class EncounterMapper implements FhirMapperContract
{
    /**
     * Build a FHIR encounter structure ready for the repository or eHealth API.
     *
     * @param  array  $data  Flat encounter form data
     * @param  mixed  ...$context  [0] array $fhirConditions  Already-mapped FHIR conditions, [1] array $uuids
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$fhirConditions, $uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? $uuids['encounter'],
            'status' => EncounterStatus::FINISHED->value,
            'period' => [
                'start' => convertToEHealthISO8601($data['periodDate'] . ' ' . $data['periodStart']),
                'end' => convertToEHealthISO8601($data['periodDate'] . ' ' . $data['periodEnd'])
            ],
            'visit' => FhirResource::make()->coding('eHealth/resources', 'visit')->toIdentifier($uuids['visit']),
            'episode' => FhirResource::make()->coding('eHealth/resources', 'episode')->toIdentifier($uuids['episode']),
            'class' => FhirResource::make()->coding('eHealth/encounter_classes', $data['classCode'])->toCoding(),
            'type' => FhirResource::make()->coding('eHealth/encounter_types', $data['typeCode'])
                ->toCodeableConcept(),
            'performer' => FhirResource::make()->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee'])
        ];

        if ($data['referralType'] === 'electronic') {
            $result['incomingReferral'] = FhirResource::make()
                ->coding('eHealth/resources', 'service_request')
                ->toIdentifier($data['referralNumber']);
        }

        if ($data['referralType'] === 'paper') {
            $result['paperReferral'] = $data['paperReferral'];
            $result['paperReferral']['serviceRequestDate'] = convertToEHealthISO8601(
                $data['paperReferral']['serviceRequestDate']
            );
        }

        if (!empty($data['priorityCode'])) {
            $result['priority'] = FhirResource::make()->coding('eHealth/encounter_priority', $data['priorityCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['reasons'])) {
            $result['reasons'] = collect($data['reasons'])
                ->map(fn (array $cc) => FhirResource::make()->coding('eHealth/ICPC2/reasons', $cc['code'])
                    ->toCodeableConcept())
                ->toArray();
        }

        $result['diagnoses'] = array_map(
            static function (array $fhir, array $diagnosis) {
                $item = [
                    'condition' => FhirResource::make()->coding('eHealth/resources', 'condition')
                        ->toIdentifier($fhir['id']),
                    'role' => FhirResource::make()->coding('eHealth/diagnosis_roles', $diagnosis['roleCode'])
                        ->toCodeableConcept(),
                ];

                if (!empty($diagnosis['rank'])) {
                    $item['rank'] = $diagnosis['rank'];
                }

                return $item;
            },
            $fhirConditions,
            $data['diagnoses']
        );

        if (!empty($data['actions'])) {
            $result['actions'] = collect($data['actions'])
                ->map(fn (array $cc) => FhirResource::make()->coding('eHealth/ICPC2/actions', $cc['code'])
                    ->toCodeableConcept())
                ->toArray();
        }

        // todo: action_references

        if (!empty($data['divisionId'])) {
            $result['division'] = FhirResource::make()->coding('eHealth/resources', 'division')
                ->toIdentifier($data['divisionId']);
        }

        // todo: prescriptions

        // todo: supporting_info

        // todo: hospitalization

        // todo: participant

        return $result;
    }

    /**
     * Populate flat form keys from a nested FHIR encounter. Used when loading an existing encounter for editing.
     *
     * @param  array  $data  FHIR encounter data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        return [
            'classCode' => data_get($data, 'class.code'),
            'typeCode' => data_get($data, 'type.coding.0.code'),
            'divisionId' => data_get($data, 'division.identifier.value', ''),
            'priorityCode' => data_get($data, 'priority.coding.0.code', ''),
            'periodDate' => CarbonImmutable::parse(data_get($data, 'period.start'))->format('Y-m-d'),
            'periodStart' => CarbonImmutable::parse(data_get($data, 'period.start'))->format('H:i'),
            'periodEnd' => CarbonImmutable::parse(data_get($data, 'period.end'))->format('H:i'),
            'actions' => collect(data_get($data, 'actions', []))
                ->map(fn (array $action) => [
                    'code' => data_get($action, 'coding.0.code', ''),
                    'text' => data_get($action, 'text', '')
                ])
                ->toArray(),
            'reasons' => collect(data_get($data, 'reasons', []))
                ->map(fn (array $reason) => [
                    'code' => data_get($reason, 'coding.0.code', ''),
                    'text' => data_get($reason, 'text', '')
                ])
                ->toArray(),
            'diagnoses' => collect(data_get($data, 'diagnoses', []))
                ->map(fn (array $diagnosis) => [
                    'roleCode' => data_get($diagnosis, 'role.coding.0.code', ''),
                    'rank' => data_get($diagnosis, 'rank', '')
                ])
                ->toArray(),
            'referralType' => match (true) {
                !empty(data_get($data, 'incoming_referral')) => 'electronic',
                !empty(data_get($data, 'paper_referral')) => 'paper',
                default => ''
            },
            'referralNumber' => data_get($data, 'incoming_referral.identifier.value', ''),
            'paperReferral' => [
                ...data_get($data, 'paper_referral', []),
                'serviceRequestDate' => convertToAppDateFormat(data_get($data, 'paper_referral.serviceRequestDate'))
            ]
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Enums\Person\EncounterStatus;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;

class EncounterMapper
{
    /**
     * Build a FHIR encounter structure ready for the repository or eHealth API.
     * Absorbs the logic previously in EncounterRepository::formatEncounterRequest.
     *
     * @param  array  $encounter
     * @param  array  $fhirConditions
     * @param  array  $uuids
     * @return array
     */
    public function toFhir(array $encounter, array $fhirConditions, array $uuids): array
    {
        // Required params
        $data = [
            'id' => $encounter['uuid'] ?? $uuids['encounter'],
            'status' => EncounterStatus::FINISHED->value,
            'period' => [
                'start' => convertToEHealthISO8601($encounter['periodDate'] . ' ' . $encounter['periodStart']),
                'end' => convertToEHealthISO8601($encounter['periodDate'] . ' ' . $encounter['periodEnd'])
            ],
            'visit' => FhirResource::make()->coding('eHealth/resources', 'visit')->toIdentifier($uuids['visit']),
            'episode' => FhirResource::make()->coding('eHealth/resources', 'episode')->toIdentifier($uuids['episode']),
            'class' => FhirResource::make()->coding('eHealth/encounter_classes', $encounter['classCode'])->toCoding(),
            'type' => FhirResource::make()->coding('eHealth/encounter_types', $encounter['typeCode'])
                ->toCodeableConcept(),
            'performer' => FhirResource::make()->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee'])
        ];

        // todo: add incoming_referral and paper_referral

        if (!empty($encounter['priorityCode'])) {
            $data['priority'] = FhirResource::make()->coding('eHealth/encounter_priority', $encounter['priorityCode'])
                ->toCodeableConcept();
        }

        if (!empty($encounter['reasons'])) {
            $data['reasons'] = collect($encounter['reasons'])
                ->map(fn (array $cc) => FhirResource::make()->coding('eHealth/ICPC2/reasons', $cc['code'])
                    ->toCodeableConcept())
                ->toArray();
        }

        $data['diagnoses'] = array_map(
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
            $encounter['diagnoses']
        );

        if (!empty($encounter['actions'])) {
            $data['actions'] = collect($encounter['actions'])
                ->map(fn (array $cc) => FhirResource::make()->coding('eHealth/ICPC2/actions', $cc['code'])
                    ->toCodeableConcept())
                ->toArray();
        }

        // todo: action_references

        if (!empty($encounter['divisionId'])) {
            $data['division'] = FhirResource::make()->coding('eHealth/resources', 'division')
                ->toIdentifier($encounter['divisionId']);
        }

        // todo: prescriptions

        // todo: supporting_info

        // todo: hospitalization

        // todo: participant

        return $data;
    }

    /**
     * Populate flat form keys on $encounter from its nested FHIR paths.
     * Used when loading an existing encounter for editing.
     *
     * @param  array  $encounter
     * @return array
     */
    public function fromFhir(array $encounter): array
    {
        return [
            'classCode' => data_get($encounter, 'class.code'),
            'typeCode' => data_get($encounter, 'type.coding.0.code'),
            'divisionId' => data_get($encounter, 'division.identifier.value', ''),
            'priorityCode' => data_get($encounter, 'priority.coding.0.code', ''),
            'periodDate' => CarbonImmutable::parse(data_get($encounter, 'period.start'))->format('Y-m-d'),
            'periodStart' => CarbonImmutable::parse(data_get($encounter, 'period.start'))->format('H:i'),
            'periodEnd' => CarbonImmutable::parse(data_get($encounter, 'period.end'))->format('H:i'),
            'actions' => collect(data_get($encounter, 'actions', []))
                ->map(fn (array $action) => [
                    'code' => data_get($action, 'coding.0.code', ''),
                    'text' => data_get($action, 'text', '')
                ])
                ->toArray(),
            'reasons' => collect(data_get($encounter, 'reasons', []))
                ->map(fn (array $reason) => [
                    'code' => data_get($reason, 'coding.0.code', ''),
                    'text' => data_get($reason, 'text', '')
                ])
                ->toArray(),
            'diagnoses' => collect(data_get($encounter, 'diagnoses', []))
                ->map(fn (array $diagnosis) => [
                    'roleCode' => data_get($diagnosis, 'role.coding.0.code', ''),
                    'rank' => data_get($diagnosis, 'rank', '')
                ])
                ->toArray()
        ];
    }
}

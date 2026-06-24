<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class MedicationRequestMapper implements FhirMapperContract
{
    /**
     * Convert flat database/form data to FHIR structure for eHealth API.
     *
     * @param  array  $data
     * @param  mixed  ...$context [0] array $uuids (person_uuid, encounter_uuid, employee_uuid, legal_entity_uuid)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $data['status'] ?? 'draft',
            'intent' => $data['intent'] ?? 'order',
            'medicationCodeableConcept' => FhirResource::make()
                ->coding('eHealth/medications', $data['medication_id'])
                ->toCodeableConcept(),
            'subject' => FhirResource::make()
                ->coding('eHealth/resources', 'patient')
                ->toIdentifier($uuids['person_uuid']),
            'requester' => [
                'agent' => FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($uuids['employee_uuid']),
                'onBehalfOf' => FhirResource::make()
                    ->coding('eHealth/resources', 'legal_entity')
                    ->toIdentifier($uuids['legal_entity_uuid'])
            ]
        ];

        if (!empty($uuids['encounter_uuid'])) {
            $result['context'] = FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter_uuid']);
        }

        if (!empty($data['based_on_uuid'])) {
            $result['basedOn'] = [
                FhirResource::make()
                    ->coding('eHealth/resources', 'care_plan_activity')
                    ->toIdentifier($data['based_on_uuid'])
            ];
        }

        if (!empty($data['category'])) {
            $result['category'] = FhirResource::make()
                ->coding('eHealth/medication_request_categories', $data['category'])
                ->toCodeableConcept();
        }

        if (!empty($data['priority'])) {
            $result['priority'] = $data['priority'];
        }

        if (!empty($data['note'])) {
            $result['note'] = [['text' => $data['note']]];
        }

        if (!empty($data['medication_program_id'])) {
            $result['program'] = FhirResource::make()
                ->coding('eHealth/medical_programs', $data['medication_program_id'])
                ->toIdentifier($data['medication_program_id']);
        }

        // Mapping Dosage Instructions
        if (!empty($data['dosage_instructions'])) {
            $result['dosageInstruction'] = array_map(function (array $inst, int $index) {
                $dosage = [
                    'sequence' => $inst['sequence'] ?? ($index + 1),
                    'text' => $inst['text'] ?? null,
                    'patientInstruction' => $inst['patient_instruction'] ?? null,
                ];

                if (!empty($inst['additional_instruction_code'])) {
                    $dosage['additionalInstruction'] = [
                        FhirResource::make()
                            ->coding('eHealth/additional_dosage_instructions', $inst['additional_instruction_code'])
                            ->toCodeableConcept()
                    ];
                }

                if (!empty($inst['timing'])) {
                    $dosage['timing'] = $inst['timing'];
                }

                if (isset($inst['as_needed_boolean'])) {
                    $dosage['asNeededBoolean'] = (bool) $inst['as_needed_boolean'];
                }

                if (!empty($inst['route'])) {
                    $dosage['route'] = FhirResource::make()
                        ->coding('eHealth/vaccination_routes', $inst['route'])
                        ->toCodeableConcept();
                }

                if (!empty($inst['method'])) {
                    $dosage['method'] = FhirResource::make()
                        ->coding('eHealth/dosage_methods', $inst['method'])
                        ->toCodeableConcept();
                }

                // Dose and Rate mapping
                if (!empty($inst['dose_and_rate'])) {
                    $dosage['doseAndRate'] = array_map(function (array $dr) {
                        $mappedDr = [];
                        if (isset($dr['dose_quantity_value'])) {
                            $mappedDr['doseQuantity'] = [
                                'value' => (float) $dr['dose_quantity_value'],
                                'unit' => $dr['dose_quantity_unit'] ?? null,
                                'system' => 'http://unitsofmeasure.org',
                                'code' => $dr['dose_quantity_code'] ?? null
                            ];
                        }
                        return $mappedDr;
                    }, $inst['dose_and_rate']);
                }

                return array_filter($dosage, fn ($val) => $val !== null);
            }, $data['dosage_instructions'], array_keys($data['dosage_instructions']));
        }

        // Dispense request quantity mapping
        if (isset($data['medication_qty'])) {
            $result['dispenseRequest'] = [
                'quantity' => [
                    'value' => (float) $data['medication_qty'],
                    'system' => 'http://unitsofmeasure.org'
                ]
            ];
        }

        return $result;
    }

    /**
     * Convert FHIR structure (from eHealth response/DB) to flat application format.
     *
     * @param  array  $data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $dosageInstructions = [];
        if (!empty($data['dosageInstruction'])) {
            foreach ($data['dosageInstruction'] as $inst) {
                $doseAndRate = [];
                if (!empty($inst['doseAndRate'])) {
                    foreach ($inst['doseAndRate'] as $dr) {
                        $doseAndRate[] = [
                            'dose_quantity_value' => data_get($dr, 'doseQuantity.value'),
                            'dose_quantity_unit' => data_get($dr, 'doseQuantity.unit'),
                            'dose_quantity_code' => data_get($dr, 'doseQuantity.code')
                        ];
                    }
                }

                $dosageInstructions[] = [
                    'sequence' => data_get($inst, 'sequence'),
                    'text' => data_get($inst, 'text'),
                    'patient_instruction' => data_get($inst, 'patientInstruction'),
                    'additional_instruction_code' => data_get($inst, 'additionalInstruction.0.coding.0.code'),
                    'timing' => data_get($inst, 'timing'),
                    'as_needed_boolean' => data_get($inst, 'asNeededBoolean', false),
                    'route' => data_get($inst, 'route.coding.0.code'),
                    'method' => data_get($inst, 'method.coding.0.code'),
                    'dose_and_rate' => $doseAndRate
                ];
            }
        }

        return [
            'uuid' => data_get($data, 'uuid') ?? data_get($data, 'id'),
            'status' => data_get($data, 'status'),
            'request_number' => data_get($data, 'requestNumber') ?? data_get($data, 'request_number'),
            'started_at' => data_get($data, 'period.start') ?? data_get($data, 'started_at'),
            'ended_at' => data_get($data, 'period.end') ?? data_get($data, 'ended_at'),
            'medication_id' => data_get($data, 'medicationCodeableConcept.coding.0.code'),
            'medication_qty' => data_get($data, 'dispenseRequest.quantity.value'),
            'medication_program_id' => data_get($data, 'program.identifier.value'),
            'intent' => data_get($data, 'intent'),
            'category' => data_get($data, 'category.coding.0.code'),
            'based_on_uuid' => data_get($data, 'basedOn.0.identifier.value'),
            'context_uuid' => data_get($data, 'context.identifier.value'),
            'priority' => data_get($data, 'priority'),
            'note' => data_get($data, 'note.0.text'),
            'dosage_instructions' => $dosageInstructions
        ];
    }
}

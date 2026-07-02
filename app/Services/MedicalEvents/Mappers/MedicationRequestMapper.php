<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

class MedicationRequestMapper implements FhirMapperContract
{
    /**
     * Convert flat database/form data to FHIR structure for eHealth API.
     *
     * @param  array  $data
     * @param  mixed  ...$context  [0] array $uuids (person_uuid, encounter_uuid, employee_uuid, legal_entity_uuid)
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
     * Build payload for POST /api/medication_request_requests (ESOZ API-005-044-0002).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array<string, mixed>
     */
    public function toCreateRequestPayload(array $data, array $uuids, ?string $carePlanUuid = null): array
    {
        $request = [
            'person_id' => $uuids['person_uuid'],
            'employee_id' => $uuids['employee_uuid'],
            'division_id' => $uuids['division_uuid'] ?? null,
            'created_at' => $data['created_at'] ?? now()->toDateString(),
            'started_at' => $data['started_at'],
            'ended_at' => $data['ended_at'],
            'medication_id' => $data['medication_id'],
            'medication_qty' => (float) $data['medication_qty'],
            'intent' => $data['intent'] ?? 'order',
            'category' => $data['category'] ?? 'community',
        ];

        if (!empty($data['medication_program_id'])) {
            $request['medical_program_id'] = $data['medication_program_id'];
        }

        if ($carePlanUuid && !empty($data['based_on_uuid'])) {
            $request['based_on'] = [
                FhirResource::make()
                    ->coding('eHealth/resources', 'care_plan')
                    ->toIdentifier($carePlanUuid),
                FhirResource::make()
                    ->coding('eHealth/resources', 'activity')
                    ->toIdentifier($data['based_on_uuid']),
            ];
        }

        if (!empty($uuids['encounter_uuid'])) {
            $request['context'] = FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter_uuid']);
        }

        if (!empty($data['dosage_instructions'])) {
            $request['dosage_instruction'] = $this->mapDosageInstructionsForCreate($data['dosage_instructions']);
        }

        $informWith = $data['inform_with'] ?? '';
        $authMethodId = explode('|', (string) $informWith)[0] ?? '';
        if ($authMethodId !== '') {
            $request['inform_with'] = $authMethodId;
        }

        if (!empty($data['container_dosage'])) {
            $request['container_dosage'] = $data['container_dosage'];
        }

        return ['medication_request_request' => array_filter($request, static fn ($value) => $value !== null && $value !== '')];
    }

    /**
     * @param  array<int, array<string, mixed>>  $instructions
     * @return array<int, array<string, mixed>>
     */
    private function mapDosageInstructionsForCreate(array $instructions): array
    {
        return array_values(array_map(function (array $inst, int $index): array {
            $unit = $inst['dose_and_rate'][0]['dose_quantity_unit'] ?? 'од.';
            $dosage = [
                'sequence' => $inst['sequence'] ?? ($index + 1),
                'text' => $inst['text'] ?? null,
                'patient_instruction' => $inst['patient_instruction'] ?? ($inst['text'] ?? null),
                'as_needed_boolean' => (bool) ($inst['as_needed_boolean'] ?? false),
            ];

            if (!empty($inst['route'])) {
                $dosage['route'] = FhirResource::make()
                    ->coding('eHealth/SNOMED/route_codes', $this->resolveRouteCode((string) $inst['route']))
                    ->toCodeableConcept();
            }

            if (!empty($inst['dose_and_rate'])) {
                $dr = is_array($inst['dose_and_rate'][0] ?? null)
                    ? $inst['dose_and_rate'][0]
                    : $inst['dose_and_rate'];

                if (isset($dr['dose_quantity_value'])) {
                    $dosage['dose_and_rate'] = [
                        'type' => FhirResource::make()
                            ->coding('eHealth/dose_and_rate', 'ordered')
                            ->toCodeableConcept(),
                        'dose_quantity' => [
                            'value' => (float) $dr['dose_quantity_value'],
                            'unit' => $dr['dose_quantity_unit'] ?? null,
                            'system' => 'eHealth/ucum/units',
                            'code' => $dr['dose_quantity_code'] ?? ($dr['dose_quantity_unit'] ?? null),
                        ],
                    ];
                }
            }

            if (isset($inst['max_dose_per_administration'])) {
                $dosage['max_dose_per_administration'] = [
                    'value' => (float) $inst['max_dose_per_administration'],
                    'unit' => $unit,
                    'system' => 'eHealth/ucum/units',
                    'code' => $unit,
                ];
            }

            if (isset($inst['max_dose_per_period'])) {
                $dosage['max_dose_per_period'] = [
                    'numerator' => [
                        'value' => (float) $inst['max_dose_per_period'],
                        'unit' => $unit,
                        'system' => 'eHealth/ucum/units',
                        'code' => $unit,
                    ],
                    'denominator' => [
                        'value' => 1,
                        'unit' => 'd',
                        'system' => 'eHealth/ucum/units',
                        'code' => 'd',
                    ],
                ];
            }

            return array_filter($dosage, static fn ($value) => $value !== null && $value !== '');
        }, $instructions, array_keys($instructions)));
    }

    private function resolveRouteCode(string $route): string
    {
        $aliases = [
            'oral' => '26643006',
        ];

        return $aliases[strtolower($route)] ?? $route;
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

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
            $result['paperReferral']['serviceRequestDate'] = convertToYmd($data['paperReferral']['serviceRequestDate']);
        }

        if (!empty($data['priorityCode'])) {
            $result['priority'] = FhirResource::make()->coding('eHealth/encounter_priority', $data['priorityCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['reasons'])) {
            $result['reasons'] = array_map(
                static fn (array $cc) => FhirResource::make()->coding('eHealth/ICPC2/reasons', $cc['code'])
                    ->toCodeableConcept($cc['text'] ?? ''),
                $data['reasons']
            );
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

        if (!empty($data['actionReferences'])) {
            $result['actionReferences'] = collect($data['actionReferences'])
                ->map(fn (mixed $item) => $this->identifierUuid($item))
                ->filter()
                ->unique()
                ->map(fn (string $uuid) => FhirResource::make()
                    ->coding('eHealth/resources', 'service')
                    ->toIdentifier($uuid))
                ->values()
                ->toArray();
        }

        if (!empty($data['divisionId'])) {
            $result['division'] = FhirResource::make()->coding('eHealth/resources', 'division')
                ->toIdentifier($data['divisionId']);
        }

        if (!empty($data['prescriptions'])) {
            $result['prescriptions'] = $data['prescriptions'];
        }

        if (!empty($data['supportingInfo'])) {
            $result['supportingInfo'] = collect($data['supportingInfo'])
                ->filter(fn (array $item) => !empty($item['id']) && !empty($item['type']))
                ->unique(fn (array $item) => $item['type'] . ':' . $item['id'])
                ->map(function (array $item) {
                    $identifier = FhirResource::make()
                        ->coding('eHealth/resources', $item['type'])
                        ->toIdentifier($item['id'], $item['typeLabel'] ?? '');

                    $displayValue = collect([
                        $item['code'] ?? $item['codeCode'] ?? null,
                        $item['name'] ?? null,
                        $item['date'] ?? $item['ehealthInsertedAt'] ?? null,
                    ])
                        ->filter()
                        ->unique()
                        ->implode(' — ');

                    if ($displayValue !== '') {
                        $identifier['display_value'] = $displayValue;
                    }

                    return $identifier;
                })
                ->values()
                ->toArray();
        }

        // todo: hospitalization

        if (!empty($data['participant'])) {
            $result['participant'] = collect($data['participant'])
                ->map(fn (mixed $item) => $this->identifierUuid($item))
                ->filter()
                ->unique()
                ->map(fn (string $uuid) => FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($uuid))
                ->values()
                ->toArray();
        }

        return $result;
    }

    private function identifierValues(?array $items): array
    {
        return collect($items ?? [])
            ->map(fn (array $item) => data_get($item, 'identifier.value'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    private function identifierUuid(mixed $item): string
    {
        if (is_string($item)) {
            return $item;
        }

        if (!is_array($item)) {
            return '';
        }

        return data_get($item, 'uuid');
    }

    private function supportingInfoValues(?array $items, array $detailsMap = []): array
    {
        return collect($items ?? [])
            ->map(function (array $item) use ($detailsMap) {
                $type = data_get($item, 'identifier.type.coding.0.code', '');
                $id = data_get($item, 'identifier.value', '');
                $typeLabel = $this->medicalRecordTypeLabel($type);
                $typeText = data_get($item, 'identifier.type.text', '');

                $displayValue = data_get($item, 'displayValue');

                [$code, $name, $date] = $this->splitSupportingInfoDisplayValue($displayValue);

                $details = $detailsMap[$id] ?? [];
                $code = $code ?: data_get($details, 'codeCode', '');
                $date = $date ?: data_get($details, 'ehealthInsertedAt', '');

                if ($name === '' && $typeText !== '' && $typeText !== $typeLabel) {
                    $name = $typeText;
                }

                return [
                    'id' => $id,
                    'type' => $type,
                    'code' => $code,
                    'name' => $name,
                    'date' => $date,
                    'typeLabel' => $typeLabel
                ];
            })
            ->filter(fn (array $item) => !empty($item['id']) && !empty($item['type']))
            ->unique(fn (array $item) => $item['type'] . ':' . $item['id'])
            ->values()
            ->toArray();
    }

    private function splitSupportingInfoDisplayValue(string $displayValue): array
    {
        $parts = collect(explode(' — ', $displayValue))
            ->map(static fn (string $part) => trim($part))
            ->filter()
            ->values();

        $date = '';
        $lastPart = $parts->last() ?? '';

        if ($lastPart !== '' && $this->isDisplayValueDate($lastPart)) {
            $date = $lastPart;
            $parts = $parts->slice(0, -1)->values();
        }

        if ($parts->count() >= 2) {
            return [
                (string)$parts->shift(),
                $parts->implode(' — '),
                $date,
            ];
        }

        return [
            '',
            $parts->first() ?? '',
            $date,
        ];
    }

    private function isDisplayValueDate(string $value): bool
    {
        return preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)
            || preg_match('/^\d{4}-\d{2}-\d{2}/', $value);
    }

    private function medicalRecordTypeLabel(string $type): string
    {
        return match ($type) {
            'condition' => __('patients.condition_or_diagnosis'),
            'observation' => __('patients.medical_observation'),
            'diagnostic_report' => __('patients.diagnostic_reports'),
            default => $type,
        };
    }

    /**
     * Populate flat form keys from a nested FHIR encounter. Used when loading an existing encounter for editing.
     *
     * @param  array  $data  FHIR encounter data
     * @param  array  $context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $supportingInfoDetails = $context[0] ?? [];

        return [
            'classCode' => data_get($data, 'class.code'),
            'typeCode' => data_get($data, 'type.coding.0.code'),
            'divisionId' => data_get($data, 'division.identifier.value', ''),
            'priorityCode' => data_get($data, 'priority.coding.0.code', ''),
            'periodDate' => convertToAppDateFormat(data_get($data, 'period.start')),
            'periodStart' => CarbonImmutable::parse(data_get($data, 'period.start'))->format('H:i'),
            'periodEnd' => CarbonImmutable::parse(data_get($data, 'period.end'))->format('H:i'),
            'actions' => array_map(
                static fn (array $action) => [
                    'code' => data_get($action, 'coding.0.code'),
                    'text' => data_get($action, 'text', '')
                ],
                data_get($data, 'actions', [])
            ),
            'reasons' => array_map(
                static fn (array $reason) => [
                    'code' => data_get($reason, 'coding.0.code'),
                    'text' => data_get($reason, 'text', '')
                ],
                data_get($data, 'reasons', [])
            ),
            'diagnoses' => array_map(
                static fn (array $diagnosis) => [
                    'roleCode' => data_get($diagnosis, 'role.coding.0.code'),
                    'rank' => data_get($diagnosis, 'rank', '')
                ],
                data_get($data, 'diagnoses', [])
            ),
            'referralType' => match (true) {
                !empty(data_get($data, 'incoming_referral')) => 'electronic',
                !empty(data_get($data, 'paper_referral')) => 'paper',
                default => ''
            },
            'referralNumber' => data_get($data, 'incoming_referral.identifier.value', ''),
            'paperReferral' => [
                ...(data_get($data, 'paper_referral') ?? []),
                'serviceRequestDate' => convertToAppDateFormat(data_get($data, 'paper_referral.serviceRequestDate'))
            ],
            'prescriptions' => data_get($data, 'prescriptions', ''),
            'actionReferences' => $this->identifierValues(data_get($data, 'action_references', [])),
            'participant' => $this->identifierValues(data_get($data, 'participants', [])),
            'supportingInfo' => $this->supportingInfoValues(
                data_get($data, 'supporting_info', []),
                $supportingInfoDetails
            )
        ];
    }
}

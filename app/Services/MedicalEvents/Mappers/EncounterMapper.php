<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Core\Arr;
use App\Enums\Person\ClinicalImpressionStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Person\ProcedureStatus;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;

class EncounterMapper implements FhirMapperContract
{
    /**
     * The field each kind of record of the package carries its status in, and the cancelled status to put there.
     *
     * @var array
     */
    private const array CANCELLED_STATUSES = [
        'conditions' => ['verificationStatus', ConditionVerificationStatus::ENTERED_IN_ERROR],
        'observations' => ['status', ObservationStatus::ENTERED_IN_ERROR],
        'immunizations' => ['status', ImmunizationStatus::ENTERED_IN_ERROR],
        'diagnosticReports' => ['status', DiagnosticReportStatus::ENTERED_IN_ERROR],
        'procedures' => ['status', ProcedureStatus::ENTERED_IN_ERROR],
        'clinicalImpressions' => ['status', ClinicalImpressionStatus::ENTERED_IN_ERROR]
    ];

    /**
     * The same for the records that may be marked as entered in error on their own. Conditions are left out
     * on purpose: a diagnosis is only cancelled together with the encounter it belongs to.
     *
     * @var array
     */
    private const array SEPARATELY_CANCELLED_STATUSES = [
        'observations' => ['status', ObservationStatus::ENTERED_IN_ERROR],
        'immunizations' => ['status', ImmunizationStatus::ENTERED_IN_ERROR],
        'diagnosticReports' => ['status', DiagnosticReportStatus::ENTERED_IN_ERROR],
        'procedures' => ['status', ProcedureStatus::ENTERED_IN_ERROR],
        'clinicalImpressions' => ['status', ClinicalImpressionStatus::ENTERED_IN_ERROR]
    ];

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
            if (!empty($data['referralDisplayValue'])) {
                $result['incomingReferral']['display_value'] = $data['referralDisplayValue'];
            }
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

        $result['diagnoses'] = [];

        // A diagnosis names the condition standing at its own position among the conditions of the package,
        // so a condition carried without a diagnosis of its own simply adds nothing here
        foreach ($data['diagnoses'] as $index => $diagnosis) {
            $conditionId = $fhirConditions[$index]['id'] ?? null;

            if ($conditionId === null) {
                continue;
            }

            $item = [
                'condition' => FhirResource::make()->coding('eHealth/resources', 'condition')
                    ->toIdentifier($conditionId),
                'role' => FhirResource::make()->coding('eHealth/diagnosis_roles', $diagnosis['roleCode'])
                    ->toCodeableConcept()
            ];

            if (!empty($diagnosis['rank'])) {
                $item['rank'] = $diagnosis['rank'];
            }

            $result['diagnoses'][] = $item;
        }

        if (!empty($data['actions'])) {
            $result['actions'] = array_map(
                static fn (array $cc) => FhirResource::make()->coding('eHealth/ICPC2/actions', $cc['code'])
                    ->toCodeableConcept($cc['text'] ?? ''),
                $data['actions']
            );
        }

        $mappedActionReferences = collect($data['actionReferences'] ?? [])
            ->pluck('uuid')
            ->filter()
            ->unique()
            ->map(fn (string $uuid) => FhirResource::make()
                ->coding('eHealth/resources', 'service')
                ->toIdentifier($uuid))
            ->values()
            ->toArray();

        if (!empty($mappedActionReferences)) {
            $result['actionReferences'] = $mappedActionReferences;
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
                ->filter(fn (array $item) => !empty($item['uuid']) && !empty($item['type']))
                ->unique(fn (array $item) => $item['type'] . ':' . $item['uuid'])
                ->map(fn (array $item) => FhirResource::make()
                    ->coding('eHealth/resources', $item['type'])
                    ->toIdentifier($item['uuid'], $item['typeLabel'] ?? ''))
                ->values()
                ->toArray();
        }

        // todo: hospitalization

        $asserterUuids = collect($fhirConditions)
            ->flatMap(function (array $condition) {
                $asserter = $condition['asserter'] ?? null;
                if (!$asserter) {
                    return [];
                }
                if (is_array($asserter) && array_is_list($asserter)) {
                    return collect($asserter)->pluck('identifier.value');
                }

                return [data_get($asserter, 'identifier.value')];
            })
            ->filter();

        $mappedParticipants = collect($data['participant'] ?? [])
            ->pluck('uuid')
            ->push($uuids['employee'] ?? null)
            ->concat($asserterUuids)
            ->filter()
            ->unique()
            ->map(fn (string $uuid) => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuid))
            ->values()
            ->toArray();

        if (!empty($mappedParticipants)) {
            $result['participant'] = $mappedParticipants;
        }

        return $result;
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
            'actionReferences' => collect(data_get($data, 'action_references', []))
                ->map(static fn (array $item) => ['uuid' => data_get($item, 'identifier.value')])
                ->filter(static fn (array $item) => !empty($item['uuid']))
                ->values()
                ->toArray(),
            'participant' => collect(data_get($data, 'participants', []))
                ->map(static fn (array $item) => [
                    'uuid' => data_get($item, 'identifier.value'),
                    'name' => data_get($item, 'displayValue', data_get($item, 'display_value', '')),
                ])
                ->filter(static fn (array $item) => !empty($item['uuid']))
                ->values()
                ->toArray(),
            'supportingInfo' => collect(data_get($data, 'supporting_info', []))
                ->map(function (array $item) use ($supportingInfoDetails) {
                    $uuid = data_get($item, 'identifier.value');
                    $type = data_get($item, 'identifier.type.coding.0.code');
                    $details = $supportingInfoDetails[$uuid] ?? [];

                    return [
                        'uuid' => $uuid,
                        'type' => $type,
                        'date' => $details['ehealthInsertedAt'] ?? null,
                        'code' => $details['codeCode'] ?? null,
                        'name' => '',
                        'typeLabel' => ''
                    ];
                })
                ->values()
                ->toArray(),
        ];
    }

    /**
     * Turn a rebuilt encounter package into the one that marks it and every record in it as entered in error.
     * The package has to arrive from the same builder that created it, so that the content eHealth compares
     * the signature against is the content it already stored.
     *
     * @param  array  $package  Package as Fhir::encounterPackage()->toFhir() built it
     * @param  string  $cancellationReason
     * @param  string  $explanatoryLetter
     * @param  string|null  $cancellationReasonText
     * @return array
     */
    public function toCancellationPackage(
        array $package,
        string $cancellationReason,
        string $explanatoryLetter,
        ?string $cancellationReasonText = null
    ): array {
        $package['encounter'] = $this->withCancellationDetails(
            $package['encounter'],
            EncounterStatus::ENTERED_IN_ERROR->value,
            $cancellationReason,
            $explanatoryLetter,
            $cancellationReasonText
        );

        foreach (self::CANCELLED_STATUSES as $packageKey => [$statusField, $cancelledStatus]) {
            $package[$packageKey] = array_map(
                static fn (array $record): array => [
                    ...$record,
                    $statusField => $cancelledStatus->value,
                    'explanatoryLetter' => $explanatoryLetter
                ],
                $package[$packageKey] ?? []
            );
        }

        // Sections with no records stay out, the same way the package was signed when it was created
        return Arr::toSnakeCase(array_filter($package));
    }

    /**
     * Turn a rebuilt encounter package into the one that marks the given records of it as entered in error.
     * The encounter and every record left out keep the status they were stored with, which is what tells eHealth
     * the package itself stays valid. The package still has to travel whole, since eHealth compares its content
     * to the content it stored, statuses aside.
     *
     * @param  array  $package  Package as Fhir::encounterPackage()->toFhir() built it
     * @param  string  $encounterStatus  Status the encounter is stored with
     * @param  array  $recordIds  eHealth IDs of the records to mark, keyed by package section
     * @param  string  $cancellationReason
     * @param  string  $explanatoryLetter
     * @param  string|null  $cancellationReasonText
     * @return array
     */
    public function toRecordCancellationPackage(
        array $package,
        string $encounterStatus,
        array $recordIds,
        string $cancellationReason,
        string $explanatoryLetter,
        ?string $cancellationReasonText = null
    ): array {
        $package['encounter'] = $this->withCancellationDetails(
            $package['encounter'],
            $encounterStatus,
            $cancellationReason,
            $explanatoryLetter,
            $cancellationReasonText
        );

        foreach ($recordIds as $packageKey => $ids) {
            [$statusField, $cancelledStatus] = self::SEPARATELY_CANCELLED_STATUSES[$packageKey];

            $package[$packageKey] = array_map(
                static fn (array $record): array => in_array($record['id'], $ids, true)
                    ? [
                        ...$record,
                        $statusField => $cancelledStatus->value,
                        'explanatoryLetter' => $explanatoryLetter
                    ]
                    : $record,
                $package[$packageKey] ?? []
            );
        }

        // Sections with no records stay out, the same way the package was signed when it was created
        return Arr::toSnakeCase(array_filter($package));
    }

    /**
     * Put the cancellation details on the encounter. eHealth requires the reason and the explanatory letter in
     * every cancellation request, even the one that leaves the encounter itself untouched.
     *
     * @param  array  $encounter
     * @param  string  $status
     * @param  string  $cancellationReason
     * @param  string  $explanatoryLetter
     * @param  string|null  $cancellationReasonText
     * @return array
     */
    private function withCancellationDetails(
        array $encounter,
        string $status,
        string $cancellationReason,
        string $explanatoryLetter,
        ?string $cancellationReasonText
    ): array {
        return [
            ...$encounter,
            'status' => $status,
            'cancellationReason' => FhirResource::make()
                ->coding('eHealth/cancellation_reasons', $cancellationReason)
                ->toCodeableConcept($cancellationReasonText ?? ''),
            'explanatoryLetter' => $explanatoryLetter
        ];
    }
}

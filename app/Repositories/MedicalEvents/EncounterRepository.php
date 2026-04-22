<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\PatientApi;
use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Encounter as EncounterSql;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property Encounter $model
 */
class EncounterRepository extends BaseRepository
{
    protected string $encounterUuid;
    protected array $diagnoseUuids;
    protected string $visitUuid;
    protected string $episodeUuid;
    protected ?string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $this->encounterUuid = Str::uuid()->toString();
        $this->visitUuid = Str::uuid()->toString();
        $this->episodeUuid = Str::uuid()->toString();
        $this->employeeUuid = Auth::user()?->getEncounterWriterEmployee()?->uuid;
    }

    /**
     * Create encounter in DB for person with related data.
     *
     * @param  array  $encounterData
     * @param  int  $personId
     * @return false|int
     * @throws Throwable
     */
    public function store(array $encounterData, int $personId): false|int
    {
        return DB::transaction(function () use ($encounterData, $personId) {
            try {
                $visit = Repository::identifier()->store($encounterData['visit']['identifier']['value']);
                Repository::codeableConcept()->attach($visit, $encounterData['visit']);

                $episode = Repository::identifier()->store($encounterData['episode']['identifier']['value']);
                Repository::codeableConcept()->attach($episode, $encounterData['episode']);

                $class = Repository::coding()->store($encounterData['class']);

                $type = Repository::codeableConcept()->store($encounterData['type']);

                if (isset($encounterData['priority']['coding'][0]['code'])) {
                    $priority = Repository::codeableConcept()->store($encounterData['priority']);
                }

                $performer = Repository::identifier()->store($encounterData['performer']['identifier']['value']);
                Repository::codeableConcept()->attach($performer, $encounterData['performer']);

                if (isset($encounterData['division'])) {
                    $division = Repository::identifier()->store($encounterData['division']['identifier']['value']);
                    Repository::codeableConcept()->attach($division, $encounterData['division']);
                }

                $encounter = $this->model->create([
                    'person_id' => $personId,
                    'uuid' => $encounterData['uuid'] ?? $encounterData['id'],
                    'status' => $encounterData['status'],
                    'visit_id' => $visit->id,
                    'episode_id' => $episode->id,
                    'class_id' => $class->id,
                    'type_id' => $type->id,
                    'priority_id' => $priority->id ?? null,
                    'performer_id' => $performer->id,
                    'division_id' => $division->id ?? null
                ]);

                $encounter->period()->create([
                    'start' => $encounterData['period']['start'],
                    'end' => $encounterData['period']['end']
                ]);

                $reasonIds = [];

                foreach ($encounterData['reasons'] as $reasonData) {
                    $reason = Repository::codeableConcept()->store($reasonData);

                    $reasonIds[] = $reason->id;
                }

                $encounter->reasons()->attach($reasonIds);

                foreach ($encounterData['diagnoses'] as $diagnoseData) {
                    $condition = Repository::identifier()->store($diagnoseData['condition']['identifier']['value']);
                    Repository::codeableConcept()->attach($condition, $diagnoseData['condition']);

                    $role = Repository::codeableConcept()->store($diagnoseData['role']);

                    EncounterDiagnose::create([
                        'encounter_id' => $encounter->id,
                        'condition_id' => $condition->id,
                        'role_id' => $role->id,
                        'rank' => $diagnoseData['rank'] ?? null
                    ]);
                }

                $actionIds = [];

                foreach ($encounterData['actions'] as $actionData) {
                    $action = Repository::codeableConcept()->store($actionData);

                    $actionIds[] = $action->id;
                }

                $encounter->actions()->attach($actionIds);

                return $encounter->id;
            } catch (Exception $e) {
                Log::channel('db_errors')->error('Error saving encounter', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);

                throw $e;
            }
        });
    }

    /**
     * Get encounter data by encounter ID form URL.
     *
     * @param  int  $encounterId
     * @return array|null
     */
    public function get(int $encounterId): ?array
    {
        return $this->model::with([
            'period',
            'visit',
            'episode',
            'class',
            'type.coding',
            'priority.coding',
            'performer',
            'reasons.coding',
            'diagnoses',
            'actions.coding',
            'division'
        ])
            ->where('id', $encounterId)
            ->first()
            ?->toArray();
    }

    /**
     * Get the encounter for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  string  $uuid
     * @return array|null
     */
    public function getForClinicalImpression(string $uuid): ?array
    {
        $encounter = EncounterSql::whereUuid($uuid)
            ->with(['period', 'diagnoses'])
            ->first();

        if (!$encounter || !data_get($encounter, 'diagnoses.0.condition.identifier.value')) {
            return null;
        }

        $condition = Condition::whereUuid($encounter['diagnoses'][0]['condition']['identifier']['value'])
            ->with('code.coding')
            ->first();

        return [
            'periodStart' => $encounter->period->start,
            'code' => $condition?->code
        ];
    }

    /**
     * Format encounter data before request.
     *
     * @param  array  $encounter
     * @param  array  $conditions
     * @param  bool  $isEpisodeNew
     * @return array
     */
    public function formatEncounterRequest(array $encounter, array $conditions, bool $isEpisodeNew): array
    {
        $encounter['id'] = $this->encounterUuid;
        $encounter['visit']['identifier']['value'] = $this->visitUuid;

        if ($isEpisodeNew) {
            $encounter['episode']['identifier']['value'] = $this->episodeUuid;
        }

        // add system if priority is provided or when it's required
        if (isset($encounter['priority'])) {
            $encounter['priority']['coding'][0]['system'] = 'eHealth/encounter_priority';
        }

        $encounter['diagnoses'] = array_map(function (array $diagnose) {
            // Create a unique UUID for each diagnosis, and use them in condition
            $diagnoseUuid = Str::uuid()->toString();
            $diagnose['diagnoses']['condition']['identifier']['value'] = $diagnoseUuid;
            $this->diagnoseUuids[] = $diagnoseUuid;

            // delete rank if not provided
            if ($diagnose['diagnoses']['rank'] === '') {
                unset($diagnose['diagnoses']['rank']);
            }

            return $diagnose['diagnoses'];
        }, $conditions);

        if (isset($encounter['division']) && $encounter['division']['identifier']['value']) {
            $encounter['division']['identifier']['type']['coding'][0] = [
                'system' => 'eHealth/resources',
                'code' => 'division'
            ];
        }

        $encounterForm = $this->formatPeriod($encounter);

        return schemaService()
            ->setDataSchema(['encounter' => $encounterForm], app(PatientApi::class))
            ->requestSchemaNormalize()
            ->extractFirst()
            ->getNormalizedData();
    }

    /**
     * Format episode data before request.
     *
     * @param  array  $episode
     * @param  array  $encounterPeriod
     * @return array
     */
    public function formatEpisodeRequest(array $episode, array $encounterPeriod): array
    {
        $episode['id'] = $this->episodeUuid;
        $episode['managingOrganization']['identifier']['value'] = legalEntity()->uuid;
        $episode['period']['start'] = convertToEHealthISO8601(
            $encounterPeriod['date'] . ' ' . $encounterPeriod['start']
        );

        return schemaService()
            ->setDataSchema($episode, app(PatientApi::class))
            ->requestSchemaNormalize('schemaEpisodeRequest')
            ->camelCaseKeys()
            ->getNormalizedData();
    }

    /**
     * Format conditions data before request.
     *
     * @param  array  $conditions
     * @return array
     */
    public function formatConditionsRequest(array $conditions): array
    {
        $conditionForm = array_map(
            function (array $condition, int $index) {
                unset($condition['query']);
                // set ID same as diagnose
                $condition['id'] = $this->diagnoseUuids[$index];

                $condition['context']['identifier']['type']['coding'][0] = [
                    'system' => 'eHealth/resources',
                    'code' => 'encounter'
                ];
                $condition['context']['identifier']['value'] = $this->encounterUuid;

                // Remove coding with empty code
                $condition['code']['coding'] = array_values(
                    array_filter(
                        $condition['code']['coding'],
                        static fn (array $coding) => !empty($coding['code']) && trim($coding['code']) !== ''
                    )
                );

                // unset if code not provided
                if ($condition['severity']['coding'][0]['code'] === '') {
                    unset($condition['severity']);
                }

                if ($condition['primarySource']) {
                    $condition['asserter']['identifier']['value'] = $this->employeeUuid;

                    unset($condition['reportOrigin']);
                } else {
                    unset($condition['asserter']);
                }

                // convert dates
                if (isset($condition['onsetTime'])) {
                    $condition['onsetDate'] = convertToEHealthISO8601(
                        $condition['onsetDate'] . ' ' . $condition['onsetTime']
                    );
                    $condition['assertedDate'] = convertToEHealthISO8601(
                        $condition['assertedDate'] . ' ' . $condition['assertedTime']
                    );
                    unset($condition['onsetTime'], $condition['assertedTime'], $condition['diagnoses']);
                }

                if (!empty($condition['evidences'][0]['details'])) {
                    $condition['evidences'][0]['details'] = collect($condition['evidences'][0]['details'])
                        ->map(static function (array $detail) {
                            $data = [];

                            Arr::set($data, 'identifier.type.coding', [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => 'condition'
                                ]
                            ]);
                            Arr::set($data, 'identifier.value', $detail['id']);

                            return $data;
                        })->toArray();
                }

                if (empty($condition['evidences'][0]['codes']) && empty($condition['evidences'][0]['details'])) {
                    unset($condition['evidences']);
                }

                return $condition;
            },
            $conditions,
            array_keys($conditions)
        );

        return schemaService()
            ->setDataSchema(['conditions' => $conditionForm], app(PatientApi::class))
            ->requestSchemaNormalize()
            ->camelCaseKeys()
            ->extractFirst()
            ->getNormalizedData();
    }

    /**
     * Format immunizations data before request.
     *
     * @param  array  $immunizations
     * @return array
     */
    public function formatImmunizationsRequest(array $immunizations): array
    {
        $immunizationForm = array_map(function (array $immunization) {
            $immunization['id'] = Str::uuid()->toString();

            $immunization['status'] = 'completed';

            $immunization['context']['identifier']['type']['coding'][0] = [
                'system' => 'eHealth/resources',
                'code' => 'encounter'
            ];
            $immunization['context']['identifier']['value'] = $this->encounterUuid;

            if ($immunization['primarySource']) {
                unset($immunization['reportOrigin']);

                $immunization['performer']['identifier']['value'] = $this->employeeUuid;
            } else {
                unset($immunization['performer']);
            }

            if ($immunization['notGiven']) {
                unset($immunization['explanation']['reasons']);
            } else {
                unset($immunization['explanation']['reasonsNotGiven']);
            }

            if ($immunization['route']['coding'][0]['code'] === '') {
                unset($immunization['route']);
            }

            if ($immunization['site']['coding'][0]['code'] === '') {
                unset($immunization['site']);
            }

            if (is_null($immunization['doseQuantity']['value'])) {
                unset($immunization['doseQuantity']);
            }

            $immunization['date'] = convertToEHealthISO8601($immunization['date'] . ' ' . $immunization['time']);
            unset($immunization['time']);

            if ($immunization['expirationDate']) {
                $immunization['expirationDate'] = convertToEHealthISO8601(
                    $immunization['expirationDate'] . ' ' . now()->format('H:i')
                );
            }

            return removeEmptyKeys($immunization);
        }, $immunizations);

        return schemaService()
            ->setDataSchema(['immunizations' => $immunizationForm], app(PatientApi::class))
            ->requestSchemaNormalize()
            ->camelCaseKeys()
            ->extractFirst()
            ->getNormalizedData();
    }

    /**
     * Format observations data before request.
     *
     * @param  array  $observations
     * @return array
     */
    public function formatObservationsRequest(array $observations): array
    {
        $observationForm = array_map(function (array $observation) {
            unset($observation['codingSystem']);

            $observation['id'] = Str::uuid()->toString();
            $observation['status'] = 'valid';

            $observation['effectiveDateTime'] = convertToEHealthISO8601(
                $observation['effectiveDate'] . ' ' . $observation['effectiveTime']
            );
            unset($observation['effectiveDate'], $observation['effectiveTime']);

            if (empty($observation['effectiveDateTime'])) {
                unset($observation['effectiveDateTime']);
            }

            $observation['issued'] = convertToEHealthISO8601(
                $observation['issuedDate'] . ' ' . $observation['issuedTime']
            );
            unset($observation['issuedDate'], $observation['issuedTime']);

            $observation['context']['identifier']['type']['coding'][0] = [
                'system' => 'eHealth/resources',
                'code' => 'encounter'
            ];
            $observation['context']['identifier']['value'] = $this->encounterUuid;

            if ($observation['primarySource']) {
                unset($observation['reportOrigin']);

                $observation['performer']['identifier']['value'] = $this->employeeUuid;
            } else {
                unset($observation['performer']);
            }

            if ($observation['valueQuantity']['value'] === '') {
                unset($observation['valueQuantity']);
            }

            // format to codeable concept type
            if (isset($observation['valueCodeableConcept'])) {
                $observation['valueCodeableConcept'] = [
                    'coding' => [
                        [
                            'system' => $observation['dictionaryName'],
                            'code' => $observation['valueCodeableConcept'],
                        ]
                    ],
                    'text' => ''
                ];
            }

            if (isset($observation['dictionaryName'])) {
                unset($observation['dictionaryName']);
            }

            // combine date&time
            if (isset($observation['valueDate'], $observation['valueTime'])) {
                $observation['valueDateTime'] = convertToEHealthISO8601(
                    $observation['valueDate'] . ' ' . $observation['valueTime']
                );
                unset($observation['valueDate'], $observation['valueTime']);
            }

            if (empty($observation['bodySite']['coding'][0]['code'])) {
                unset($observation['bodySite']);
            }

            if (empty($observation['interpretation']['coding'][0]['code'])) {
                unset($observation['interpretation']);
            }

            if (empty($observation['method']['coding'][0]['code'])) {
                unset($observation['method']);
            }

            if ($observation['components'][0]['valueCodeableConcept']['coding'][0]['code'] === '') {
                unset($observation['components']);
            }

            if (isset($observation['components'][0]['interpretation']['coding'][0]['code']) && $observation['components'][0]['interpretation']['coding'][0]['code'] === '') {
                unset($observation['components']);
            }

            return $observation;
        }, $observations);

        return schemaService()
            ->setDataSchema(['observations' => $observationForm], app(PatientApi::class))
            ->requestSchemaNormalize()
            ->camelCaseKeys()
            ->extractFirst()
            ->getNormalizedData();
    }

    /**
     * Format diagnostic reports data before request.
     *
     * @param  array  $diagnosticReports
     * @param  string|null  $divisionUuid
     * @return array
     */
    public function formatDiagnosticReportsRequest(array $diagnosticReports, ?string $divisionUuid = null): array
    {
        $diagnosticReportForm = array_map(function (array $diagnosticReport) use ($divisionUuid) {
            // delete frontend properties
            unset($diagnosticReport['isReferralAvailable'], $diagnosticReport['referralType'], $diagnosticReport['query']);

            $diagnosticReport['id'] = Str::uuid()->toString();
            $diagnosticReport['status'] = 'final';

            if ($diagnosticReport['primarySource']) {
                unset($diagnosticReport['reportOrigin']);

                $diagnosticReport['performer']['reference']['identifier']['value'] = $this->employeeUuid;
            } else {
                unset($diagnosticReport['performer']);
            }

            if (empty($diagnosticReport['conclusionCode']['coding'][0]['code'])) {
                unset($diagnosticReport['conclusionCode']);
            }

            $diagnosticReport['recordedBy']['identifier']['value'] = $this->employeeUuid;

            $diagnosticReport['issued'] = convertToEHealthISO8601(
                $diagnosticReport['issuedDate'] . $diagnosticReport['issuedTime']
            );
            unset($diagnosticReport['issuedDate'], $diagnosticReport['issuedTime']);

            $diagnosticReport['effectivePeriod']['start'] = convertToEHealthISO8601(
                $diagnosticReport['effectivePeriodStartDate'] . $diagnosticReport['effectivePeriodStartTime']
            );
            unset($diagnosticReport['effectivePeriodStartDate'], $diagnosticReport['effectivePeriodStartTime']);

            $diagnosticReport['effectivePeriod']['end'] = convertToEHealthISO8601(
                $diagnosticReport['effectivePeriodEndDate'] . $diagnosticReport['effectivePeriodEndTime']
            );
            unset($diagnosticReport['effectivePeriodEndDate'], $diagnosticReport['effectivePeriodEndTime']);

            $diagnosticReport['encounter'] = [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']],
                        'text' => ''
                    ],
                    'value' => $this->encounterUuid
                ]
            ];

            $diagnosticReport['managingOrganization'] = [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'legal_entity']],
                        'text' => ''
                    ],
                    'value' => legalEntity()->uuid
                ],
            ];

            if (is_null($divisionUuid)) {
                unset($diagnosticReport['division']);
            } else {
                $diagnosticReport['division']['identifier']['value'] = $divisionUuid;
            }

            if (empty($diagnosticReport['resultsInterpreter']['reference']['identifier']['value'])) {
                unset($diagnosticReport['resultsInterpreter']);
            }

            return $diagnosticReport;
        }, $diagnosticReports);

        return schemaService()
            ->setDataSchema(['diagnostic_reports' => $diagnosticReportForm], app(PatientApi::class))
            ->requestSchemaNormalize()
            ->camelCaseKeys()
            ->extractFirst()
            ->getNormalizedData();
    }

    /**
     * Format procedures data before request.
     *
     * @param  array  $procedures
     * @return array
     */
    public function formatProceduresRequest(array $procedures): array
    {
        $procedureForm = array_map(function (array $procedure) {
            if ($procedure['referralType'] === 'electronic' || $procedure['referralType'] === '') {
                unset($procedure['paperReferral']);
            }

            // Delete frontend properties
            unset($procedure['isReferralAvailable'], $procedure['referralType']);

            $procedure['id'] = Str::uuid()->toString();
            $procedure['status'] = 'completed';

            $procedure['encounter'] = [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']],
                        'text' => ''
                    ],
                    'value' => $this->encounterUuid
                ]
            ];

            $procedure['recordedBy']['identifier']['value'] = $this->employeeUuid;

            if (empty($procedure['division']['identifier']['value'])) {
                unset($procedure['division']);
            }

            if ($procedure['primarySource']) {
                unset($procedure['reportOrigin']);

                $procedure['performer']['identifier']['value'] = $this->employeeUuid;
            } else {
                unset($procedure['performer']);
            }

            $procedure['performedPeriod']['start'] = convertToEHealthISO8601(
                $procedure['performedPeriodStartDate'] . $procedure['performedPeriodStartTime']
            );
            unset($procedure['performedPeriodStartDate'], $procedure['performedPeriodStartTime']);

            $procedure['performedPeriod']['end'] = convertToEHealthISO8601(
                $procedure['performedPeriodEndDate'] . $procedure['performedPeriodEndTime']
            );
            unset($procedure['performedPeriodEndDate'], $procedure['performedPeriodEndTime']);

            $procedure['managingOrganization'] = [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'legal_entity']],
                        'text' => ''
                    ],
                    'value' => legalEntity()->uuid
                ],
            ];

            if (!empty($procedure['reasonReferences'])) {
                foreach ($procedure['reasonReferences'] as &$reasonReference) {
                    $code = str_contains($reasonReference['code']['coding'][0]['system'], 'condition_codes')
                        ? 'condition'
                        : 'observation';

                    $identifier = [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => $code]]
                        ],
                        'value' => $reasonReference['id']
                    ];

                    // Keep only the identifier key
                    $reasonReference = ['identifier' => $identifier];
                }

                unset($reasonReference);
            }

            if (empty($procedure['outcome'])) {
                unset($procedure['outcome']);
            }

            if (empty($procedure['usedCodes'])) {
                unset($procedure['usedCodes']);
            }

            if (!empty($procedure['complicationDetails'])) {
                foreach ($procedure['complicationDetails'] as &$complicationDetail) {
                    $identifier = [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'condition']],
                        ],
                        'value' => $complicationDetail['id']
                    ];

                    // Keep only the identifier key
                    $complicationDetail = ['identifier' => $identifier];
                }

                unset($complicationDetail);
            }

            // Remove elements where the key is equal empty array
            return array_filter($procedure, static fn ($value) => !is_array($value) || !empty($value));
        }, $procedures);

        return schemaService()
            ->setDataSchema(['procedures' => $procedureForm], app(PatientApi::class))
            ->requestSchemaNormalize()
            ->camelCaseKeys()
            ->extractFirst()
            ->getNormalizedData();
    }

    /**
     * Format clinical impressions data before request.
     *
     * @param  array  $clinicalImpressions
     * @return array
     */
    public function formatClinicalImpressionsRequest(array $clinicalImpressions): array
    {
        $clinicalImpressionForm = array_map(function (array $clinicalImpression) {
            $clinicalImpression['id'] = Str::uuid()->toString();
            $clinicalImpression['status'] = 'completed';

            $clinicalImpression['encounter'] = [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']],
                        'text' => ''
                    ],
                    'value' => $this->encounterUuid
                ]
            ];

            $clinicalImpression['effectivePeriod']['start'] = convertToEHealthISO8601(
                $clinicalImpression['effectivePeriodStartDate'] . $clinicalImpression['effectivePeriodStartTime']
            );
            unset($clinicalImpression['effectivePeriodStartDate'], $clinicalImpression['effectivePeriodStartTime']);

            $clinicalImpression['effectivePeriod']['end'] = convertToEHealthISO8601(
                $clinicalImpression['effectivePeriodEndDate'] . $clinicalImpression['effectivePeriodEndTime']
            );
            unset($clinicalImpression['effectivePeriodEndDate'], $clinicalImpression['effectivePeriodEndTime']);

            $clinicalImpression['assessor']['identifier']['value'] = $this->employeeUuid;

            // TODO: після створення, додати форматування попередньої клінічної оцінки, вона має бути тільки одна

            if (!empty($clinicalImpression['problems'])) {
                $clinicalImpression['problems'] = collect($clinicalImpression['problems'])
                    ->map(static function (array $problem) {
                        $data = [];

                        Arr::set($data, 'identifier.type.coding', [
                            [
                                'system' => 'eHealth/resources',
                                'code' => 'condition'
                            ]
                        ]);
                        Arr::set($data, 'identifier.value', $problem['id']);

                        return $data;
                    })->toArray();
            }

            if (!empty($clinicalImpression['findings'])) {
                $clinicalImpression['findings'] = collect($clinicalImpression['findings'])
                    ->map(static function (array $finding) {
                        $data = [];

                        Arr::set($data, 'item_reference.identifier.type.coding', [
                            [
                                'system' => 'eHealth/resources',
                                'code' => $finding['type']
                            ]
                        ]);
                        Arr::set($data, 'item_reference.identifier.value', $finding['id']);

                        return $data;
                    })->toArray();
            }

            $clinicalImpression['supporting_info'] = [];

            if (!empty($clinicalImpression['supportingInfoEpisodes'])) {
                $supportingInfoEpisodes = collect($clinicalImpression['supportingInfoEpisodes'])
                    ->map(static function (array $supportingInfoEpisode) {
                        $data = [];

                        Arr::set($data, 'identifier.type.coding', [
                            [
                                'system' => 'eHealth/resources',
                                'code' => $supportingInfoEpisode['type']
                            ]
                        ]);
                        Arr::set($data, 'identifier.value', $supportingInfoEpisode['id']);

                        return $data;
                    })->toArray();

                $clinicalImpression['supporting_info'] = array_merge(
                    $clinicalImpression['supporting_info'],
                    $supportingInfoEpisodes
                );
            }

            if (!empty($clinicalImpression['supportingInfo'])) {
                $supportingInfo = collect($clinicalImpression['supportingInfo'])
                    ->map(static function (array $supportingInfo) {
                        $data = [];

                        Arr::set($data, 'identifier.type.coding', [
                            [
                                'system' => 'eHealth/resources',
                                'code' => $supportingInfo['type']
                            ]
                        ]);
                        Arr::set($data, 'identifier.value', $supportingInfo['id']);

                        return $data;
                    })->toArray();

                $clinicalImpression['supporting_info'] = array_merge(
                    $clinicalImpression['supporting_info'],
                    $supportingInfo
                );
            }

            unset($clinicalImpression['supportingInfo'], $clinicalImpression['supportingInfoEpisodes']);

            // Remove elements where the key is equal empty array
            return array_filter($clinicalImpression, static fn ($value) => !is_array($value) || !empty($value));
        }, $clinicalImpressions);

        return schemaService()
            ->setDataSchema(['clinical_impressions' => $clinicalImpressionForm], app(PatientApi::class))
            ->requestSchemaNormalize()
            ->camelCaseKeys()
            ->extractFirst()
            ->getNormalizedData();
    }

    /**
     * Format encounter period to ISO8601 format.
     *
     * @param  array  $encounterForm
     * @return array
     */
    public function formatPeriod(array $encounterForm): array
    {
        $encounterForm['period'] = [
            'start' => convertToEHealthISO8601(
                $encounterForm['period']['date'] . ' ' . $encounterForm['period']['start']
            ),
            'end' => convertToEHealthISO8601($encounterForm['period']['date'] . ' ' . $encounterForm['period']['end'])
        ];
        unset($encounterForm['period']['date']);

        return $encounterForm;
    }

    /**
     * Sync encounter data and related data by comparing existing data with API data.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingEncounters = $this->model->whereIn('uuid', $apiUuids)
                ->withRelationships()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEncounters->get($data['uuid']);

                $class = $this->syncCoding($existing, $data['class'] ?? null, 'class');
                $type = $this->syncCodeableConcept($existing, $data['type'] ?? null, 'type');
                $priority = $this->syncCodeableConcept($existing, $data['priority'] ?? null, 'priority');
                $performerSpeciality = $this->syncCodeableConcept(
                    $existing,
                    $data['performer_speciality'] ?? null,
                    'performerSpeciality'
                );

                $visit = $this->syncIdentifier($existing, $data['visit'] ?? null, 'visit');
                $episode = $this->syncIdentifier($existing, $data['episode'] ?? null, 'episode');
                $incomingReferral = $this->syncIdentifier(
                    $existing,
                    $data['incoming_referral'] ?? null,
                    'incomingReferral'
                );
                $originEpisode = $this->syncIdentifier(
                    $existing,
                    $data['origin_episode'] ?? null,
                    'originEpisode'
                );
                $performer = $this->syncIdentifier($existing, $data['performer'] ?? null, 'performer');
                $division = $this->syncIdentifier($existing, $data['division'] ?? null, 'division');

                $encounterData = [
                    'person_id' => $personId,
                    'status' => $data['status'],
                    'cancellation_reason' => $data['cancellation_reason'] ?? null,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'prescriptions' => $data['prescriptions'] ?? null,
                    'class_id' => $class?->id,
                    'type_id' => $type?->id,
                    'priority_id' => $priority?->id,
                    'performer_speciality_id' => $performerSpeciality?->id,
                    'visit_id' => $visit?->id,
                    'episode_id' => $episode?->id,
                    'incoming_referral_id' => $incomingReferral?->id,
                    'origin_episode_id' => $originEpisode?->id,
                    'performer_id' => $performer?->id,
                    'division_id' => $division?->id,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null
                ];

                if ($existing) {
                    $existing->update($encounterData);
                    $encounter = $existing;
                } else {
                    $encounter = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $encounterData)
                    );
                }

                Repository::period()->sync($encounter, $data['period']);

                $encounter->reasons()->sync(
                    $this->syncCodeableConcepts($existing, $data['reasons'] ?? null, 'reasons')
                );
                $encounter->actions()->sync(
                    $this->syncCodeableConcepts($existing, $data['actions'] ?? null, 'actions')
                );
                $encounter->actionReferences()->sync(
                    $this->syncIdentifiers($existing, $data['action_references'] ?? null, 'actionReferences')
                );
                $encounter->participants()->sync(
                    $this->syncIdentifiers($existing, $data['participant'] ?? null, 'participants')
                );
                $encounter->supportingInfo()->sync(
                    $this->syncIdentifiers($existing, $data['supporting_info'] ?? null, 'supportingInfo')
                );

                $this->syncHospitalization($encounter, $data['hospitalization'] ?? null);
            }
        });
    }

    /**
     * Sync encounter hospitalization (HasOne) with nested codings and destination identifier.
     *
     * @param  Encounter  $encounter
     * @param  array|null  $data
     * @return void
     */
    protected function syncHospitalization(Encounter $encounter, ?array $data): void
    {
        if (empty($data)) {
            $encounter->hospitalization?->delete();

            return;
        }

        $existingHospitalization = $encounter->hospitalization;

        $admitSource = $this->syncCoding(
            $existingHospitalization,
            $data['admit_source']['coding'][0] ?? null,
            'admitSource'
        );
        $reAdmission = $this->syncCoding(
            $existingHospitalization,
            $data['re_admission']['coding'][0] ?? null,
            'reAdmission'
        );
        $dischargeDisposition = $this->syncCoding(
            $existingHospitalization,
            $data['discharge_disposition']['coding'][0] ?? null,
            'dischargeDisposition'
        );
        $dischargeDepartment = $this->syncCoding(
            $existingHospitalization,
            $data['discharge_department']['coding'][0] ?? null,
            'dischargeDepartment'
        );
        $destination = $this->syncIdentifier($existingHospitalization, $data['destination'] ?? null, 'destination');

        $hospitalizationData = [
            'pre_admission_identifier' => $data['pre_admission_identifier'] ?? null,
            'admit_source_id' => $admitSource?->id,
            're_admission_id' => $reAdmission?->id,
            'destination_id' => $destination?->id,
            'discharge_disposition_id' => $dischargeDisposition?->id,
            'discharge_department_id' => $dischargeDepartment?->id
        ];

        if ($existingHospitalization) {
            $existingHospitalization->update($hospitalizationData);
        } else {
            $encounter->hospitalization()->create($hospitalizationData);
        }
    }
}

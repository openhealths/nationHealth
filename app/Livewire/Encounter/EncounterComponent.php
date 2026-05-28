<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Exceptions\ApiException as eHealthApiException;
use App\Classes\Cipher\Traits\Cipher;
use App\Classes\eHealth\Api\ServiceRequestApi;
use App\Core\Arr;
use App\Enums\Person\ClinicalImpressionStatus;
use App\Enums\Person\EpisodeStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Encounter\Forms\Api\EncounterRequestApi;
use App\Models\Employee\Employee;
use App\Models\Person\Person;
use App\Traits\FormTrait;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use App\Livewire\Encounter\Forms\EncounterForm as Form;
use Carbon\CarbonImmutable;
use Livewire\WithFileUploads;
use Throwable;

class EncounterComponent extends Component
{
    use FormTrait;
    use Cipher;
    use WithFileUploads;

    public Form $form;

    public bool $showSignatureModal = false;

    /**
     * ID of the patient for which create an encounter.
     *
     * @var int
     */
    #[Locked]
    public int $personId;

    /**
     * Patient full name.
     *
     * @var string
     */
    public string $patientFullName;

    /**
     * List of authorized user's divisions.
     *
     * @var array
     */
    public array $divisions;

    /**
     * List of existing patient episodes.
     *
     * @var array
     */
    public array $episodes = [];

    /**
     * List of patient episodes used only in the medical records search drawer.
     * This list is not limited to active episodes, because old medical records can belong to closed episodes.
     *
     * @var array
     */
    public array $medicalRecordEpisodes = [];

    /**
     * List of existing patient clinical impressions.
     *
     * @var array
     */
    public array $clinicalImpressions = [];

    /**
     * List of found encounters, procedures, or diagnostic reports for clinical impression supporting info.
     *
     * @var array
     */
    public array $supportingInfoResults = [];

    /**
     * Episode type, new or existing.
     *
     * @var string
     */
    public string $episodeType = 'new';

    /**
     * Full name of employee.
     *
     * @var string
     */
    public string $employeeFullName;

    /**
     * Patient UUID for API requests.
     *
     * @var string
     */
    public string $patientUuid;

    /**
     * Legal entity type of auth user.
     *
     * @var string
     */
    protected string $legalEntityType;

    /**
     * Role of auth user.
     *
     * @var string
     */
    protected string $role;

    /**
     * Found the ICD-10 code and description.
     *
     * @var array
     */
    public array $results;

    /**
     * List of LOINC observation codes per category.
     *
     * @var array
     */
    public array $observationLoincCodeMap;

    /**
     * List of custom observation codes per category.
     *
     * @var array
     */
    public array $observationCustomCodeMap;

    /**
     * List of observation values and type of data for specific categories.
     *
     * @var array
     */
    public array $observationValueMap;

    /**
     * Allowed condition codes per code system for the current user, based on employee type and speciality.
     * Key absent = no restriction; key present with empty array = system forbidden; key present with codes = allowed codes.
     *
     * @var array
     */
    public array $allowedConditionCodesBySystem = [];

    /**
     * List of values for codeable concept.
     *
     * @var array
     */
    public array $codeableConceptValues;

    public array $medicalRecordOptions = [];

    /**
     * List of employees of current legal entity.
     *
     * @var array
     */
    public array $employees;

    /**
     * List of founded conditions and observations.
     *
     * @var array
     */
    public array $evidenceDetails = [];

    /**
     * Selected services that should be displayed on edit even if they are not in the current dictionary list.
     *
     * @var array
     */
    public array $selectedServiceOptions = [];

    /**
     * Selected employees that should be displayed on edit even if they are not in the current legal entity list.
     *
     * @var array
     */
    public array $selectedEmployeeOptions = [];

    /**
     * List of founded conditions and observations.
     *
     * @var array
     */
    public array $conditionsAndObservations = [];

    /**
     * List of founded conditions or observations for clinical impression findings.
     *
     * @var array
     */
    public array $findingResults = [];

    /**
     * List of founded conditions for procedure complication details.
     *
     * @var array
     */
    public array $complicationDetailResults = [];

    /**
     * List of founded conditions or observations for procedure reason references.
     *
     * @var array
     */
    public array $reasonReferenceResults = [];

    /**
     * List of founded problems for current episode.
     *
     * @var array
     */
    public array $problems = [];

    /**
     * List of dictionary names.
     *
     * @var array|string[]
     */
    protected array $dictionaryNames = [
        'eHealth/encounter_statuses',
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'eHealth/encounter_priority',
        'eHealth/episode_types',
        'eHealth/ICPC2/condition_codes',
        'eHealth/ICPC2/reasons',
        'eHealth/ICPC2/actions',
        'eHealth/diagnosis_roles',
        'eHealth/condition_clinical_statuses',
        'eHealth/condition_verification_statuses',
        'eHealth/condition_severities',
        'eHealth/report_origins',
        'eHealth/reason_explanations',
        'eHealth/reason_not_given_explanations',
        'eHealth/immunization_report_origins',
        'eHealth/immunization_statuses',
        'eHealth/vaccine_codes',
        'eHealth/immunization_dosage_units',
        'eHealth/vaccination_routes',
        'eHealth/immunization_body_sites',
        'eHealth/vaccination_authorities',
        'eHealth/vaccination_target_diseases',
        'eHealth/observation_categories',
        'eHealth/ICF/observation_categories',
        'eHealth/LOINC/observation_codes',
        'eHealth/custom/observation_codes',
        'eHealth/stature',
        'eHealth/eye_colour',
        'eHealth/hair_color',
        'eHealth/hair_length',
        'GENDER',
        'eHealth/rankin_scale',
        'eHealth/LOINC/LL2009-0',
        'eHealth/LOINC/LL2021-5',
        'eHealth/occupation_type',
        'eHealth/vaccination_covid_groups',
        'eHealth/LOINC/LL2451-4',
        'eHealth/LOINC/LL360-9',
        'eHealth/LOINC/LL3841-5',
        'eHealth/LOINC/LL4129-4',
        'eHealth/LOINC/LL3250-9',
        'eHealth/LOINC/LL744-4',
        'eHealth/LOINC/LL951-5',
        'eHealth/LOINC/LL3290-5',
        'eHealth/LOINC/LL5128-5',
        'eHealth/LOINC/LL2478-7',
        'eHealth/LOINC/LL6876-8',
        'eHealth/LOINC/LL733-7',
        'eHealth/LOINC/LL1036-4',
        'eHealth/LOINC/LL2411-8',
        'eHealth/LOINC/LL6372-8',
        'eHealth/ICF/qualifiers',
        'eHealth/ICF/qualifiers/extent_or_magnitude_of_impairment',
        'eHealth/ICF/qualifiers/nature_of_change_in_body_structure',
        'eHealth/ICF/qualifiers/anatomical_localization',
        'eHealth/ICF/qualifiers/performance',
        'eHealth/ICF/qualifiers/capacity',
        'eHealth/ICF/qualifiers/barrier_or_facilitator',
        'eHealth/observation_methods',
        'eHealth/observation_interpretations',
        'eHealth/body_sites',
        'eHealth/ucum/units',
        'eHealth/diagnostic_report_categories',
        'eHealth/procedure_categories',
        'eHealth/procedure_outcomes',
        'eHealth/clinical_impression_patient_categories',
        'POSITION'
    ];

    public function boot(): void
    {
        $icd10Cache = $this->dictionaries['eHealth/ICD10_AM/condition_codes'] ?? [];

        $this->getDictionary();

        $this->dictionaries['eHealth/ICD10_AM/condition_codes'] = $icd10Cache;

        $this->observationLoincCodeMap = config('observation.category_codes.loinc', []);
        $this->observationCustomCodeMap = config('observation.category_codes.custom', []);
        $this->observationValueMap = config('observation.code_values');

        $this->dictionaries['eHealth/ICF/classifiers'] = dictionary()->basics()
            ->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();
        $this->dictionaries['eHealth/assistive_products'] = dictionary()->basics()
            ->byName('eHealth/assistive_products')
            ->flattenedChildValues()
            ->toArray();

        $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();
        $this->loadRuleEngineRules();

        $this->codeableConceptValues = collect(config('observation.code_values'))
            ->filter(static fn (array $value) => $value[1] === 'valueCodeableConcept')
            ->mapWithKeys(fn (array $value) => [
                $value[0] => $this->dictionaries[$value[0]] ?? [],
            ])
            ->toArray();

        $this->legalEntityType = legalEntity()->type->name;
        $this->role = Auth::user()->roles->first()->name;

        $this->adjustEpisodeTypes();
        $this->adjustEncounterClasses();
        $this->adjustEncounterTypes();
    }

    /**
     * Search for referral number.
     *
     * @return void
     * @throws eHealthApiException
     */
    public function searchForReferralNumber(): void
    {
        $buildSearchRequest = EncounterRequestApi::buildGetServiceRequestList($this->form->referralNumber);
        ServiceRequestApi::searchForServiceRequestsByParams($buildSearchRequest);
    }

    /**
     * Batch-fetch ICD-10 descriptions for given codes into $results.
     * Used by Alpine init() to populate icd10Descriptions without blocking the UI.
     *
     * @param  array  $codes
     * @return void
     */
    public function fetchIcd10Descriptions(array $codes): void
    {
        $this->results = DB::table('icd_10')
            ->whereIn('code', $codes)
            ->select(['code', 'description'])
            ->get()
            ->toArray();
    }

    /**
     * Search for ICD-10 in DB by the provided value.
     *
     * @param  string  $value
     * @return void
     */
    public function searchICD10(string $value): void
    {
        $query = DB::table('icd_10')
            ->select(['code', 'description'])
            ->where(function (Builder $query) use ($value) {
                $query->where('code', 'ILIKE', "%$value%")
                    ->orWhere('description', 'ILIKE', "%$value%");
            })
            ->limit(50);

        $allowedCodes = $this->allowedConditionCodesBySystem['eHealth/ICD10_AM/condition_codes'] ?? null;
        if ($allowedCodes !== null) {
            $query->whereIn('code', $allowedCodes);
        }

        $this->results = $query->get()->toArray();
    }

    /**
     * Initialize the component data based on the patient ID.
     *
     * @param  int  $personId
     * @return void
     */
    protected function initializeComponent(int $personId): void
    {
        $authUser = Auth::user();

        $employees = $authUser->party->employees()
            ->whereEmployeeType(Role::DOCTOR)
            ->select(['uuid', 'position', 'party_id'])
            ->with('party:id,last_name,first_name,second_name')
            ->whereLegalEntityId(legalEntity()->id)
            ->get();
        $this->employees = $employees->map(function (Employee $employee) {
            return [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position
            ];
        })->toArray();

        $this->personId = $personId;
        $this->legalEntityType = legalEntity()->type->name;
        $this->role = $authUser->roles->first()->name;
        $this->divisions = legalEntity()->divisions()->whereStatus(Status::ACTIVE)->get()->toArray();

        $encounterWriterEmployee = $authUser->getEncounterWriterEmployee();
        $this->employeeFullName = $encounterWriterEmployee->fullName;
        $this->allowedConditionCodesBySystem = $this->computeAllowedConditionCodesBySystem($encounterWriterEmployee);

        $this->setPatientData();

        // set division ID if only one exist
        if (count($this->divisions) === 1) {
            $this->form->encounter['divisionId'] = $this->divisions[0]['uuid'];
        }

        $this->getEpisodes();
    }

    /**
     * Search for conditions or observations by type.
     * Used for: evidence details (condition modal), reason references (procedure modal).
     *
     * @param  string  $type  'condition' or 'observation'
     * @return void
     */
    public function searchConditionsOrObservations(string $type): void
    {
        try {
            $this->evidenceDetails = $this->fetchConditionsOrObservations($type);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while getting evidence details');
        }
    }

    /**
     * Search conditions or observations to use as clinical impression findings.
     *
     * @param  string  $type  'condition' or 'observation'
     * @return void
     */
    public function searchFindings(string $type): void
    {
        try {
            $this->findingResults = $this->fetchConditionsOrObservations($type);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while getting findings');
        }
    }

    /**
     * Search conditions to use as procedure complication details.
     *
     * @return void
     */
    public function searchComplicationDetails(): void
    {
        try {
            $this->complicationDetailResults = $this->fetchConditionsOrObservations('condition');
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while getting complication details');
        }
    }

    /**
     * Search conditions or observations to use as procedure reason references.
     *
     * @param  string  $type  'condition' or 'observation'
     * @return void
     */
    public function searchReasonReferences(string $type): void
    {
        try {
            $this->reasonReferenceResults = $this->fetchConditionsOrObservations($type);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while getting reason references');
        }
    }

    /**
     * @param  string  $type  'condition' or 'observation'
     * @return array
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function fetchConditionsOrObservations(string $type): array
    {
        $api = $type === 'observation' ? EHealth::observation() : EHealth::condition();

        $response = $api->getBySearchParams(
            $this->patientUuid,
            ['managing_organization_id' => legalEntity()->uuid]
        );

        $results = collect($response->validate())
            ->when($type === 'observation', fn ($collection) => $collection->filter(
                static fn (array $item) => data_get($item, 'status') !== ObservationStatus::ENTERED_IN_ERROR->value
            ))
            ->map(static fn (array $item) => [
                'id' => data_get($item, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($item, 'ehealth_inserted_at')),
                'codeCode' => data_get($item, 'code.coding.0.code'),
                'codeSystem' => data_get($item, 'code.coding.0.system'),
                'type' => $type
            ])
            ->values()
            ->all();

        $this->loadIcd10Descriptions($results);

        return $results;
    }

    /**
     * Search for clinical impressions in episodes.
     *
     * @return void
     */
    public function searchClinicalImpressions(): void
    {
        if (!empty($this->clinicalImpressions)) {
            return;
        }

        try {
            $this->clinicalImpressions = collect(
                EHealth::clinicalImpression()->getSummary(
                    $this->patientUuid,
                    ['status' => ClinicalImpressionStatus::COMPLETED->value]
                )->validate()
            )->map(static function (array $item) {
                $item = Arr::toCamelCase($item);
                $item['ehealthInsertedAt'] = convertToAppDateFormat($item['ehealthInsertedAt'] ?? null);

                return $item;
            })->all();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while getting clinical impressions');

            return;
        }
    }

    /**
     * Search for complication details in conditions for selected episode.
     *
     * @return void
     */
    public function searchProblems(): void
    {
        if (!empty($this->problems)) {
            return;
        }

        try {
            $this->problems = collect(
                EHealth::condition()->getBySearchParams(
                    $this->patientUuid,
                    ['managing_organization_id' => legalEntity()->uuid]
                )->validate()
            )->map(static fn (array $item) => [
                'id' => data_get($item, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($item, 'ehealth_inserted_at')),
                'codeCode' => data_get($item, 'code.coding.0.code'),
                'codeSystem' => data_get($item, 'code.coding.0.system')
            ])
                ->values()
                ->all();

            $this->loadIcd10Descriptions($this->problems);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while searching for problems');
        }
    }

    /**
     * @param  string  $type  One of: episodes, encounter, procedure, diagnostic_report.
     * @return void
     */
    public function searchSupportingInfo(string $type): void
    {
        try {
            $params = ['managing_organization_id' => legalEntity()->uuid];

            $this->supportingInfoResults = match ($type) {
                'episodes' => collect($this->episodes)
                    ->map(fn (array $episode) => [
                        'uuid' => data_get($episode, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($episode, 'ehealthInsertedAt')),
                        'code' => data_get($episode, 'name'),
                        'type' => 'episode_of_care'
                    ])
                    ->values()
                    ->all(),
                'encounter' => collect(EHealth::encounter()->getBySearchParams($this->patientUuid, $params)->validate())
                    ->map(function (array $encounter) {
                        $primaryDiagnosis = collect(data_get($encounter, 'diagnoses', []))
                            ->first(fn (array $diagnosis) => data_get($diagnosis, 'role.coding.0.code') === 'primary');

                        return [
                            'uuid' => data_get($encounter, 'uuid'),
                            'ehealthInsertedAt' => convertToAppDateFormat(data_get($encounter, 'ehealth_inserted_at')),
                            'code' => data_get($primaryDiagnosis, 'code.coding.0.code'),
                            'type' => 'encounter'
                        ];
                    })
                    ->values()
                    ->all(),
                'procedure' => collect(EHealth::procedure()->getBySearchParams($this->patientUuid, $params)->validate())
                    ->map(fn (array $procedure) => [
                        'uuid' => data_get($procedure, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($procedure, 'ehealth_inserted_at')),
                        'code' => data_get($procedure, 'code.identifier.value'),
                        'type' => 'procedure'
                    ])
                    ->values()
                    ->all(),
                'diagnosticReport' => collect(
                    EHealth::diagnosticReport()->getBySearchParams($this->patientUuid, $params)->validate()
                )
                    ->map(fn (array $report) => [
                        'uuid' => data_get($report, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($report, 'ehealth_inserted_at')),
                        'code' => data_get($report, 'code.identifier.value'),
                        'type' => 'diagnostic_report'
                    ])
                    ->values()
                    ->all(),
                default => []
            };
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, "Error while searching for $type in Encounter Component");
        }
    }

    public function searchMedicalRecords(string $type = 'ALL', string $episodeId = 'ALL'): array
    {
        $episodeId = trim($episodeId);
        $type = trim($type);

        if (empty($this->medicalRecordEpisodes)) {
            $this->getMedicalRecordEpisodes();
        }

        $allowedTypes = ['condition', 'observation', 'diagnostic_report'];
        $types = $type === 'ALL' || $type === ''
            ? $allowedTypes
            : array_values(array_intersect([$type], $allowedTypes));

        if (empty($types)) {
            return $this->medicalRecordOptions = [];
        }

        try {
            return $this->medicalRecordOptions = collect($types)
                ->flatMap(function (string $recordType) use ($episodeId) {
                    return collect($this->medicalRecordEpisodeIdsForSearch($episodeId))
                        ->flatMap(fn (?string $recordEpisodeId) => $this->searchMedicalRecordsByType($recordType, $recordEpisodeId))
                        ->toArray();
                })
                ->unique(fn (array $record) => $record['type'].'-'.$record['id'])
                ->sortByDesc('sortDate')
                ->take(10)
                ->map(static function (array $record) {
                    unset($record['sortDate']);

                    return $record;
                })
                ->values()
                ->toArray();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while searching medical records for Encounter');

            return $this->medicalRecordOptions = [];
        } catch (Throwable $exception) {
            Log::error('Error while searching medical records for Encounter', [
                'message' => $exception->getMessage(),
                'type' => $type,
                'episode_id' => $episodeId,
            ]);

            return $this->medicalRecordOptions = [];
        }
    }

    private function searchMedicalRecordsByType(string $type, ?string $episodeId = null): array
    {
        $params = [
            'managing_organization_id' => legalEntity()->uuid,
        ];

        if (filled($episodeId)) {
            if ($type === 'diagnostic_report') {
                $params['origin_episode_id'] = $episodeId;
            } else {
                $params['episode_id'] = $episodeId;
            }
        }

        $response = match ($type) {
            'condition' => EHealth::condition()->getBySearchParams($this->patientUuid, $params),
            'observation' => EHealth::observation()->getBySearchParams($this->patientUuid, $params),
            'diagnostic_report' => EHealth::diagnosticReport()->getBySearchParams($this->patientUuid, $params),
        };

        return collect($this->medicalRecordsData($response))
            ->when($type === 'observation', fn ($collection) => $collection->reject(
                static fn (array $item) => data_get($item, 'status') === ObservationStatus::ENTERED_IN_ERROR->value
            ))
            ->map(fn (array $record) => $this->medicalRecordOption($record, $type))
            ->values()
            ->toArray();
    }

    private function medicalRecordEpisodeIdsForSearch(string $episodeId): array
    {
        if ($episodeId !== '' && $episodeId !== 'ALL') {
            return [$episodeId];
        }

        $episodeIds = collect($this->medicalRecordEpisodes)
            ->map(fn (array $episode) => data_get($episode, 'uuid'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return $episodeIds ?: [null];
    }

    private function medicalRecordsData(mixed $response): array
    {
        $data = $response->getData();

        return data_get($data, 'data', $data) ?: [];
    }

    private function medicalRecordOption(array $record, string $type): array
    {
        $code = $this->medicalRecordCode($record, $type);
        $name = $this->medicalRecordName($record, $type, $code);
        $rawDate = collect($this->medicalRecordDatePaths($type))
            ->map(fn (string $path) => data_get($record, $path))
            ->first(fn ($value) => filled($value));

        return [
            'id' => data_get($record, 'uuid', data_get($record, 'id')),
            'type' => $type,
            'typeLabel' => $this->medicalRecordTypeLabel($type),
            'code' => $code,
            'name' => $name ?: $code,
            'date' => $this->formatRecordDate($rawDate),
            'episode' => $this->medicalRecordEpisode($record),
            'sortDate' => $rawDate,
        ];
    }

    private function medicalRecordCode(array $record, string $type): string
    {
        if ($type === 'diagnostic_report') {
            return collect([
                'code.identifier.value',
                'codeIdentifier.value',
                'code.value',
                'codeValue',
            ])
                ->map(fn (string $path) => data_get($record, $path))
                ->first(fn ($value) => filled($value));
        }

        return collect([
            'code.coding.0.code',
            'codeCode',
            'code.code',
        ])
            ->map(fn (string $path) => data_get($record, $path))
            ->first(fn ($value) => filled($value));
    }

    private function medicalRecordName(array $record, string $type, string $code): string
    {
        if ($type === 'diagnostic_report') {
            $fallback = collect([
                'code.displayValue',
                'code.display_value',
                'code.identifier.type.text',
                'conclusion',
            ])
                ->map(fn (string $path) => data_get($record, $path))
                ->first(fn ($value) => filled($value));

            return $this->serviceLabel($code, $fallback);
        }

        $system = collect([
            'code.coding.0.system',
            'codeSystem',
            'code.system',
        ])
            ->map(fn (string $path) => data_get($record, $path))
            ->first(fn ($value) => filled($value));

        $fallback = collect([
            'code.text',
            'codeText',
            'name',
        ])
            ->map(fn (string $path) => data_get($record, $path))
            ->first(fn ($value) => filled($value));

        return $this->dictionaryLabel($system, $code, $fallback);
    }

    private function medicalRecordEpisode(array $record): ?string
    {
        return collect([
            'episode.identifier.value',
            'episode.value',
            'episode.uuid',
            'episodeId',
            'episode_id',
            'originEpisode.identifier.value',
            'originEpisodeId',
            'origin_episode_id',
            'contextEpisode.identifier.value',
            'contextEpisodeId',
            'context_episode_id',
        ])
            ->map(fn (string $path) => data_get($record, $path))
            ->first(fn ($value) => filled($value));
    }

    private function medicalRecordDatePaths(string $type): array
    {
        return match ($type) {
            'condition' => ['onsetDate', 'onset_date', 'assertedDate', 'asserted_date', 'ehealthInsertedAt', 'ehealth_inserted_at'],
            'observation' => ['effectiveDateTime', 'effective_date_time', 'issued', 'issuedDate', 'ehealthInsertedAt', 'ehealth_inserted_at'],
            'diagnostic_report' => ['issued', 'issuedDate', 'effectivePeriod.start', 'effective_period.start', 'ehealthInsertedAt', 'ehealth_inserted_at'],
            default => ['ehealthInsertedAt', 'ehealth_inserted_at'],
        };
    }

    private function formatRecordDate(mixed $date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            return CarbonImmutable::parse($date)->format('d.m.Y');
        } catch (Throwable) {
            return $date;
        }
    }

    private function dictionaryLabel(?string $system, ?string $code, ?string $fallback = ''): string
    {
        if (!$system || !$code) {
            return ($fallback ?: $code ?: '');
        }

        $value = data_get($this->dictionaries, "$system.$code");

        if (is_array($value)) {
            return data_get($value, 'name', data_get($value, 'label', $code));
        }

        return ($value ?: $fallback ?: $code);
    }

    private function serviceLabel(?string $id, ?string $fallback = ''): string
    {
        if (!$id) {
            return $fallback;
        }

        $service = $this->serviceOptionById($id);

        return data_get($service, 'name', $fallback ?: $id);
    }

    protected function selectedServiceOptionsFromEncounter(array $encounter): array
    {
        return collect(data_get($encounter, 'action_references', []))
            ->map(function (array $item) {
                $id = data_get($item, 'identifier.value', '');

                if ($id === '') {
                    return null;
                }

                $service = $this->serviceOptionById($id);

                return [
                    'id' => $id,
                    'code' => data_get($service, 'code', ''),
                    'name' => (data_get($service, 'name', '')),
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->toArray();
    }

    private function serviceOptionById(string $id): ?array
    {
        $service = collect($this->dictionaries['custom/services'] ?? [])
            ->first(fn ($service, $key) => data_get($service, 'id', $key) === $id);

        return is_array($service) ? $service : null;
    }

    protected function selectedEmployeeOptionsFromEncounter(array $encounter): array
    {
        $ids = collect(data_get($encounter, 'participants', []))
            ->map(fn (array $item) => data_get($item, 'identifier.value', ''))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $employees = Employee::query()
            ->whereIn('uuid', $ids->all())
            ->select(['uuid', 'position', 'party_id'])
            ->with('party:id,last_name,first_name,second_name')
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position,
            ])
            ->keyBy('id');

        return $ids
            ->map(function (string $id) use ($employees) {
                $employee = $employees->get($id);

                if ($employee) {
                    return $employee;
                }

                return [
                    'id' => $id,
                    'name' => $id,
                    'position' => '',
                ];
            })
            ->values()
            ->toArray();
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


    protected function setPatientData(): void
    {
        $patient = Person::select(['uuid', 'first_name', 'last_name', 'second_name'])
            ->whereId($this->personId)
            ->firstOrFail();

        $this->patientUuid = $patient->uuid;
        $this->patientFullName = $patient->fullName;
    }

    /**
     * Adjust episode types according to legal entity type and employee type.
     *
     * @return void
     */
    protected function adjustEpisodeTypes(): void
    {
        $keys = $this->getFilteredKeysFromConfig(
            "legal_entity_episode_types.$this->legalEntityType",
            "employee_episode_types.$this->role"
        );

        $this->adjustDictionary('eHealth/episode_types', $keys);
    }
    
    /**
     * Show encounter classes based on legal entity and employee type.
     *
     * @return void
     */
    protected function adjustEncounterClasses(): void
    {
        $keys = $this->getFilteredKeysFromConfig(
            "legal_entity_encounter_classes.$this->legalEntityType",
            "performer_employee_encounter_classes.$this->role"
        );

        $this->adjustDictionary('eHealth/encounter_classes', $keys);

        // set default encounter class, if there is only one
        if (count($this->dictionaries['eHealth/encounter_classes']) === 1) {
            $this->form->encounter['classCode'] = array_key_first($this->dictionaries['eHealth/encounter_classes']);
        }
    }

    /**
     * Show encounter types based on encounter class.
     *
     * @return void
     */
    protected function adjustEncounterTypes(): void
    {
        $selectedClass = key($this->dictionaries['eHealth/encounter_classes']);
        $keys = $this->getFilteredKeysFromConfig("encounter_class_encounter_types.$selectedClass");

        $this->adjustDictionary('eHealth/encounter_types', $keys);
    }
    
    /**
     * Get active episodes for current patient.
     * Used by the main encounter episode selector.
     *
     * @return void
     */
    protected function getEpisodes(): void
    {
        try {
            $this->episodes = $this->getEpisodesBySearchParams([
                'managing_organization_id' => legalEntity()->uuid,
                'status' => EpisodeStatus::ACTIVE->value,
            ]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting episodes');

            return;
        }
    }

    /**
     * Get all available episodes for current patient.
     * Used by the medical records search drawer.
     *
     * @return void
     */
    protected function getMedicalRecordEpisodes(): void
    {
        try {
            $this->medicalRecordEpisodes = $this->getEpisodesBySearchParams([
                'managing_organization_id' => legalEntity()->uuid,
            ]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting episodes for medical records search');

            return;
        }
    }

    /**
     * Get all pages for episodes search params.
     *
     * @param  array  $params
     * @return array
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function getEpisodesBySearchParams(array $params): array
    {
        $episodes = collect();
        $page = 1;

        do {
            $response = EHealth::episode()->getBySearchParams(
                $this->patientUuid,
                array_merge($params, ['page' => $page, 'page_size' => 100])
            );

            $episodes = $episodes->merge($response->validate());
            $page++;
        } while ($response->isNotLast());

        return $episodes
            ->unique('uuid')
            ->values()
            ->toArray();
    }

    /**
     * Load rules from the API and save them into the cache.
     *
     * @return void
     */
    protected function loadRuleEngineRules(): void
    {
        $this->dictionaries['custom/rule_engine_rule_list'] = Cache::remember(
            'rule_engine_rule_list',
            now()->addDays(7),
            static fn () => EHealth::ruleEngineRules()->getMany()->getData()
        );

        foreach ($this->dictionaries['custom/rule_engine_rule_list'] as $dictionary) {
            $cacheKey = "rule_engine_details_{$dictionary['code']['code']}";

            $details = Cache::remember(
                $cacheKey,
                now()->addDays(7),
                static fn () => EHealth::ruleEngineRules()->get($dictionary['id'])->getData()
            );

            $this->dictionaries['custom/rule_engine_details'][$details['code']['code']] = $details;
        }
    }

    /**
     * Compute allowed condition codes per code system for the current user.
     * Key absent means no restriction; empty array means the system is forbidden; non-empty array lists the allowed codes.
     * Combines employee-type restrictions with officio-speciality restrictions, intersecting ICD-10 AM when both apply.
     *
     * @param  Employee  $employee
     * @return array
     */
    private function computeAllowedConditionCodesBySystem(Employee $employee): array
    {
        $employeeTypeRestrictions = config("ehealth.employee_type_conditions_allowed.$employee->employeeType");

        $speciality = $employee->loadMissing('specialities')
            ->specialities
            ->firstWhere('speciality_officio', true)
            ?->speciality;
        $specialityIcd10Codes = $speciality
            ? config("ehealth.icd10am_speciality_conditions_allowed.$speciality")
            : null;

        $result = [];
        $icd10Key = 'eHealth/ICD10_AM/condition_codes';
        $icpc2Key = 'eHealth/ICPC2/condition_codes';

        $employeeIcd10Codes = $employeeTypeRestrictions !== null
            ? ($employeeTypeRestrictions[$icd10Key] ?? [])
            : null;

        if ($employeeIcd10Codes !== null && $specialityIcd10Codes !== null) {
            $result[$icd10Key] = array_values(array_intersect($employeeIcd10Codes, $specialityIcd10Codes));
        } elseif ($employeeIcd10Codes !== null) {
            $result[$icd10Key] = $employeeIcd10Codes;
        } elseif ($specialityIcd10Codes !== null) {
            $result[$icd10Key] = $specialityIcd10Codes;
        }

        if ($employeeTypeRestrictions !== null) {
            $result[$icpc2Key] = $employeeTypeRestrictions[$icpc2Key] ?? [];
        }

        return $result;
    }

    /**
     * Adjust dictionaries by provided key and values.
     */
    private function adjustDictionary(string $dictionaryKey, array $allowedValues): void
    {
        $this->dictionaries[$dictionaryKey] = Arr::only($this->dictionaries[$dictionaryKey], $allowedValues);
    }
}
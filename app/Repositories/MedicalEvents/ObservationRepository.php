<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\PatientApi;
use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\ObservationComponent;
use App\Models\MedicalEvents\Sql\Quantity;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ObservationRepository extends BaseRepository
{
    protected string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $this->employeeUuid = Auth::user()?->getDiagnosticReportWriterEmployee()->uuid;
    }

    /**
     * Format data before request.
     *
     * @param  array  $observations
     * @param  string  $diagnosticReportUuid
     * @return array
     */
    public function formatEHealthRequest(array $observations, string $diagnosticReportUuid): array
    {
        $observationForm = array_map(function (array $observation) use ($diagnosticReportUuid) {
            // Delete frontend properties
            unset($observation['codingSystem']);

            // Connect with diagnostic report
            $observation['diagnosticReport'] = [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            [
                                'system' => 'eHealth/resources',
                                'code' => 'diagnostic_report'
                            ]
                        ]
                    ],
                    'value' => $diagnosticReportUuid
                ]
            ];

            $observation['id'] = Str::uuid()->toString();
            $observation['status'] = 'valid';

            if (isset($observation['dictionaryName'])) {
                unset($observation['dictionaryName']);
            }

            $observation['effectiveDateTime'] = convertToISO8601(
                $observation['effectiveDate'] . $observation['effectiveTime']
            );
            unset($observation['effectiveDate'], $observation['effectiveTime']);

            $observation['issued'] = convertToISO8601($observation['issuedDate'] . $observation['issuedTime']);
            unset($observation['issuedDate'], $observation['issuedTime']);

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
                            'system' => 'eHealth/' . $observation['code']['coding'][0]['code'],
                            'code' => $observation['valueCodeableConcept']
                        ]
                    ],
                    'text' => ''
                ];
            }

            // combine date&time
            if (isset($observation['valueDate'], $observation['valueTime'])) {
                $observation['valueDateTime'] = convertToISO8601($observation['valueDate'] . $observation['valueTime']);
                unset($observation['valueDate'], $observation['valueTime']);
            }

            if (empty($observation['interpretation']['coding'][0]['code'])) {
                unset($observation['interpretation']);
            }

            if (empty($observation['bodySite']['coding'][0]['code'])) {
                unset($observation['bodySite']);
            }

            if (empty($observation['method']['coding'][0]['code'])) {
                unset($observation['method']);
            }

            if ($observation['code']['coding'][0]['system'] !== 'eHealth/ICF/classifiers') {
                unset($observation['components']);
            }

            return $observation;
        }, $observations);

        return schemaService()
            ->setDataSchema(['observations' => $observationForm], app(PatientApi::class))
            ->requestSchemaNormalize('schemaDiagnosticReportPackageRequest')
            ->camelCaseKeys()
            ->getNormalizedData();
    }

    /**
     * Store observation in DB.
     *
     * @param  array  $data
     * @param  int  $personId
     * @param  int|null  $encounterId
     * @param  int|null  $diagnosticReportId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId, ?int $encounterId = null, ?int $diagnosticReportId = null): void
    {
        DB::transaction(function () use ($data, $personId, $encounterId, $diagnosticReportId) {
            try {
                foreach ($data as $datum) {
                    if ($diagnosticReportId) {
                        $diagnosticReport = Repository::identifier()
                            ->store($datum['diagnosticReport']['identifier']['value']);
                        Repository::codeableConcept()->attach($diagnosticReport, $datum['diagnosticReport']);
                    }

                    $code = Repository::codeableConcept()->store($datum['code']);

                    if (isset($datum['performer'])) {
                        $performer = Repository::identifier()->store($datum['performer']['identifier']['value']);
                        Repository::codeableConcept()->attach($performer, $datum['performer']);
                    }

                    if (isset($datum['reportOrigin'])) {
                        $reportOrigin = Repository::codeableConcept()->store($datum['reportOrigin']);
                    }

                    if (isset($datum['interpretation'])) {
                        $interpretation = Repository::codeableConcept()->store($datum['interpretation']);
                    }

                    if (isset($datum['bodySite'])) {
                        $bodySite = Repository::codeableConcept()->store($datum['bodySite']);
                    }

                    if (isset($datum['method'])) {
                        $method = Repository::codeableConcept()->store($datum['method']);
                    }

                    $valueQuantity = null;
                    if (isset($datum['valueQuantity'])) {
                        $valueQuantity = [
                            'value' => $datum['valueQuantity']['value'],
                            'comparator' => $datum['valueQuantity']['comparator'] ?? null,
                            'unit' => $datum['valueQuantity']['unit'] ?? null,
                            'system' => $datum['valueQuantity']['system'] ?? null,
                            'code' => $datum['valueQuantity']['code'] ?? null
                        ];
                    }

                    if (isset($datum['valueCodeableConcept'])) {
                        $valueCodeableConcept = Repository::codeableConcept()->store($datum['valueCodeableConcept']);
                    }

                    if (isset($datum['context'])) {
                        $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                        Repository::codeableConcept()->attach($context, $datum['context']);
                    }

                    /** @var Observation $observation */
                    $observation = $this->model::create([
                        'uuid' => $datum['uuid'] ?? $datum['id'],
                        'person_id' => $personId,
                        'encounter_id' => $encounterId,
                        'status' => $datum['status'],
                        'diagnostic_report_id' => $diagnosticReport->id ?? null,
                        'code_id' => $code->id,
                        'effective_date_time' => $datum['effectiveDateTime'] ?? null,
                        'issued' => $datum['issued'],
                        'primary_source' => $datum['primarySource'],
                        'performer_id' => $performer->id ?? null,
                        'report_origin_id' => $reportOrigin->id ?? null,
                        'interpretation_id' => $interpretation->id ?? null,
                        'comment' => $datum['comment'] ?? null,
                        'body_site_id' => $bodySite->id ?? null,
                        'method_id' => $method->id ?? null,
                        'value_codeable_concept_id' => $valueCodeableConcept->id ?? null,
                        'value_string' => $datum['valueString'] ?? null,
                        'value_boolean' => $datum['valueBoolean'] ?? null,
                        'value_date_time' => $datum['valueDateTime'] ?? null,
                        'context_id' => $context->id ?? null
                    ]);

                    // Create polymorphic quantity relationship if needed
                    if ($valueQuantity) {
                        $observation->valueQuantity()->create($valueQuantity);
                    }

                    $categoriesIds = [];

                    foreach ($datum['categories'] as $categoryData) {
                        $category = Repository::codeableConcept()->store($categoryData);

                        $categoriesIds[] = $category->id;
                    }

                    $observation->categories()->attach($categoriesIds);

                    if (isset($datum['components'])) {
                        foreach ($datum['components'] as $componentData) {
                            $valueCodeableConcept = Repository::codeableConcept()
                                ->store($componentData['valueCodeableConcept']);
                            $interpretation = Repository::codeableConcept()->store($componentData['interpretation']);

                            ObservationComponent::create([
                                'observation_id' => $observation->id,
                                'codeable_concept_id' => $valueCodeableConcept->id,
                                'interpretation_id' => $interpretation->id
                            ]);
                        }
                    }
                }
            } catch (Exception $e) {
                Log::channel('db_errors')->error('Error saving observation', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);

                throw $e;
            }
        });
    }

    /**
     * Get observation data that is related to the encounter.
     *
     * @param  int  $encounterId
     * @return array|null
     */
    public function get(int $encounterId): ?array
    {
        return $this->model::with([
            'categories.coding',
            'code.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'interpretation.coding',
            'bodySite.coding',
            'method.coding',
            'valueQuantity',
            'valueCodeableConcept.coding',
            'reactionOn.type.coding',
            'components.valueCodeableConcept.coding',
            'components.interpretation.coding'
        ])
            ->where('encounter_id', $encounterId)
            ->get()
            ?->toArray();
    }

    /**
     * Get the observation for the procedure based on the provided UUID to display the selected complication detail.
     *
     * @param  string  $uuid
     * @return array|null
     */
    public function getForProcedure(string $uuid): ?array
    {
        return Observation::whereUuid($uuid)
            ->select(['id', 'onset_date', 'code_id'])
            ->with('code.coding')
            ->first()
            ?->toArray();
    }

    /**
     * Formatting to show on the frontend.
     *
     * @param  array  $observations
     * @return array
     */
    public function formatForView(array $observations): array
    {
        return array_map(static function (array $observation) {
            if (empty($observation['reportOrigin'])) {
                $observation['reportOrigin'] = [
                    'coding' => [
                        ['code' => '']
                    ]
                ];
            }

            if ($observation['categories'][0]['coding'][0]['system'] === 'eHealth/observation_categories') {
                $observation['codingSystem'] = 'loinc';
            } else {
                $observation['codingSystem'] = 'icf';
            }

            return $observation;
        }, $observations);
    }

    /**
     * Sync observation data and related data by deleting and creating.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            // Get UUIDs from API data
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingObservations = $this->model::whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingObservations->get($data['uuid']);

                // Sync relationships
                $code = $this->syncCodeableConcept($existing, $data['code'], 'code');
                $context = $this->syncIdentifier($existing, $data['context'] ?? null, 'context');
                $performer = $this->syncIdentifier($existing, $data['performer'] ?? null, 'performer');
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $diagnosticReport = $this->syncIdentifier(
                    $existing,
                    $data['diagnostic_report'] ?? null,
                    'diagnosticReport'
                );
                $specimen = $this->syncIdentifier($existing, $data['specimen'] ?? null, 'specimen');
                $device = $this->syncIdentifier($existing, $data['device'] ?? null, 'device');
                $interpretation = $this->syncCodeableConcept(
                    $existing,
                    $data['interpretation'] ?? null,
                    'interpretation'
                );
                $bodySite = $this->syncCodeableConcept($existing, $data['body_site'] ?? null, 'bodySite');
                $method = $this->syncCodeableConcept($existing, $data['method'] ?? null, 'method');

                // Create or update main observation
                $observation = $this->model::updateOrCreate(
                    ['uuid' => $data['uuid']],
                    array_merge(
                        [
                            'person_id' => $personId,
                            'code_id' => $code->id,
                            'context_id' => $context?->id,
                            'performer_id' => $performer?->id,
                            'report_origin_id' => $reportOrigin?->id,
                            'diagnostic_report_id' => $diagnosticReport?->id,
                            'specimen_id' => $specimen?->id,
                            'device_id' => $device?->id,
                            'interpretation_id' => $interpretation?->id,
                            'body_site_id' => $bodySite?->id,
                            'method_id' => $method?->id
                        ],
                        Arr::except($data, [
                            'code',
                            'context',
                            'performer',
                            'report_origin',
                            'diagnostic_report',
                            'specimen',
                            'device',
                            'interpretation',
                            'body_site',
                            'method',
                            'reference_ranges',
                            'categories',
                            'components'
                        ])
                    )
                );

                // Sync categories
                $categoryIds = $this->syncCodeableConcepts($existing, $data['categories'], 'categories');
                $observation->categories()->sync($categoryIds);

                // Sync components
                $this->syncComponents($observation, $existing, $data['components'] ?? []);
            }
        });
    }

    /**
     * Sync observation components.
     *
     * @param  Observation  $observation
     * @param  Observation|null  $existing
     * @param  array  $componentsData
     * @return void
     */
    private function syncComponents(Observation $observation, ?Observation $existing, array $componentsData): void
    {
        $existingComponents = $existing?->components ?? collect();

        foreach ($componentsData as $index => $componentData) {
            $existingComponent = $existingComponents[$index] ?? null;

            // Sync component relationships
            if ($existingComponent) {
                // Update existing component's relationships
                $code = $existingComponent->code;
                if ($code) {
                    $this->updateCodeableConcept($code, $componentData['code']);
                } else {
                    $code = Repository::codeableConcept()->store($componentData['code']);
                }

                $interpretation = null;
                if (isset($componentData['interpretation'])) {
                    if ($existingComponent->interpretation) {
                        $this->updateCodeableConcept(
                            $existingComponent->interpretation,
                            $componentData['interpretation']
                        );
                        $interpretation = $existingComponent->interpretation;
                    } else {
                        $interpretation = Repository::codeableConcept()->store($componentData['interpretation']);
                    }
                }

                $valueCodeableConcept = $existingComponent->valueCodeableConcept;
                if ($valueCodeableConcept) {
                    $this->updateCodeableConcept($valueCodeableConcept, $componentData['value_codeable_concept']);
                } else {
                    $valueCodeableConcept = Repository::codeableConcept()->store(
                        $componentData['value_codeable_concept']
                    );
                }

                // Update the component record
                $existingComponent->update([
                    'code_id' => $code->id,
                    'interpretation_id' => $interpretation?->id,
                    'value_codeable_concept_id' => $valueCodeableConcept->id
                ]);
            } else {
                // Create new component
                $componentCode = Repository::codeableConcept()->store($componentData['code']);

                $componentInterpretation = null;
                if (isset($componentData['interpretation'])) {
                    $componentInterpretation = Repository::codeableConcept()->store($componentData['interpretation']);
                }

                $componentValueCodeableConcept = Repository::codeableConcept()->store(
                    $componentData['value_codeable_concept']
                );

                ObservationComponent::create([
                    'observation_id' => $observation->id,
                    'code_id' => $componentCode->id,
                    'interpretation_id' => $componentInterpretation?->id,
                    'value_codeable_concept_id' => $componentValueCodeableConcept->id
                ]);
            }
        }
    }
}

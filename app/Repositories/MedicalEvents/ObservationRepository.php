<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\PatientApi;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\ObservationComponent;
use App\Models\MedicalEvents\Sql\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property Observation $model
 */
class ObservationRepository extends BaseRepository
{
    protected ?string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $this->employeeUuid = Auth::user()?->getDiagnosticReportWriterEmployee()?->uuid;
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
                if ($this->employeeUuid) {
                    $observation['performer']['identifier']['value'] = $this->employeeUuid;
                }
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
     * @param  int|null  $diagnosticReportId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId, ?int $diagnosticReportId = null): void
    {
        DB::transaction(function () use ($data, $personId, $diagnosticReportId) {
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

                if (isset($datum['context'])) {
                    $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                    Repository::codeableConcept()->attach($context, $datum['context']);
                }

                $observation = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    'person_id' => $personId,
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
                    'context_id' => $context->id ?? null
                ]);

                $this->storeValue($datum, $observation);

                $categoriesIds = [];

                foreach ($datum['categories'] as $categoryData) {
                    $category = Repository::codeableConcept()->store($categoryData);

                    $categoriesIds[] = $category->id;
                }

                $observation->categories()->attach($categoriesIds);

                if (isset($datum['components'])) {
                    foreach ($datum['components'] as $componentData) {
                        $componentCode = Repository::codeableConcept()->store($componentData['code']);
                        $componentInterpretation = Repository::codeableConcept()->store(
                            $componentData['interpretation']
                        );

                        $component = ObservationComponent::create([
                            'observation_id' => $observation->id,
                            'code_id' => $componentCode->id,
                            'interpretation_id' => $componentInterpretation->id
                        ]);

                        $this->storeValue($componentData, $component);
                    }
                }
            }
        });
    }

    /**
     * Store all value fields for an observation or component into the values table.
     *
     * @param  array  $datum
     * @param  Observation|ObservationComponent  $owner
     * @return void
     */
    private function storeValue(array $datum, Observation|ObservationComponent $owner): void
    {
        $valueData = [];

        if (isset($datum['valueQuantity'])) {
            $quantity = Quantity::create([
                'value' => $datum['valueQuantity']['value'],
                'comparator' => $datum['valueQuantity']['comparator'] ?? null,
                'unit' => $datum['valueQuantity']['unit'] ?? null,
                'system' => $datum['valueQuantity']['system'] ?? null,
                'code' => $datum['valueQuantity']['code'] ?? null
            ]);
            $valueData['value_quantity_id'] = $quantity->id;
        }

        if (isset($datum['valueCodeableConcept'])) {
            $valueCodeableConcept = Repository::codeableConcept()->store($datum['valueCodeableConcept']);
            $valueData['value_codeable_concept_id'] = $valueCodeableConcept->id;
        }

        if (isset($datum['valueString'])) {
            $valueData['value_string'] = $datum['valueString'];
        }

        if (isset($datum['valueBoolean'])) {
            $valueData['value_boolean'] = $datum['valueBoolean'];
        }

        if (isset($datum['valueDateTime'])) {
            $valueData['value_date_time'] = $datum['valueDateTime'];
        }

        if (isset($datum['valueTime'])) {
            $valueData['value_time'] = $datum['valueTime'];
        }

        if (!empty($valueData)) {
            $owner->value()->create($valueData);
        }
    }

    /**
     * Build a UUID => [insertedAt, codeCode] map for the given observation UUIDs.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        return collect(
            $this->model->whereIn('uuid', $uuids)
                ->with('code.coding')
                ->get()
                ->toArray()
        )
            ->mapWithKeys(fn (array $observation) => [
                $observation['uuid'] => [
                    'insertedAt' => $observation['ehealthInsertedAt'] ?? null,
                    'codeCode' => data_get($observation, 'code.coding.0.code'),
                    'type' => 'observation'
                ]
            ])
            ->toArray();
    }

    /**
     * Get observation data that is related to the encounter.
     *
     * @param  string  $encounterUuid
     * @return array|null
     */
    public function get(string $encounterUuid): ?array
    {
        return $this->model::with([
            'categories.coding',
            'code.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'interpretation.coding',
            'bodySite.coding',
            'method.coding',
            'value.valueQuantity',
            'value.valueCodeableConcept.coding',
            'reactionOn.type.coding',
            'components.code.coding',
            'components.value.valueCodeableConcept.coding',
            'components.interpretation.coding'
        ])
            ->whereHas('context', fn ($query) => $query->where('value', $encounterUuid))
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
     * Sync observation data and related data by deleting and creating.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @param  string|null  $encounterUuid
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData, ?string $encounterUuid = null): void
    {
        DB::transaction(function () use ($personId, $validatedData, $encounterUuid) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            if ($encounterUuid !== null) {
                $this->model
                    ->whereHas('context', fn ($q) => $q->where('value', $encounterUuid))
                    ->whereNotIn('uuid', $apiUuids)
                    ->with(['components.value', 'value'])
                    ->get()
                    ->each(function (Observation $observation): void {
                        $observation->categories()->detach();
                        $observation->components->each(function (ObservationComponent $component): void {
                            $component->value()->delete();
                            $component->delete();
                        });
                        $observation->value()->delete();
                        $observation->delete();
                    });
            }

            $existingObservations = $this->model->whereIn('uuid', $apiUuids)
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

                $observationData = [
                    'person_id' => $personId,
                    'status' => $data['status'],
                    'code_id' => $code->id,
                    'context_id' => $context?->id,
                    'performer_id' => $performer?->id,
                    'report_origin_id' => $reportOrigin?->id,
                    'diagnostic_report_id' => $diagnosticReport?->id,
                    'specimen_id' => $specimen?->id,
                    'device_id' => $device?->id,
                    'interpretation_id' => $interpretation?->id,
                    'body_site_id' => $bodySite?->id,
                    'method_id' => $method?->id,
                    'effective_date_time' => $data['effective_date_time'] ?? null,
                    'issued' => $data['issued'] ?? null,
                    'primary_source' => $data['primary_source'] ?? null,
                    'comment' => $data['comment'] ?? null
                ];

                if ($existing) {
                    $existing->update($observationData);
                    $observation = $existing;
                } else {
                    $observation = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $observationData)
                    );
                }

                $this->syncValue($data, $observation);

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

            if ($existingComponent) {
                if ($existingComponent->code) {
                    $this->updateCodeableConcept($existingComponent->code, $componentData['code']);
                    $code = $existingComponent->code;
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

                $existingComponent->update([
                    'code_id' => $code->id,
                    'interpretation_id' => $interpretation?->id
                ]);

                $this->syncValue($componentData, $existingComponent);
            } else {
                $componentCode = Repository::codeableConcept()->store($componentData['code']);

                $componentInterpretation = null;
                if (isset($componentData['interpretation'])) {
                    $componentInterpretation = Repository::codeableConcept()->store($componentData['interpretation']);
                }

                $component = ObservationComponent::create([
                    'observation_id' => $observation->id,
                    'code_id' => $componentCode->id,
                    'interpretation_id' => $componentInterpretation?->id
                ]);

                $this->syncValue($componentData, $component);
            }
        }
    }

    /**
     * Sync all value fields for an observation or component into the values table.
     *
     * @param  array  $data
     * @param  Observation|ObservationComponent  $owner
     * @return void
     */
    private function syncValue(array $data, Observation|ObservationComponent $owner): void
    {
        $existingValue = $owner->value;
        $valueData = [];

        if (isset($data['value_quantity'])) {
            $quantityData = [
                'value' => $data['value_quantity']['value'],
                'comparator' => $data['value_quantity']['comparator'] ?? null,
                'unit' => $data['value_quantity']['unit'] ?? null,
                'system' => $data['value_quantity']['system'] ?? null,
                'code' => $data['value_quantity']['code'] ?? null
            ];

            if ($existingValue?->valueQuantity) {
                $existingValue->valueQuantity->update($quantityData);
                $valueData['value_quantity_id'] = $existingValue->valueQuantity->id;
            } else {
                $valueData['value_quantity_id'] = Quantity::create($quantityData)->id;
            }
        }

        if (isset($data['value_codeable_concept'])) {
            if ($existingValue?->valueCodeableConcept) {
                $this->updateCodeableConcept($existingValue->valueCodeableConcept, $data['value_codeable_concept']);
                $valueData['value_codeable_concept_id'] = $existingValue->valueCodeableConcept->id;
            } else {
                $valueData['value_codeable_concept_id'] = Repository::codeableConcept()
                    ->store($data['value_codeable_concept'])->id;
            }
        }

        if (isset($data['value_string'])) {
            $valueData['value_string'] = $data['value_string'];
        }

        if (isset($data['value_boolean'])) {
            $valueData['value_boolean'] = $data['value_boolean'];
        }

        if (isset($data['value_date_time'])) {
            $valueData['value_date_time'] = $data['value_date_time'];
        }

        if (isset($data['value_time'])) {
            $valueData['value_time'] = $data['value_time'];
        }

        if (empty($valueData)) {
            return;
        }

        if ($existingValue) {
            $existingValue->update($valueData);
        } else {
            $owner->value()->create($valueData);
        }
    }
}

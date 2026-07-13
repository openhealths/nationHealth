<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\ObservationComponent;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Quantity;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public function getByDiagnosticReportId(int $diagnosticReportId): array
    {
        $diagnosticReportUuid = DiagnosticReport::query()
            ->whereKey($diagnosticReportId)
            ->value('uuid');

        if (!$diagnosticReportUuid) {
            return [];
        }

        return $this->model
            ->withAllRelations()
            ->whereHas('diagnosticReport', fn (Builder $query) => $query->where('value', $diagnosticReportUuid))
            ->get()
            ->toArray();
    }

    /**
     * Store observation in DB.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @param  int|null  $diagnosticReportId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient, ?int $diagnosticReportId = null): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($data, $ownerColumn, $ownerId, $diagnosticReportId) {
            foreach ($data as $datum) {
                $diagnosticReport = null;
                if ($diagnosticReportId) {
                    $diagnosticReport = Repository::identifier()
                        ->store($datum['diagnosticReport']['identifier']['value']);
                    Repository::codeableConcept()->attach($diagnosticReport, $datum['diagnosticReport']);
                }

                $code = Repository::codeableConcept()->store($datum['code']);

                $performer = null;
                if (isset($datum['performer'])) {
                    $performer = Repository::identifier()->store($datum['performer']['identifier']['value']);
                    Repository::codeableConcept()->attach($performer, $datum['performer']);
                }

                $context = null;
                if (isset($datum['context'])) {
                    $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                    Repository::codeableConcept()->attach($context, $datum['context']);
                }

                $observation = $this->model->updateOrCreate(
                    ['uuid' => $datum['uuid'] ?? $datum['id']],
                    [
                        $ownerColumn => $ownerId,
                        'status' => $datum['status'],
                        'diagnostic_report_id' => $diagnosticReport?->id,
                        'code_id' => $code->id,
                        'effective_date_time' => $datum['effectiveDateTime'] ?? null,
                        'issued' => $datum['issued'],
                        'primary_source' => $datum['primarySource'],
                        'performer_id' => $performer?->id,
                        'report_origin_id' => isset($datum['reportOrigin'])
                            ? Repository::codeableConcept()->store($datum['reportOrigin'])->id
                            : null,
                        'interpretation_id' => isset($datum['interpretation'])
                            ? Repository::codeableConcept()->store($datum['interpretation'])->id
                            : null,
                        'comment' => $datum['comment'] ?? null,
                        'body_site_id' => isset($datum['bodySite'])
                            ? Repository::codeableConcept()->store($datum['bodySite'])->id
                            : null,
                        'method_id' => isset($datum['method'])
                            ? Repository::codeableConcept()->store($datum['method'])->id
                            : null,
                        'context_id' => $context?->id
                    ]
                );

                if (!$observation->wasRecentlyCreated) {
                    $observation->value()?->delete();
                    $observation->categories()->detach();
                    foreach ($observation->components as $component) {
                        $component->value()?->delete();
                        $component->delete();
                    }
                }

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

                        $component = $observation->components()->create([
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
                    'ehealthInsertedAt' => $observation['ehealthInsertedAt'] ?? null,
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
            'components.value.valueQuantity',
            'components.value.valueCodeableConcept.coding',
            'components.value.valueRange.low',
            'components.value.valueRange.high',
            'components.value.valueRatio.numerator',
            'components.value.valueRatio.denominator',
            'components.value.valueSampledData',
            'components.interpretation.coding'
        ])
            ->whereHas('context', fn (Builder $query) => $query->where('value', $encounterUuid))
            ->get()
            ?->toArray();
    }

    /**
     * Get observations data that is related to the patient (person or preperson).
     *
     * @param  Person|Preperson  $patient
     * @return array
     */
    public function getByPersonId(Person|Preperson $patient): array
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return $this->model
            ->withAllRelations()
            ->where($ownerColumn, $ownerId)
            ->orderByDesc('issued')
            ->orderByDesc('ehealth_inserted_at')
            ->orderByDesc('id')
            ->get()
            ->toArray();
    }

    /**
     * Sync observation data and related data by deleting and creating.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @param  string|null  $encounterUuid
     * @return void
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData, ?string $encounterUuid = null): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData, $encounterUuid) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            if ($encounterUuid !== null) {
                $this->model->whereNotIn('uuid', $apiUuids)
                    ->whereHas('context', fn (Builder $query) => $query->where('value', $encounterUuid))
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
                    $ownerColumn => $ownerId,
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
                    'issued' => $data['issued'],
                    'primary_source' => $data['primary_source'],
                    'comment' => $data['comment'] ?? null,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
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

                $categoryIds = $this->syncCodeableConcepts($existing, $data['categories'], 'categories');
                $this->syncPivot($observation, 'categories', $categoryIds);

                $this->syncComponents($observation, $data['components'] ?? []);
            }
        });
    }

    /**
     * Sync observation components.
     *
     * @param  Observation  $observation
     * @param  array  $componentsData
     * @return void
     */
    private function syncComponents(Observation $observation, array $componentsData): void
    {
        $existingComponents = $observation->relationLoaded('components')
            ? $observation->components
            : collect();

        if (empty($componentsData)) {
            $existingComponents->each(fn (ObservationComponent $component) => $component->delete());

            return;
        }

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

                $component = $observation->components()->create([
                    'code_id' => $componentCode->id,
                    'interpretation_id' => $componentInterpretation?->id
                ]);

                $this->syncValue($componentData, $component);
            }
        }

        foreach ($existingComponents->slice(count($componentsData)) as $extra) {
            $extra->delete();
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
        $existingValue = $owner->relationLoaded('value') ? $owner->value : null;
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

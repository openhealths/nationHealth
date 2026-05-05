<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CarePlanRepository
{
    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return CarePlan::where('legal_entity_id', $legalEntityId)
            ->with(['person', 'author.party', 'encounter.diagnoses.condition'])
            ->latest()
            ->get();
    }

    public function getByPersonId(int $personId): Collection
    {
        return CarePlan::where('person_id', $personId)
            ->with(['person', 'author.party', 'encounter.diagnoses.condition'])
            ->latest()
            ->get();
    }
    
    public function findById(int $id): ?CarePlan
    {
        return CarePlan::with(['person', 'author.party', 'activities'])->find($id);
    }
    
    public function findByUuid(string $uuid): ?CarePlan
    {
        return CarePlan::with(['person', 'author.party', 'activities'])->where('uuid', $uuid)->first();
    }
    
    public function create(array $data): CarePlan
    {
        return CarePlan::create($data);
    }
    
    public function update(CarePlan $carePlan, array $data): bool
    {
        return $carePlan->update($data);
    }

    public function updateById(int $id, array $data): bool
    {
        $carePlan = CarePlan::find($id);
        if (!$carePlan) {
            return false;
        }
        return $carePlan->update($data);
    }

    /**
     * Format Care Plan data into the proper FHIR schema for eHealth API requests.
     */
    public function formatCarePlanRequest(array $form, ?string $encounterUuid, array $encounterData, ?string $employeeUuid): array
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        $addresses = [];
        if (!empty($encounterData['diagnoses'][0]['condition']['identifier']['value'])) {
            $addresses[] = [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'condition']]
                    ],
                    'value' => $encounterData['diagnoses'][0]['condition']['identifier']['value']
                ]
            ];
        }

        $employeeRef = [
            'identifier' => [
                'type' => [
                    'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]
                ],
                'value' => $employeeUuid
            ]
        ];

        return \App\Core\Arr::removeEmptyKeys([
            'id' => $id,
            'intent' => $form['intent'] ?? 'order',
            'status' => 'new',
            'category' => [
                'coding' => [
                    ['system' => 'eHealth/care_plan_categories', 'code' => $form['category']]
                ]
            ],
            'instantiates_protocol' => !empty($form['clinical_protocol']) ? [['display' => $form['clinical_protocol']]] : null,
            'title' => $form['title'],
            'period' => array_filter([
                'start' => convertToEHealthISO8601($form['period_start'] . ' 00:00:00'),
                'end' => !empty($form['period_end']) ? convertToEHealthISO8601($form['period_end'] . ' 23:59:59') : null,
            ]),
            'addresses' => !empty($addresses) ? $addresses : null,
            'supporting_info' => array_merge(
                array_map(fn($e) => [
                    'identifier' => [
                        'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'episode_of_care']]],
                        'value' => $e['uuid'] ?? $e['id'] ?? null
                    ]
                ], $form['episodes'] ?? []),
                array_map(fn($m) => [
                    'identifier' => [
                        'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'observation']]],
                        'value' => $m['uuid'] ?? $m['id'] ?? null
                    ]
                ], $form['medical_records'] ?? [])
            ),
            'encounter' => !empty($form['encounter']) ? [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']]
                    ],
                    'value' => $form['encounter']
                ]
            ] : null,
            'author' => $employeeRef,
            'care_manager' => $employeeRef,
            'description' => $form['description'] ?: null,
            'note' => $form['note'] ?: null,
            'inform_with' => !empty($form['inform_with']) ? [
                'coding' => [
                    ['system' => 'eHealth/inform_with', 'code' => $form['inform_with']]
                ]
            ] : null,
            'terms_of_service' => [
                'coding' => [
                    ['system' => 'eHealth/care_provision_conditions', 'code' => $form['terms_of_service']]
                ]
            ]
        ]);
    }

    public function syncCarePlans(\App\Models\Person\Person $person, array $query = []): void
    {
        $response = EHealth::carePlan()->getSummary($person->uuid, $query);
        $data = $response->getData();

        if (!isset($data['data']) || !is_array($data['data'])) {
            return;
        }

        $validator = Validator::make($data['data'], [
            '*' => 'array',
            '*.id' => 'required|uuid',
            '*.status' => 'required|string',
            '*.title' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $activityRepo = app(CarePlanActivityRepository::class);

        foreach ($data['data'] as $rawFhir) {
            \App\Models\MedicalEvents\Mongo\CarePlan::updateOrCreate(
                ['uuid' => $rawFhir['id']],
                ['data' => $rawFhir]
            );

            DB::transaction(function () use ($person, $rawFhir, $activityRepo) {
                $category = isset($rawFhir['category'])
                    ? MedicalEventsRepository::codeableConcept()->store($rawFhir['category'])
                    : null;

                $encounterIdentifier = isset($rawFhir['encounter'])
                    ? MedicalEventsRepository::identifier()->store($rawFhir['encounter']['identifier']['value'])
                    : null;
                if ($encounterIdentifier && isset($rawFhir['encounter']['identifier']['type'])) {
                    MedicalEventsRepository::codeableConcept()->attach($encounterIdentifier, $rawFhir['encounter']);
                }

                $careManager = isset($rawFhir['careManager'])
                    ? MedicalEventsRepository::identifier()->store($rawFhir['careManager']['identifier']['value'])
                    : null;
                if ($careManager && isset($rawFhir['careManager']['identifier']['type'])) {
                    MedicalEventsRepository::codeableConcept()->attach($careManager, $rawFhir['careManager']);
                }

                $carePlan = CarePlan::updateOrCreate(
                    ['uuid' => $rawFhir['id']],
                    [
                        'person_id' => $person->id,
                        'status' => $rawFhir['status'],
                        'title' => $rawFhir['title'],
                        'description' => $rawFhir['description'] ?? null,
                        'note' => $rawFhir['note'] ?? null,
                        'category_id' => $category?->id,
                        'encounter_identifier_id' => $encounterIdentifier?->id,
                        'care_manager_id' => $careManager?->id,
                        'period_start' => isset($rawFhir['period']['start']) ? \Carbon\Carbon::parse($rawFhir['period']['start']) : null,
                        'period_end' => isset($rawFhir['period']['end']) ? \Carbon\Carbon::parse($rawFhir['period']['end']) : null,
                    ]
                );

                if (isset($rawFhir['period'])) {
                    MedicalEventsRepository::period()->sync($carePlan, $rawFhir['period'], 'effectivePeriod');
                }

                if (isset($rawFhir['supportingInfo'])) {
                    $supportingInfoIds = [];
                    foreach ($rawFhir['supportingInfo'] as $info) {
                        $identifier = MedicalEventsRepository::identifier()->store($info['identifier']['value']);
                        if (isset($info['identifier']['type'])) {
                            MedicalEventsRepository::codeableConcept()->attach($identifier, $info);
                        }
                        $supportingInfoIds[] = $identifier->id;
                    }
                    $carePlan->supportingInfoReferences()->sync($supportingInfoIds);
                }

                // Trigger sync for activities directly for each plan found active or relevant
                $activityRepo->syncActivities($person, $carePlan);
            });
        }
    }
}

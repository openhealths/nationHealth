<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\CarePlanStatus;
use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CarePlanRepository
{
    public function getByLegalEntity(int $legalEntityId, array $filters = []): Collection
    {
        $query = CarePlan::where('legal_entity_id', $legalEntityId)
            ->with(['person', 'author.party', 'encounter.diagnoses.condition', 'encounterIdentifier']);

        if (!empty($filters['name'])) {
            $query->where('title', 'like', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['encounter_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('encounter_id', $filters['encounter_id'])
                    ->orWhereHas('encounterIdentifier', function ($qi) use ($filters) {
                        $qi->where('value', 'like', '%' . $filters['encounter_id'] . '%');
                    });
            });
        }

        return $query->latest()->get();
    }

    public function getByPersonId(int $personId, array $filters = []): Collection
    {
        $query = CarePlan::where('person_id', $personId)
            ->with(['person', 'author.party', 'encounter.diagnoses.condition', 'encounterIdentifier']);

        if (!empty($filters['name'])) {
            $query->where('title', 'like', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['encounter_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('encounter_id', $filters['encounter_id'])
                    ->orWhereHas('encounterIdentifier', function ($qi) use ($filters) {
                        $qi->where('value', 'like', '%' . $filters['encounter_id'] . '%');
                    });
            });
        }

        return $query
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
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
    public function formatCarePlanRequest(array $form, ?string $encounterUuid, array $encounterData, ?string $employeeUuid, ?string $carePlanUuid = null): array
    {
        $id = $carePlanUuid ?: \Illuminate\Support\Str::uuid()->toString();

        $addresses = $encounterData['addresses'] ?? [];

        $employeeRef = [
            'identifier' => [
                'type' => [
                    'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]
                ],
                'value' => $employeeUuid
            ]
        ];

        // Use encounter period start if available to satisfy eHealth rule:
        // "Care plan start date must be greater or equal than Encounter period start"
        $periodStart = $form['periodStart'] ?? $form['period_start'];
        if (!empty($encounterData['period_start'])) {
            // Encounter start is already in UTC from DB
            $encounterStart = \Carbon\CarbonImmutable::parse($encounterData['period_start'], 'UTC');

            // Form date is Kyiv time (e.g. "12.05.2026")
            $formStart = \Carbon\CarbonImmutable::parse($periodStart, config('app.timezone', 'Europe/Kyiv'))->startOfDay();

            // If the selected day is the same or earlier than the encounter's day,
            // we MUST use the encounter's actual start time to avoid the 422 error.
            if ($formStart->utc()->lt($encounterStart)) {
                // If user picked today but encounter started later today,
                // we set care plan start exactly to encounter start + 1 minute for safety
                $periodStart = $encounterStart->addMinute()->toDateTimeString();

                // Since convertToEHealthISO8601 parses and converts to UTC again,
                // we need to pass it a format it understands or bypass it.
                // To keep it simple, we'll use a direct ISO string if we already have UTC.
                $finalPeriodStart = $encounterStart->addMinute()->toIso8601ZuluString();
            } else {
                $finalPeriodStart = convertToEHealthISO8601($periodStart . ' 00:00:00');
            }
        } else {
            $finalPeriodStart = convertToEHealthISO8601($periodStart . ' 00:00:00');
        }

        $payload = removeEmptyKeys([
            'id' => $id,
            'intent' => 'order',
            'status' => 'new',
            'category' => [
                'coding' => [
                    ['system' => 'eHealth/care_plan_categories', 'code' => $form['category']]
                ]
            ],
            'instantiates_protocol' => !empty($form['clinicalProtocol']) ? [['display' => $form['clinicalProtocol']]] : (!empty($form['clinical_protocol']) ? [['display' => $form['clinical_protocol']]] : null),
            'title' => $form['title'],
            'period' => array_filter([
                'start' => $finalPeriodStart,
                'end' => !empty($form['periodEnd']) ? convertToEHealthISO8601($form['periodEnd'] . ' 23:59:59') : (!empty($form['period_end']) ? convertToEHealthISO8601($form['period_end'] . ' 23:59:59') : null),
            ]),
            'addresses' => !empty($addresses) ? array_values($addresses) : null,
            'supporting_info' => array_values(array_filter(array_map(fn ($e) =>
                (!empty($e['uuid']) || !empty($e['id'])) ? [
                    'identifier' => [
                        'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'episode_of_care']]],
                        'value' => $e['uuid'] ?? $e['id']
                    ]
                ] : null, $form['episodes'] ?? []))),
            'encounter' => !empty($form['encounter']) ? [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']]
                    ],
                    'value' => $form['encounter']
                ]
            ] : null,
            'author' => $employeeRef,
            'description' => $form['description'] ?: null,
            'note' => $form['note'] ?: null,
            'terms_of_service' => [
                'coding' => [
                    ['system' => 'PROVIDING_CONDITION', 'code' => $form['termsOfService'] ?? $form['terms_of_service']]
                ]
            ]
        ]);

        return $payload;
    }

    public function syncCarePlans(array $validatedData, ?int $personId = null): void
    {
        $activityRepo = app(CarePlanActivityRepository::class);
        $plans = isset($validatedData['data']) ? $validatedData['data'] : $validatedData;

        foreach ($plans as $rawFhir) {
            $person = null;

            if ($personId) {
                $person = \App\Models\Person\Person::find($personId);
            } else {
                // Try to find person by subject identifier (patient UUID)
                $patientUuid = $rawFhir['subject']['identifier']['value'] ?? null;
                if ($patientUuid) {
                    $person = \App\Models\Person\Person::where('uuid', $patientUuid)->first();
                }
            }

            if (!$person) {
                \Illuminate\Support\Facades\Log::warning('CarePlanRepository: person not found for CarePlan sync', [
                    'care_plan_uuid' => $rawFhir['id'] ?? $rawFhir['uuid'] ?? 'missing',
                    'patient_uuid' => $rawFhir['subject']['identifier']['value'] ?? 'missing'
                ]);
                continue;
            }

            // Decide whether we need to fetch full details from eHealth
            $carePlan = CarePlan::where('uuid', $rawFhir['id'] ?? $rawFhir['uuid'] ?? null)->first();
            if (!$carePlan) {
                // Try finding by encounter identifier
                $encounterIdentifierRaw = $rawFhir['encounter'] ?? null;
                $encounterIdentifierVal = $encounterIdentifierRaw['identifier']['value'] ?? null;
                if ($encounterIdentifierVal) {
                    $encounterIdentifier = \App\Models\MedicalEvents\Sql\Identifier::where('value', $encounterIdentifierVal)->first();
                    if ($encounterIdentifier) {
                        $carePlan = CarePlan::where('person_id', $person->id)
                            ->where('encounter_identifier_id', $encounterIdentifier->id)
                            ->whereNull('uuid')
                            ->first();
                    }
                }
            }

            $needsDetails = false;
            if (!$carePlan) {
                if (empty($rawFhir['title'])) {
                    $needsDetails = true;
                }
            } else {
                $remoteStatus = $rawFhir['status'] ?? 'active';
                $localStatus = $carePlan->status;

                if ($localStatus !== $remoteStatus) {
                    $needsDetails = true;
                } elseif ($carePlan->title === 'План лікування' && empty($rawFhir['title'])) {
                    $needsDetails = true;
                }
            }

            if ($needsDetails) {
                try {
                    $detailResponse = EHealth::carePlan()->getDetails($person->uuid, $rawFhir['id'] ?? $rawFhir['uuid']);
                    $detailData = $detailResponse->validate();
                    if (!empty($detailData)) {
                        $rawFhir = array_merge($rawFhir, $detailData);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('CarePlanRepository: failed to fetch details for care plan ' . ($rawFhir['id'] ?? $rawFhir['uuid']), [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // TODO: Move raw FHIR data storage to MongoDB when the driver and collection are ready.
            // Currently disabled to prevent conflicts with the SQL 'care_plans' table.
            /*
            \App\Models\MedicalEvents\Mongo\CarePlan::updateOrCreate(
                ['uuid' => $rawFhir['id'] ?? $rawFhir['uuid'] ?? null],
                ['data' => $rawFhir]
            );
            */

            DB::transaction(function () use ($person, $rawFhir, $activityRepo, $carePlan) {
                $categoryData = isset($rawFhir['category']) && is_array($rawFhir['category'])
                    ? ($rawFhir['category'][0] ?? null)
                    : ($rawFhir['category'] ?? null);

                $category = $categoryData
                    ? MedicalEventsRepository::codeableConcept()->store($categoryData)
                    : null;

                $encounterIdentifier = isset($rawFhir['encounter']['identifier']['value'])
                    ? MedicalEventsRepository::identifier()->store($rawFhir['encounter']['identifier']['value'])
                    : null;

                if ($encounterIdentifier && isset($rawFhir['encounter']['identifier']['type'])) {
                    MedicalEventsRepository::codeableConcept()->attach($encounterIdentifier, $rawFhir['encounter']);
                }

                $careManagerRaw = $rawFhir['care_manager'] ?? ($rawFhir['careManager'] ?? null);
                $careManager = isset($careManagerRaw['identifier']['value'])
                    ? MedicalEventsRepository::identifier()->store($careManagerRaw['identifier']['value'])
                    : null;

                if ($careManager && isset($careManagerRaw['identifier']['type'])) {
                    MedicalEventsRepository::codeableConcept()->attach($careManager, $careManagerRaw);
                }

                $author = null;
                $authorUuid = $rawFhir['author']['identifier']['value'] ?? null;
                if ($authorUuid) {
                    $author = \App\Models\Employee\Employee::where('uuid', $authorUuid)->first();
                }

                // Fallback to current user if author not found (to satisfy NOT NULL constraint)
                $authorId = $author?->id ?? Auth::user()?->getCarePlanWriterEmployee()?->id;

                $addresses = [];
                if (isset($rawFhir['addresses']) && is_array($rawFhir['addresses'])) {
                    foreach ($rawFhir['addresses'] as $addr) {
                        if (isset($addr['coding']) && is_array($addr['coding'])) {
                            $addresses[] = $addr;
                        } elseif (isset($addr['reference']) && str_starts_with($addr['reference'], 'Condition/')) {
                            $conditionUuid = str_replace('Condition/', '', $addr['reference']);
                            $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                            if ($actualCondition) {
                                $coding = $actualCondition->code?->coding?->first();
                                if ($coding) {
                                    $addresses[] = [
                                        'coding' => [
                                            [
                                                'system' => $coding->system,
                                                'code' => $coding->code
                                            ]
                                        ]
                                    ];
                                }
                            }
                        }
                    }
                }

                // Map supportingInfo for local JSON storage
                $supportingInfoRaw = $rawFhir['supporting_info'] ?? ($rawFhir['supportingInfo'] ?? null);
                $episodes = [];
                if (isset($supportingInfoRaw) && is_array($supportingInfoRaw)) {
                    foreach ($supportingInfoRaw as $info) {
                        $val = $info['identifier']['value'] ?? null;
                        if ($val) {
                            $episodes[] = [
                                'id' => $val,
                                'name' => $val
                            ];
                        }
                    }
                }
                $supportingInfoDb = [
                    'episodes' => $episodes,
                    'medical_records' => []
                ];

                if ($carePlan) {
                    $carePlan->update([
                        'uuid' => $rawFhir['id'] ?? $rawFhir['uuid'] ?? null,
                        'author_id' => $authorId,
                        'legal_entity_id' => $person->legal_entity_id ?? legalEntity()->id,
                        'status' => $rawFhir['status'] ?? CarePlanStatus::ACTIVE->value,
                        'title' => !empty($rawFhir['title']) ? $rawFhir['title'] : ($carePlan->title ?? 'План лікування'),
                        'description' => !empty($rawFhir['description']) ? $rawFhir['description'] : ($carePlan->description ?? null),
                        'note' => !empty($rawFhir['note']) ? $rawFhir['note'] : ($carePlan->note ?? null),
                        'category_id' => $category?->id,
                        'encounter_identifier_id' => $encounterIdentifier?->id,
                        'care_manager_id' => $careManager?->id,
                        'period_start' => isset($rawFhir['period']['start'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['start'])
                            : ($rawFhir['ehealth_inserted_at'] ?? now()),
                        'period_end' => isset($rawFhir['period']['end'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['end'])
                            : null,
                        'terms_of_service' => $rawFhir['terms_of_service']['coding'][0]['code'] ?? null,
                        'addresses' => !empty($addresses) ? $addresses : ($carePlan->addresses ?? null),
                        'supporting_info' => $supportingInfoDb,
                    ]);
                } else {
                    $carePlan = CarePlan::create([
                        'uuid' => $rawFhir['id'] ?? $rawFhir['uuid'] ?? null,
                        'person_id' => $person->id,
                        'author_id' => $authorId,
                        'legal_entity_id' => $person->legal_entity_id ?? (legalEntity()?->id ?? null),
                        'status' => $rawFhir['status'] ?? CarePlanStatus::ACTIVE->value,
                        'title' => !empty($rawFhir['title']) ? $rawFhir['title'] : 'План лікування',
                        'description' => !empty($rawFhir['description']) ? $rawFhir['description'] : null,
                        'note' => !empty($rawFhir['note']) ? $rawFhir['note'] : null,
                        'category_id' => $category?->id,
                        'encounter_identifier_id' => $encounterIdentifier?->id,
                        'care_manager_id' => $careManager?->id,
                        'period_start' => isset($rawFhir['period']['start'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['start'])
                            : ($rawFhir['ehealth_inserted_at'] ?? now()),
                        'period_end' => isset($rawFhir['period']['end'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['end'])
                            : null,
                        'terms_of_service' => $rawFhir['terms_of_service']['coding'][0]['code'] ?? null,
                        'addresses' => !empty($addresses) ? $addresses : null,
                        'supporting_info' => $supportingInfoDb,
                    ]);
                }

                if (isset($rawFhir['period'])) {
                    MedicalEventsRepository::period()->sync($carePlan, $rawFhir['period'], 'effectivePeriod');
                }

                if (isset($supportingInfoRaw)) {
                    $supportingInfoIds = [];
                    foreach ($supportingInfoRaw as $info) {
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

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
    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return CarePlan::where('legal_entity_id', $legalEntityId)
            ->with(['person', 'author.party', 'encounter.diagnoses.conditionModel.code.coding'])
            ->latest()
            ->get();
    }

    public function getByPersonId(int $personId, array $filters = []): Collection
    {
        $query = CarePlan::where('person_id', $personId)
            ->with(['person', 'author.party', 'encounter.diagnoses.conditionModel.code.coding']);

        if (!empty($filters['name'])) {
            $query->where('title', 'like', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['encounter_id'])) {
            $query->whereHas('encounter', function ($q) use ($filters) {
                $q->where('uuid', 'like', '%' . $filters['encounter_id'] . '%')
                    ->orWhere('id', $filters['encounter_id']);
            });
        }

        return $query->latest()->get();
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
            $formStart = \Carbon\CarbonImmutable::parse($periodStart, config('app.timezone', 'Europe/Kiev'))->startOfDay();

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

        if (isset($plans['uuid']) || isset($plans['id'])) {
            $plans = [$plans];
        }

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
                    'care_plan_uuid' => $rawFhir['id'] ?? $rawFhir['uuid'] ?? null,
                    'patient_uuid' => $rawFhir['subject']['identifier']['value'] ?? 'missing'
                ]);
                continue;
            }

            // TODO: Move raw FHIR data storage to MongoDB when the driver and collection are ready.
            // Currently disabled to prevent conflicts with the SQL 'care_plans' table.
            /*
            \App\Models\MedicalEvents\Mongo\CarePlan::updateOrCreate(
                ['uuid' => $rawFhir['uuid']],
                ['data' => $rawFhir]
            );
            */

            DB::transaction(function () use ($person, $rawFhir, $activityRepo) {
                $categoryData = isset($rawFhir['category']) && is_array($rawFhir['category'])
                    ? (array_key_exists(0, $rawFhir['category']) ? $rawFhir['category'][0] : $rawFhir['category'])
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

                $careManager = isset($rawFhir['careManager']['identifier']['value'])
                    ? MedicalEventsRepository::identifier()->store($rawFhir['careManager']['identifier']['value'])
                    : null;

                if ($careManager && isset($rawFhir['careManager']['identifier']['type'])) {
                    MedicalEventsRepository::codeableConcept()->attach($careManager, $rawFhir['careManager']);
                }

                $author = null;
                $authorUuid = $rawFhir['author']['identifier']['value'] ?? null;
                if ($authorUuid) {
                    $author = \App\Models\Employee\Employee::where('uuid', $authorUuid)->first();

                    if (!$author) {
                        // Resolve Legal Entity from managing organization or fallback
                        $managingOrgUuid = $rawFhir['managing_organization']['identifier']['value'] ?? null;
                        $legalEntity = null;
                        if ($managingOrgUuid) {
                            $legalEntity = \App\Models\LegalEntity::where('uuid', $managingOrgUuid)->first();
                        }

                        $fallbackEmployee = null;
                        if (Auth::check()) {
                            $fallbackEmployee = Auth::user()?->getCarePlanWriterEmployee()
                                ?? Auth::user()?->activeEmployee();
                        }
                        $fallbackEmployee ??= \App\Models\Employee\Employee::first();

                        $legalEntityId = $legalEntity?->id
                            ?? $fallbackEmployee?->legal_entity_id
                            ?? legalEntity()?->id
                            ?? \App\Models\LegalEntity::first()?->id;

                        $legalEntityUuid = $legalEntity?->uuid
                            ?? $fallbackEmployee?->legal_entity_uuid
                            ?? legalEntity()?->uuid
                            ?? \App\Models\LegalEntity::first()?->uuid;

                        // Parse doctor's name from display_value using a patronymic-aware parser
                        $displayName = $rawFhir['author']['display_value'] ?? 'Невідомий Лікар';
                        $words = array_values(array_filter(explode(' ', trim($displayName))));

                        $firstName = 'Лікар';
                        $lastName = 'Невідомий';
                        $secondName = null;

                        if (count($words) === 3) {
                            if (preg_match('/(ович|евич|йович|івна|евна|ївна|ич|на)$/ui', $words[1])) {
                                $firstName = $words[0];
                                $secondName = $words[1];
                                $lastName = $words[2];
                            } elseif (preg_match('/(ович|евич|йович|івна|евна|ївна|ич|на)$/ui', $words[2])) {
                                $lastName = $words[0];
                                $firstName = $words[1];
                                $secondName = $words[2];
                            } else {
                                $lastName = $words[0];
                                $firstName = $words[1];
                                $secondName = $words[2];
                            }
                        } elseif (count($words) === 2) {
                            $firstName = $words[0];
                            $lastName = $words[1];
                        } elseif (count($words) === 1) {
                            $firstName = $words[0];
                            $lastName = $words[0];
                        }

                        // Create a stub Party record
                        $party = \App\Models\Relations\Party::create([
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'second_name' => $secondName,
                            'verification_status' => \App\Enums\Party\VerificationStatus::VERIFIED->value,
                        ]);

                        // Create a stub Employee record (NOTE: this is a fallback for external doctor plans)
                        $author = \App\Models\Employee\Employee::create([
                            'uuid' => $authorUuid,
                            'legal_entity_uuid' => $legalEntityUuid,
                            'legal_entity_id' => $legalEntityId,
                            'party_id' => $party->id,
                            'position' => 'DOCTOR',
                            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
                            'start_date' => now()->format('Y-m-d'),
                            'status' => \App\Enums\Status::APPROVED->value,
                            'is_active' => true,
                        ]);
                    }
                }

                $fallbackEmployee = null;
                if (Auth::check()) {
                    $fallbackEmployee = Auth::user()?->getCarePlanWriterEmployee()
                        ?? Auth::user()?->activeEmployee();
                }
                $fallbackEmployee ??= \App\Models\Employee\Employee::first();

                // Fallback to current user if author not found (to satisfy NOT NULL constraint)
                $authorId = $author?->id ?? $fallbackEmployee?->id;

                $legalEntityId = $author?->legal_entity_id
                    ?? $fallbackEmployee?->legal_entity_id
                    ?? legalEntity()?->id
                    ?? \App\Models\LegalEntity::first()?->id;

                // Try to find existing record by UUID OR by (person + encounter) if UUID is missing locally
                $carePlan = CarePlan::where('uuid', $rawFhir['id'] ?? $rawFhir['uuid'] ?? null)->first();
                if (!$carePlan && $encounterIdentifier) {
                    $carePlan = CarePlan::where('person_id', $person->id)
                        ->where('encounter_identifier_id', $encounterIdentifier->id)
                        ->whereNull('uuid')
                        ->first();
                }

                $encounterId = null;
                $localEncounter = null;
                $encounterUuid = $rawFhir['encounter']['identifier']['value'] ?? null;
                if ($encounterUuid) {
                    $localEncounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $encounterUuid)->first();
                    if ($localEncounter) {
                        $encounterId = $localEncounter->id;
                    }
                }

                $incomingTitle = isset($rawFhir['title']) ? trim($rawFhir['title']) : '';
                $isGenericIncoming = ($incomingTitle === '' || $incomingTitle === 'План лікування');

                $title = null;
                if (!$isGenericIncoming) {
                    $title = $incomingTitle;
                } elseif ($carePlan && $carePlan->title && trim($carePlan->title) !== '' && trim($carePlan->title) !== 'План лікування') {
                    $title = $carePlan->title;
                } else {
                    // Generate dynamically from linked encounter's diagnosis
                    $diagnosisText = null;
                    if ($localEncounter) {
                        $localEncounter->loadMissing('diagnoses.conditionModel.code.coding');
                        $diagnosis = $localEncounter->diagnoses->first();
                        if ($diagnosis && $diagnosis->conditionModel) {
                            $condition = $diagnosis->conditionModel;
                            $code = $condition->code_string;
                            $description = $condition->code_display;
                            if ($code) {
                                $diagnosisText = $description ? "$code - $description" : $code;
                            }
                        }
                    }

                    if ($diagnosisText) {
                        $title = "План лікування: " . $diagnosisText;
                    } else {
                        // Fallback to category text
                        $categoryText = null;
                        if (isset($categoryData['text'])) {
                            $categoryText = $categoryData['text'];
                        } elseif (isset($categoryData['coding'][0]['code'])) {
                            $code = $categoryData['coding'][0]['code'];
                            try {
                                $dict = dictionary()->basics()->byName('eHealth/care_plan_categories');
                                if ($dict) {
                                    $categoryText = $dict->asCodeDescription()->get($code);
                                }
                            } catch (\Throwable $e) {
                                // ignore
                            }
                            $categoryText ??= $code;
                        }

                        if ($categoryText) {
                            $title = "План лікування: " . $categoryText;
                        } else {
                            $title = 'План лікування';
                        }
                    }
                }

                if ($carePlan) {
                    $carePlan->update([
                        'uuid' => $rawFhir['id'] ?? $rawFhir['uuid'] ?? null,
                        'author_id' => $authorId,
                        'legal_entity_id' => $legalEntityId,
                        'status' => $rawFhir['status'] ?? CarePlanStatus::ACTIVE->value,
                        'title' => $title,
                        'description' => $rawFhir['description'] ?? null,
                        'note' => $rawFhir['note'] ?? null,
                        'category_id' => $category?->id,
                        'encounter_id' => $encounterId,
                        'encounter_identifier_id' => $encounterIdentifier?->id,
                        'care_manager_id' => $careManager?->id,
                        'period_start' => isset($rawFhir['period']['start'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['start'])
                            : ($rawFhir['ehealth_inserted_at'] ?? now()),
                        'period_end' => isset($rawFhir['period']['end'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['end'])
                            : null,
                        'terms_of_service' => $rawFhir['terms_of_service']['coding'][0]['code'] ?? null,
                    ]);
                } else {
                    $carePlan = CarePlan::create([
                        'uuid' => $rawFhir['id'] ?? $rawFhir['uuid'] ?? null,
                        'person_id' => $person->id,
                        'author_id' => $authorId,
                        'legal_entity_id' => $legalEntityId,
                        'status' => $rawFhir['status'] ?? CarePlanStatus::ACTIVE->value,
                        'title' => $title,
                        'description' => $rawFhir['description'] ?? null,
                        'note' => $rawFhir['note'] ?? null,
                        'category_id' => $category?->id,
                        'encounter_id' => $encounterId,
                        'encounter_identifier_id' => $encounterIdentifier?->id,
                        'care_manager_id' => $careManager?->id,
                        'period_start' => isset($rawFhir['period']['start'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['start'])
                            : ($rawFhir['ehealth_inserted_at'] ?? now()),
                        'period_end' => isset($rawFhir['period']['end'])
                            ? \Carbon\Carbon::parse($rawFhir['period']['end'])
                            : null,
                        'terms_of_service' => $rawFhir['terms_of_service']['coding'][0]['code'] ?? null,
                    ]);
                }


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

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Dto\CarePlan\Model as CarePlanModelData;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanSync;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CarePlanRepository
{
    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return CarePlan::where('legal_entity_id', $legalEntityId)
            ->with(['person', 'person.names', 'author.party', 'encounter.episode', 'encounter.diagnoses.condition', 'encounterIdentifier'])
            ->latest()
            ->get();
    }

    public function getByPersonId(int $personId, array $filters = []): Collection
    {
        $query = CarePlan::where('person_id', $personId)
            ->with(['person', 'person.names', 'author.party', 'encounter.episode', 'encounter.diagnoses.condition', 'encounterIdentifier']);

        if (!empty($filters['name'])) {
            $query->where('title', 'like', "%{$filters['name']}%");
        }

        if (!empty($filters['status'])) {
            $query->whereRaw('LOWER(status) = LOWER(?)', [$filters['status']]);
        }

        if (!empty($filters['start_date'])) {
            $query->whereDate('period_start', '>=', \Carbon\Carbon::parse($filters['start_date']));
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate('period_end', '<=', \Carbon\Carbon::parse($filters['end_date']));
        }

        if (!empty($filters['encounter_id'])) {
            $query->where('encounter_id', 'like', "%{$filters['encounter_id']}%");
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
     * Resolve care plan period bounds as stored in eHealth (UTC).
     *
     * @return array{start: ?\Carbon\CarbonInterface, end: ?\Carbon\CarbonInterface}
     */
    public function resolveEHealthPeriodBounds(CarePlan $carePlan): array
    {
        $carePlan->loadMissing(['effectivePeriod', 'encounter.period']);

        if ($carePlan->effectivePeriod) {
            $rawStart = $this->periodValueAsUtcString($carePlan->effectivePeriod, 'start');
            $rawEnd = $this->periodValueAsUtcString($carePlan->effectivePeriod, 'end');

            return [
                'start' => $rawStart ? \Carbon\Carbon::parse($rawStart, 'UTC') : null,
                'end' => $rawEnd ? \Carbon\Carbon::parse($rawEnd, 'UTC') : null,
            ];
        }

        $periodStartForm = $carePlan->period_start?->format('Y-m-d');
        $encounterRawStart = $carePlan->encounter?->period?->getRawOriginal('start');

        if ($encounterRawStart && $periodStartForm) {
            $encounterStart = \Carbon\CarbonImmutable::parse($encounterRawStart, 'UTC');
            $formStart = \Carbon\CarbonImmutable::parse($periodStartForm, config('app.timezone', 'Europe/Kyiv'))->startOfDay();

            if ($formStart->utc()->lt($encounterStart)) {
                $start = \Carbon\Carbon::parse($encounterStart->addMinute()->toIso8601ZuluString())->utc();
            } else {
                $start = \Carbon\Carbon::parse(convertToEHealthISO8601($periodStartForm . ' 00:00:00'))->utc();
            }
        } elseif ($periodStartForm) {
            $start = \Carbon\Carbon::parse(convertToEHealthISO8601($periodStartForm . ' 00:00:00'))->utc();
        } else {
            $start = null;
        }

        $end = null;
        if ($carePlan->period_end) {
            $end = \Carbon\Carbon::parse(
                convertToEHealthISO8601($carePlan->period_end->format('Y-m-d') . ' 23:59:59')
            )->utc();
        }

        return ['start' => $start, 'end' => $end];
    }

    private function periodValueAsUtcString(\App\Models\MedicalEvents\Sql\Period $period, string $key): ?string
    {
        $raw = $period->getRawOriginal($key);
        if (!empty($raw)) {
            return (string) $raw;
        }

        $value = $period->getAttributes()[$key] ?? null;
        if (empty($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $validatedData
     * @param  int|null  $fallbackAuthorId  Employee used when the remote author is unknown locally.
     *                                      Care plans require an author, so plans stay unsynced
     *                                      without one rather than being attributed to a guess.
     */
    public function syncCarePlans(array $validatedData, ?int $personId = null, ?int $fallbackAuthorId = null): void
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
                    'care_plan_uuid' => $rawFhir['id'],
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

            DB::transaction(function () use ($person, $rawFhir, $activityRepo, $fallbackAuthorId) {
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
                }

                $authorId = $author?->id ?? $fallbackAuthorId;

                if (!$authorId) {
                    \Illuminate\Support\Facades\Log::warning('CarePlanRepository: author not found for CarePlan sync', [
                        'care_plan_uuid' => $rawFhir['id'] ?? null,
                        'author_uuid' => $authorUuid,
                    ]);

                    return;
                }

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

                // Try to find existing record by UUID OR by (person + encounter) if UUID is missing locally
                $carePlan = CarePlan::where('uuid', $rawFhir['id'] ?? $rawFhir['uuid'] ?? null)->first();
                if (!$carePlan && $encounterIdentifier) {
                    $carePlan = CarePlan::where('person_id', $person->id)
                        ->where('encounter_identifier_id', $encounterIdentifier->id)
                        ->whereNull('uuid')
                        ->first();
                }

                $localEncounterId = null;
                if ($encounterIdentifier) {
                    $localEncounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $encounterIdentifier->value)->first();
                    $localEncounterId = $localEncounter?->id;
                }

                $fields = app(ObjectMapperInterface::class)->map(
                    new CarePlanSync($rawFhir),
                    new CarePlanModelData(
                        $carePlan?->title ?? 'План лікування',
                        $carePlan?->description,
                        $carePlan?->note,
                        now(),
                    )
                )->toSyncAttributes();
                $fields = array_replace($fields, [
                    'author_id' => $authorId,
                    'legal_entity_id' => $person->legal_entity_id ?? ($carePlan ? legalEntity()->id : legalEntity()?->id),
                    'category_id' => $category?->id,
                    'encounter_identifier_id' => $encounterIdentifier?->id,
                    'encounter_id' => $localEncounterId ?? $carePlan?->encounter_id,
                    'care_manager_id' => $careManager?->id,
                    'addresses' => !empty($addresses) ? $addresses : $carePlan?->addresses,
                ]);
                if ($carePlan) {
                    $carePlan->update($fields);
                } else {
                    $carePlan = CarePlan::create(array_replace($fields, ['person_id' => $person->id]));
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

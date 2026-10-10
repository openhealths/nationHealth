<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CarePlanActivityRepository
{
    /** Preserve the existing transaction/row lock scope; issuedSum must use the same database connection. */
    public function assertCanIssue(int $activityId, float $qty, callable $issuedSum): CarePlanActivity
    {
        return DB::transaction(function () use ($activityId, $qty, $issuedSum): CarePlanActivity {
            $activity = CarePlanActivity::query()->whereKey($activityId)->lockForUpdate()->firstOrFail();
            $cap = $activity->quantity;
            if ($cap === null || $cap === '') {
                return $activity;
            }
            $issued = max(0.0, (float) $issuedSum($activity->id));
            $remaining = max(0.0, (float) $cap - $issued);
            if ($qty > $remaining + 0.0001) {
                throw new \InvalidArgumentException(__('care-plan.activity_issue_exceeds_remaining', ['remaining' => $remaining]));
            }

            return $activity;
        });
    }

    /** @return list<array{type: string, status: string, uuid: string|null}> */
    public function findOpenDocuments(CarePlanActivity $activity): array
    {
        $documents = [];
        foreach (MedicalEventsRepository::medicationRequest()->findOpenForActivity($activity) as $request) {
            $status = strtolower((string) $request->status);
            $documents[] = [
                'type' => in_array($status, ['new', 'draft', 'signed'], true) ? 'medication_request_request' : 'medication_request',
                'status' => $status,
                'uuid' => $request->uuid,
            ];
        }
        foreach ([
            'service_request' => MedicalEventsRepository::serviceRequest(),
            'device_request' => MedicalEventsRepository::deviceRequest(),
        ] as $type => $repository) {
            foreach ($repository->findOpenForActivity($activity) as $request) {
                $documents[] = ['type' => $type, 'status' => strtolower((string) $request->status), 'uuid' => $request->uuid];
            }
        }

        return $documents;
    }

    public function findById(int $id): ?CarePlanActivity
    {
        return CarePlanActivity::find($id);
    }

    public function findForCarePlan(CarePlan $carePlan, int $id): ?CarePlanActivity
    {
        return CarePlanActivity::query()->where('care_plan_id', $carePlan->id)->whereKey($id)->first();
    }

    public function getByCarePlanId(int $carePlanId)
    {
        return CarePlanActivity::where('care_plan_id', $carePlanId)->get();
    }

    public function create(array $data): CarePlanActivity
    {
        return CarePlanActivity::create($data);
    }

    public function update(CarePlanActivity $activity, array $data): bool
    {
        return $activity->update($data);
    }

    public function updateById(int $id, array $data): bool
    {
        $activity = CarePlanActivity::find($id);
        if (!$activity) {
            return false;
        }

        return $activity->update($data);
    }

    public function deleteById(int $id): bool
    {
        $activity = CarePlanActivity::find($id);
        if (!$activity) {
            return false;
        }

        return DB::transaction(function () use ($activity): bool {
            $activity->quantityQuantity?->delete();
            $activity->dailyAmountQuantity?->delete();

            return (bool) $activity->delete();
        });
    }

    public function syncActivities(\App\Models\Person\Person $person, \App\Models\CarePlan $carePlan, array $query = []): void
    {
        if (empty($carePlan->uuid)) {
            \Illuminate\Support\Facades\Log::warning('CarePlanActivityRepository: sync skipped because CarePlan UUID is missing');

            return;
        }

        $response = EHealth::carePlanActivity()->getSummary($person->uuid, $carePlan->uuid, $query);
        $data = $response->getData();

        \Illuminate\Support\Facades\Log::info('CarePlanActivityRepository: syncActivities raw response data', [
            'person_uuid' => $person->uuid,
            'care_plan_uuid' => $carePlan->uuid,
            'response' => $data
        ]);

        $activities = isset($data['data']) ? $data['data'] : $data;

        if (!is_array($activities)) {
            \Illuminate\Support\Facades\Log::warning('CarePlanActivityRepository: sync skipped because data is not an array', ['data' => $data]);

            return;
        }

        foreach ($activities as $index => $rawFhir) {
            if (is_array($rawFhir) && !isset($rawFhir['status']) && isset($rawFhir['detail']['status'])) {
                $activities[$index]['status'] = $rawFhir['detail']['status'];
            }
        }

        $validator = Validator::make($activities, [
            '*' => 'array',
            '*.id' => 'required|uuid',
            '*.status' => 'required|string',
        ]);

        if ($validator->fails()) {
            \Illuminate\Support\Facades\Log::error('CarePlanActivityRepository: sync validation failed', [
                'errors' => $validator->errors()->toArray(),
                'data' => $activities
            ]);
            throw new ValidationException($validator);
        }

        foreach ($activities as $rawFhir) {
            /*
            \App\Models\MedicalEvents\Mongo\CarePlanActivity::updateOrCreate(
                ['uuid' => $rawFhir['id']],
                ['data' => $rawFhir]
            );
            */

            DB::transaction(function () use ($carePlan, $rawFhir) {
                $detail = $rawFhir['detail'] ?? [];

                $kind = null;
                if (isset($detail['kind'])) {
                    if (is_array($detail['kind'])) {
                        $kind = MedicalEventsRepository::codeableConcept()->store($detail['kind']);
                    } else {
                        $kind = MedicalEventsRepository::codeableConcept()->store([
                            'coding' => [
                                [
                                    'system' => 'http://hl7.org/fhir/care-plan-activity-kind',
                                    'code' => $detail['kind']
                                ]
                            ],
                            'text' => $detail['kind']
                        ]);
                    }
                }

                $rawProductCodeableConcept = $detail['product_codeable_concept'] ?? ($detail['productCodeableConcept'] ?? null);
                $rawReasonCode = $detail['reason_code'] ?? ($detail['reasonCode'] ?? null);
                $rawOutcomeCodeableConcept = $detail['outcome_codeable_concept'] ?? ($detail['outcomeCodeableConcept'] ?? null);
                $rawProductReference = $detail['product_reference'] ?? ($detail['productReference'] ?? null);
                $rawReasonReference = $detail['reason_reference'] ?? ($detail['reasonReference'] ?? null);
                $rawGoal = $detail['goal'] ?? null;
                $rawOutcomeReference = $detail['outcome_reference'] ?? ($detail['outcomeReference'] ?? null);

                $productConcept = !empty($rawProductCodeableConcept)
                    ? MedicalEventsRepository::codeableConcept()->store($rawProductCodeableConcept)
                    : null;

                $reasonConcept = !empty($rawReasonCode)
                    ? MedicalEventsRepository::codeableConcept()->store($rawReasonCode[0])
                    : null;

                $outcomeConcept = !empty($rawOutcomeCodeableConcept)
                    ? MedicalEventsRepository::codeableConcept()->store($rawOutcomeCodeableConcept)
                    : null;

                $productReference = !empty($rawProductReference)
                    ? MedicalEventsRepository::identifier()->store($rawProductReference['identifier']['value'])
                    : null;

                $authorUuid = $rawFhir['author']['identifier']['value'] ?? null;
                $authorId = null;
                if ($authorUuid) {
                    $authorId = \App\Models\Employee\Employee::where('uuid', $authorUuid)->value('id');
                }
                if (!$authorId) {
                    $authorId = $carePlan->author_id;
                }

                $activity = CarePlanActivity::where('uuid', $rawFhir['id'])->first();

                $quantityId = null;
                $rawQuantity = $detail['quantity'] ?? null;
                if ($rawQuantity) {
                    $qtyData = [
                        'value' => isset($rawQuantity['value']) ? (float)$rawQuantity['value'] : null,
                        'comparator' => $rawQuantity['comparator'] ?? null,
                        'unit' => $rawQuantity['unit'] ?? null,
                        'system' => $rawQuantity['system'] ?? null,
                        'code' => $rawQuantity['code'] ?? null,
                    ];
                    if ($activity && $activity->quantityQuantity) {
                        $activity->quantityQuantity->update($qtyData);
                        $quantityId = $activity->quantityQuantity->id;
                    } else {
                        $quantityObj = \App\Models\MedicalEvents\Sql\Quantity::create($qtyData);
                        $quantityId = $quantityObj->id;
                    }
                } else {
                    if ($activity && $activity->quantityQuantity) {
                        $activity->quantityQuantity->delete();
                    }
                }

                $dailyAmountId = null;
                $rawDailyAmount = $detail['dailyAmount'] ?? ($detail['daily_amount'] ?? null);
                if ($rawDailyAmount) {
                    $dailyAmountData = [
                        'value' => isset($rawDailyAmount['value']) ? (float)$rawDailyAmount['value'] : null,
                        'comparator' => $rawDailyAmount['comparator'] ?? null,
                        'unit' => $rawDailyAmount['unit'] ?? null,
                        'system' => $rawDailyAmount['system'] ?? null,
                        'code' => $rawDailyAmount['code'] ?? null,
                    ];
                    if ($activity && $activity->dailyAmountQuantity) {
                        $activity->dailyAmountQuantity->update($dailyAmountData);
                        $dailyAmountId = $activity->dailyAmountQuantity->id;
                    } else {
                        $dailyAmountObj = \App\Models\MedicalEvents\Sql\Quantity::create($dailyAmountData);
                        $dailyAmountId = $dailyAmountObj->id;
                    }
                } else {
                    if ($activity && $activity->dailyAmountQuantity) {
                        $activity->dailyAmountQuantity->delete();
                    }
                }

                $activity = CarePlanActivity::updateOrCreate(
                    ['uuid' => $rawFhir['id']],
                    array_merge(
                        app(\Symfony\Component\ObjectMapper\ObjectMapperInterface::class)->map(
                            new \App\Classes\eHealth\Api\Responses\Collections\CarePlanActivitySync($rawFhir),
                            \App\Dto\CarePlanActivity\Model::class,
                        )->toArray(),
                        [
                            'care_plan_id' => $carePlan->id,
                            'kind_id' => $kind?->id,
                            'product_codeable_concept_id' => $productConcept?->id,
                            'reason_code_id' => $reasonConcept?->id,
                            'outcome_codeable_concept_id' => $outcomeConcept?->id,
                            'product_reference_id' => $productReference?->id,
                            'quantity_id' => $quantityId,
                            'daily_amount_id' => $dailyAmountId,
                            'author_id' => $authorId,
                        ],
                    )
                );

                $rawScheduledPeriod = $detail['scheduledPeriod'] ?? ($detail['scheduled_period'] ?? null);
                $scheduledPeriodData = null;
                if ($rawScheduledPeriod) {
                    $scheduledPeriodData = [
                        'start' => $rawScheduledPeriod['start'] ?? null,
                        'end' => $rawScheduledPeriod['end'] ?? null,
                    ];
                }
                MedicalEventsRepository::period()->sync($activity, $scheduledPeriodData, 'scheduledPeriod');

                if (!empty($rawReasonReference)) {
                    $ids = [];
                    foreach ($rawReasonReference as $ref) {
                        $ids[] = MedicalEventsRepository::identifier()->store($ref['identifier']['value'])->id;
                    }
                    $activity->reasonReferences()->sync($ids);
                }

                if (!empty($rawGoal)) {
                    $ids = [];
                    foreach ($rawGoal as $ref) {
                        $ids[] = MedicalEventsRepository::identifier()->store($ref['identifier']['value'])->id;
                    }
                    $activity->goalReferences()->sync($ids);
                }

                if (!empty($rawOutcomeReference)) {
                    $ids = [];
                    foreach ($rawOutcomeReference as $ref) {
                        $ids[] = MedicalEventsRepository::identifier()->store($ref['identifier']['value'])->id;
                    }
                    $activity->outcomeReferences()->sync($ids);
                }
            });
        }
    }

}

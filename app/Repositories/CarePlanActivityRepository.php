<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CarePlanActivityRepository
{
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
        if (!$activity) return false;
        return $activity->update($data);
    }

    public function formatCarePlanActivityRequest(CarePlanActivity $activity): array
    {
        return \App\Core\Arr::removeEmptyKeys([
            'detail' => \App\Core\Arr::removeEmptyKeys([
                'kind' => $activity->kind,
                'description' => $activity->description ?: null,
                'product_reference' => $activity->product_reference ? ['identifier' => ['value' => $activity->product_reference]] : null,
                'scheduled_period' => array_filter([
                    'start' => $activity->scheduled_period_start ? convertToYmd($activity->scheduled_period_start->format('d.m.Y')) : null,
                    'end' => $activity->scheduled_period_end ? convertToYmd($activity->scheduled_period_end->format('d.m.Y')) : null,
                ]),
                'quantity' => $activity->quantity ? ['value' => $activity->quantity, 'system' => $activity->quantity_system ?? null, 'code' => $activity->quantity_code ?? null] : null,
                'daily_amount' => $activity->daily_amount ? ['value' => $activity->daily_amount, 'system' => $activity->daily_amount_system ?? null, 'code' => $activity->daily_amount_code ?? null] : null,
                'reason_code' => $activity->reason_code ? [['coding' => [['code' => $activity->reason_code]]]] : null,
                'reason_reference' => !empty($activity->reason_reference) ? array_map(fn($r) => ['identifier' => ['value' => $r]], $activity->reason_reference) : null,
                'goal' => !empty($activity->goal) ? array_map(fn($g) => ['identifier' => ['value' => $g]], $activity->goal) : null,
            ]),
            'program' => $activity->program ? ['identifier' => ['value' => $activity->program]] : null,
        ]);
    }

    public function syncActivities(\App\Models\Person\Person $person, \App\Models\CarePlan $carePlan, array $query = []): void
    {
        $response = EHealth::carePlanActivity()->getSummary($person->uuid, $carePlan->uuid, $query);
        $data = $response->getData();

        if (!isset($data['data']) || !is_array($data['data'])) {
            return;
        }

        $validator = Validator::make($data['data'], [
            '*' => 'array',
            '*.id' => 'required|uuid',
            '*.status' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        foreach ($data['data'] as $rawFhir) {
            \App\Models\MedicalEvents\Mongo\CarePlanActivity::updateOrCreate(
                ['uuid' => $rawFhir['id']],
                ['data' => $rawFhir]
            );

            DB::transaction(function () use ($carePlan, $rawFhir) {
                $detail = $rawFhir['detail'] ?? [];

                $kind = isset($detail['kind'])
                    ? MedicalEventsRepository::codeableConcept()->store($detail['kind'])
                    : null;

                $productConcept = isset($detail['productCodeableConcept'])
                    ? MedicalEventsRepository::codeableConcept()->store($detail['productCodeableConcept'])
                    : null;

                $reasonConcept = !empty($detail['reasonCode'])
                    ? MedicalEventsRepository::codeableConcept()->store($detail['reasonCode'][0])
                    : null;

                $outcomeConcept = isset($detail['outcomeCodeableConcept'])
                    ? MedicalEventsRepository::codeableConcept()->store($detail['outcomeCodeableConcept'])
                    : null;

                $productReference = isset($detail['productReference'])
                    ? MedicalEventsRepository::identifier()->store($detail['productReference']['identifier']['value'])
                    : null;

                $activity = CarePlanActivity::updateOrCreate(
                    ['uuid' => $rawFhir['id']],
                    [
                        'care_plan_id' => $carePlan->id,
                        'status' => $rawFhir['status'],
                        'kind_id' => $kind?->id,
                        'product_codeable_concept_id' => $productConcept?->id,
                        'reason_code_id' => $reasonConcept?->id,
                        'outcome_codeable_concept_id' => $outcomeConcept?->id,
                        'product_reference_id' => $productReference?->id,
                        'quantity' => $detail['quantity']['value'] ?? null,
                        'quantity_system' => $detail['quantity']['system'] ?? null,
                        'quantity_code' => $detail['quantity']['code'] ?? null,
                        'description' => $detail['description'] ?? null,
                        'scheduled_period_start' => isset($detail['scheduled_period']['start']) ? \Carbon\Carbon::parse($detail['scheduled_period']['start']) : null,
                        'scheduled_period_end' => isset($detail['scheduled_period']['end']) ? \Carbon\Carbon::parse($detail['scheduled_period']['end']) : null,
                    ]
                );

                if (isset($detail['reasonReference'])) {
                    $ids = [];
                    foreach ($detail['reasonReference'] as $ref) {
                        $ids[] = MedicalEventsRepository::identifier()->store($ref['identifier']['value'])->id;
                    }
                    $activity->reasonReferences()->sync($ids);
                }

                if (isset($detail['goal'])) {
                    $ids = [];
                    foreach ($detail['goal'] as $ref) {
                        $ids[] = MedicalEventsRepository::identifier()->store($ref['identifier']['value'])->id;
                    }
                    $activity->goalReferences()->sync($ids);
                }

                if (isset($detail['outcomeReference'])) {
                    $ids = [];
                    foreach ($detail['outcomeReference'] as $ref) {
                        $ids[] = MedicalEventsRepository::identifier()->store($ref['identifier']['value'])->id;
                    }
                    $activity->outcomeReferences()->sync($ids);
                }
            });
        }
    }
}

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
    public function findById(int $id): ?CarePlanActivity
    {
        return CarePlanActivity::with(['kindConcept'])->find($id);
    }

    public function getByCarePlanId(int $carePlanId)
    {
        return CarePlanActivity::where('care_plan_id', $carePlanId)->with(['kindConcept'])->get();
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

    public function formatCarePlanActivityRequest(CarePlanActivity $activity): array
    {
        $kindLower = strtolower($activity->kind);
        $resourceCode = 'service';
        if (str_contains($kindLower, 'medication')) {
            $resourceCode = 'innm_dosage';
        } elseif (str_contains($kindLower, 'device')) {
            $resourceCode = 'device_definition';
        }

        $quantityCode = $activity->quantity_code;
        if ($quantityCode === 'units' || empty($quantityCode)) {
            $quantityCode = 'PIECE';
        }

        return removeEmptyKeys([
            'id' => $activity->uuid ?: (string) \Illuminate\Support\Str::uuid(),
            'author' => [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            [
                                'system' => 'eHealth/resources',
                                'code' => 'employee',
                            ],
                        ],
                    ],
                    'value' => $activity->author?->uuid ?: (\Illuminate\Support\Facades\Auth::user()?->getCarePlanWriterEmployee()?->uuid ?? \Illuminate\Support\Facades\Auth::user()?->activeEmployee()?->uuid),
                ],
            ],
            'care_plan' => [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            [
                                'system' => 'eHealth/resources',
                                'code' => 'care_plan',
                            ],
                        ],
                    ],
                    'value' => $activity->carePlan?->uuid,
                ],
            ],
            'detail' => removeEmptyKeys([
                'kind' => $activity->kind,
                'status' => 'scheduled',
                'do_not_perform' => false,
                'description' => $activity->description ?: null,
                'product_reference' => $activity->product_reference ? [
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => $resourceCode,
                                ],
                            ],
                        ],
                        'value' => $activity->product_reference,
                    ],
                ] : null,
                'scheduled_period' => array_filter([
                    'start' => $activity->scheduled_period_start ? convertToEHealthISO8601($activity->scheduled_period_start->format('Y-m-d') . ' 00:00:00') : null,
                    'end' => $activity->scheduled_period_end ? convertToEHealthISO8601($activity->scheduled_period_end->format('Y-m-d') . ' 23:59:59') : null,
                ]),
                'quantity' => $activity->quantity ? [
                    'value' => (float)$activity->quantity,
                    'system' => $activity->quantity_system ?: 'SERVICE_UNIT',
                    'code' => $quantityCode,
                ] : null,
                'daily_amount' => $activity->daily_amount ? [
                    'value' => (float)$activity->daily_amount,
                    'system' => $activity->quantity_system ?: 'SERVICE_UNIT',
                    'code' => $quantityCode,
                ] : null,
                'reason_code' => $activity->reason_code ? [['coding' => [['code' => $activity->reason_code]]]] : null,
                'reason_reference' => !empty($activity->reason_reference) ? array_map(function ($r) {
                    $parts = explode('/', $r);
                    $type = 'condition';
                    $value = $r;
                    if (count($parts) === 2) {
                        $type = strtolower($parts[0]);
                        $value = $parts[1];
                    }
                    $resourceCode = match ($type) {
                        'condition' => 'condition',
                        'diagnosticreport' => 'diagnostic_report',
                        'observation' => 'observation',
                        default => 'condition',
                    };

                    return [
                        'identifier' => [
                            'type' => [
                                'coding' => [
                                    [
                                        'system' => 'eHealth/resources',
                                        'code' => $resourceCode,
                                    ],
                                ],
                            ],
                            'value' => $value,
                        ],
                    ];
                }, $activity->reason_reference) : null,
                'goal' => !empty($activity->goal) ? array_map(fn ($g) => [
                    'coding' => [
                        [
                            'system' => 'eHealth/care_plan_activity_goals',
                            'code' => $g,
                        ],
                    ],
                ], $activity->goal) : null,
            ]),
            'program' => $activity->program ? ['identifier' => ['value' => $activity->program]] : null,
        ]);
    }

    public function syncActivities(\App\Models\Person\Person $person, \App\Models\CarePlan $carePlan, array $query = []): void
    {
        if (empty($carePlan->uuid)) {
            \Illuminate\Support\Facades\Log::warning('CarePlanActivityRepository: sync skipped because CarePlan UUID is missing');

            return;
        }

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
            /*
            \App\Models\MedicalEvents\Mongo\CarePlanActivity::updateOrCreate(
                ['uuid' => $rawFhir['id']],
                ['data' => $rawFhir]
            );
            */

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

                $authorEmployeeId = null;
                $authorUuid = $rawFhir['author']['identifier']['value'] ?? null;
                if ($authorUuid) {
                    $authorEmployeeId = DB::table('employees')->where('uuid', $authorUuid)->value('id');
                }
                $authorEmployeeId ??= $carePlan->author_id;

                $kindCode = is_array($detail['kind'] ?? null)
                    ? ($detail['kind']['coding'][0]['code'] ?? null)
                    : ($detail['kind'] ?? null);

                $activity = CarePlanActivity::updateOrCreate(
                    ['uuid' => $rawFhir['id']],
                    [
                        'care_plan_id' => $carePlan->id,
                        'author_id' => $authorEmployeeId,
                        'status' => $rawFhir['status'],
                        'kind' => $kindCode,
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

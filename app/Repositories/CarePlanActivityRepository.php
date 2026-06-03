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
        return CarePlanActivity::find($id);
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
        if (!$activity) return false;
        return $activity->update($data);
    }

    private function normalizeUnitCode(?string $system, ?string $code): ?string
    {
        if (empty($code)) {
            return null;
        }
        if (empty($system)) {
            return $code;
        }

        try {
            $res = dictionary()->basics()->getMultipleFormatted([$system])->toArray();
            $dict = $res[$system] ?? null;
            if ($dict && is_array($dict)) {
                foreach (array_keys($dict) as $key) {
                    if (strcasecmp((string)$key, $code) === 0) {
                        return (string)$key;
                    }
                }
            }
        } catch (\Exception $e) {
            // fallback
        }

        return $code;
    }

    public function formatCarePlanActivityRequest(CarePlanActivity $activity): array
    {
        $productReference = null;
        if (!empty($activity->product_reference)) {
            $kindLower = strtolower($activity->kind);
            if (str_contains($kindLower, 'service')) {
                $code = 'service';
            } elseif (str_contains($kindLower, 'medication')) {
                $code = 'medication';
            } elseif (str_contains($kindLower, 'device')) {
                $code = 'device_definition';
            } else {
                $code = 'service'; // fallback
            }

            $productReference = [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            [
                                'system' => 'eHealth/resources',
                                'code' => $code
                            ]
                        ]
                    ],
                    'value' => $activity->product_reference
                ]
            ];
        }

        $authorUuid = $activity->author?->uuid ?? auth()->user()?->activeDoctorEmployee()?->uuid;

        $quantityRelation = $activity->quantityQuantity;
        $quantityValue = $quantityRelation ? $quantityRelation->value : $activity->quantity;
        $quantitySystem = $quantityRelation ? $quantityRelation->system : $activity->quantity_system;
        $quantityCode = $quantityRelation ? $quantityRelation->code : $activity->quantity_code;
        $quantityNormalizedCode = $this->normalizeUnitCode($quantitySystem, $quantityCode);

        $dailyAmountRelation = $activity->dailyAmountQuantity;
        $dailyAmountValue = $dailyAmountRelation ? $dailyAmountRelation->value : $activity->daily_amount;
        $dailyAmountSystem = $dailyAmountRelation ? $dailyAmountRelation->system : ($activity->daily_amount_system ?? $quantitySystem);
        $dailyAmountCode = $dailyAmountRelation ? $dailyAmountRelation->code : ($activity->daily_amount_code ?? $quantityCode);
        $dailyAmountNormalizedCode = $this->normalizeUnitCode($dailyAmountSystem, $dailyAmountCode);

        $scheduledPeriod = $activity->scheduledPeriod;
        $startDate = $scheduledPeriod ? $scheduledPeriod->getRawOriginal('start') : $activity->scheduled_period_start;
        $endDate = $scheduledPeriod ? $scheduledPeriod->getRawOriginal('end') : $activity->scheduled_period_end;

        $formattedStart = null;
        if ($startDate) {
            if ($activity->uuid && $scheduledPeriod) {
                // For existing activities synced to eHealth, use the exact stored timestamp
                // to prevent cryptographic signature mismatch on cancel/complete operations.
                $formattedStart = convertToEHealthISO8601($startDate);
            } else {
                $startCarbon = \Carbon\Carbon::parse($startDate);
                $formattedStart = convertToEHealthISO8601(
                    $startCarbon->format('Y-m-d') . ' ' . 
                    ($startCarbon->isToday() ? now()->format('H:i:s') : '12:00:00')
                );
            }
        }

        $formattedEnd = null;
        if ($endDate) {
            if ($activity->uuid && $scheduledPeriod) {
                // Preserve exact end timestamp for existing activities (same reason as above).
                $formattedEnd = convertToEHealthISO8601($endDate);
            } else {
                $endCarbon = \Carbon\Carbon::parse($endDate);
                $formattedEnd = convertToEHealthISO8601($endCarbon->format('Y-m-d') . ' 23:59:59');
            }
        }

        // For non-medication requests, eHealth does not allow daily_amount system/code to be set
        $isMedication = str_contains(strtolower($activity->kind), 'medication');

        return removeEmptyKeys([
            'id' => $activity->uuid,
            'author' => [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            [
                                'system' => 'eHealth/resources',
                                'code' => 'employee'
                            ]
                        ]
                    ],
                    'value' => $authorUuid
                ]
            ],
            'care_plan' => [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            [
                                'system' => 'eHealth/resources',
                                'code' => 'care_plan'
                            ]
                        ]
                    ],
                    'value' => $activity->carePlan?->uuid
                ]
            ],
            'detail' => removeEmptyKeys([
                'kind' => $activity->kind,
                'status' => 'scheduled',
                'do_not_perform' => (bool)$activity->do_not_perform,
                'description' => $activity->description ?: null,
                'product_reference' => $productReference,
                'scheduled_period' => removeEmptyKeys([
                    'start' => $formattedStart,
                    'end' => $formattedEnd,
                ]),
                'quantity' => $quantityValue ? ['value' => (float)$quantityValue, 'system' => $quantitySystem, 'code' => $quantityNormalizedCode] : null,
                'daily_amount' => $dailyAmountValue ? removeEmptyKeys([
                    'value' => (float)$dailyAmountValue,
                    'system' => $isMedication ? $dailyAmountSystem : null,
                    'code' => $isMedication ? $dailyAmountNormalizedCode : null
                ]) : null,
                'reason_code' => $activity->reason_code ? [['coding' => [['code' => $activity->reason_code]]]] : null,
                'reason_reference' => !empty($activity->reason_reference) ? array_map(function($r) {
                    $parts = explode('/', $r);
                    if (count($parts) === 2) {
                        $type = strtolower($parts[0]);
                        $uuid = $parts[1];
                    } else {
                        $type = 'condition';
                        $uuid = $r;
                    }
                    return [
                        'identifier' => [
                            'type' => [
                                'coding' => [
                                    [
                                        'system' => 'eHealth/resources',
                                        'code' => $type
                                    ]
                                ]
                            ],
                            'value' => $uuid
                        ]
                    ];
                }, $activity->reason_reference) : null,
                'goal' => !empty($activity->goal) ? array_map(fn($g) => ['identifier' => ['value' => $g]], $activity->goal) : null,
                'program' => $activity->program ? [
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => 'medical_program'
                                ]
                            ]
                        ],
                        'value' => $activity->program
                    ]
                ] : null,
            ]),
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

                $kindString = null;
                if (isset($detail['kind'])) {
                    if (is_array($detail['kind'])) {
                        $kindString = $detail['kind']['coding'][0]['code'] ?? ($detail['kind']['text'] ?? null);
                    } else {
                        $kindString = (string)$detail['kind'];
                    }
                }

                $authorUuid = $rawFhir['author']['identifier']['value'] ?? null;
                $authorId = null;
                if ($authorUuid) {
                    $authorId = \App\Models\Employee\Employee::where('uuid', $authorUuid)->value('id');
                }
                if (!$authorId) {
                    $authorId = auth()->user()?->activeDoctorEmployee()?->id;
                }
                if (!$authorId) {
                    $authorId = $carePlan->author_id;
                }

                $productReferenceValue = $detail['productReference']['identifier']['value'] ?? null;

                $reasonReferenceArray = [];
                if (isset($detail['reasonReference'])) {
                    foreach ($detail['reasonReference'] as $ref) {
                        $val = $ref['identifier']['value'] ?? null;
                        if ($val) {
                            if (str_contains($val, '/')) {
                                $reasonReferenceArray[] = $val;
                            } else {
                                $type = 'Condition';
                                if (isset($ref['identifier']['type']['coding'][0]['code'])) {
                                    $code = $ref['identifier']['type']['coding'][0]['code'];
                                    if (strcasecmp($code, 'condition') === 0) {
                                        $type = 'Condition';
                                    } elseif (strcasecmp($code, 'observation') === 0) {
                                        $type = 'Observation';
                                    } elseif (strcasecmp($code, 'diagnostic_report') === 0) {
                                        $type = 'DiagnosticReport';
                                    }
                                }
                                $reasonReferenceArray[] = $type . '/' . $val;
                            }
                        }
                    }
                }

                $goalArray = [];
                if (isset($detail['goal'])) {
                    foreach ($detail['goal'] as $g) {
                        $val = $g['identifier']['value'] ?? null;
                        if ($val) {
                            $goalArray[] = $val;
                        }
                    }
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
                    [
                        'care_plan_id' => $carePlan->id,
                        'status' => $rawFhir['status'],
                        'kind_id' => $kind?->id,
                        'product_codeable_concept_id' => $productConcept?->id,
                        'reason_code_id' => $reasonConcept?->id,
                        'outcome_codeable_concept_id' => $outcomeConcept?->id,
                        'product_reference_id' => $productReference?->id,
                        'quantity_id' => $quantityId,
                        'daily_amount_id' => $dailyAmountId,
                        'quantity' => $rawQuantity['value'] ?? null,
                        'quantity_system' => $rawQuantity['system'] ?? null,
                        'quantity_code' => $rawQuantity['code'] ?? null,
                        'daily_amount' => $rawDailyAmount['value'] ?? null,
                        'daily_amount_system' => $rawDailyAmount['system'] ?? null,
                        'daily_amount_code' => $rawDailyAmount['code'] ?? null,
                        'description' => $detail['description'] ?? null,
                        'scheduled_period_start' => isset($detail['scheduledPeriod']['start']) ? \Carbon\Carbon::parse($detail['scheduledPeriod']['start']) : (isset($detail['scheduled_period']['start']) ? \Carbon\Carbon::parse($detail['scheduled_period']['start']) : null),
                        'scheduled_period_end' => isset($detail['scheduledPeriod']['end']) ? \Carbon\Carbon::parse($detail['scheduledPeriod']['end']) : (isset($detail['scheduled_period']['end']) ? \Carbon\Carbon::parse($detail['scheduled_period']['end']) : null),
                        'kind' => $kindString,
                        'author_id' => $authorId,
                        'product_reference' => $productReferenceValue,
                        'reason_reference' => $reasonReferenceArray,
                        'goal' => $goalArray,
                    ]
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

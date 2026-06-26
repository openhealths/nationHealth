<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\MedicalEvents\Fhir;
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
        if (!$activity) {
            return false;
        }

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

    /**
     * eHealth expects integer quantity for device requests, decimal for service/medication.
     */
    private function formatQuantityValueForKind(mixed $value, string $kind): int|float
    {
        if (str_contains(strtolower($kind), 'device')) {
            return (int) $value;
        }

        return (float) $value;
    }

    private function formatScheduledPeriodStart(CarePlanActivity $activity, mixed $startDate, mixed $scheduledPeriod): ?string
    {
        if (empty($startDate)) {
            return null;
        }

        if ($activity->uuid && $scheduledPeriod) {
            return \Carbon\Carbon::parse($startDate, 'UTC')->utc()->toIso8601ZuluString();
        }

        $startCarbon = \Carbon\Carbon::parse($startDate);
        $status = strtolower((string) ($activity->status ?? ''));
        $isDraft = $status === '' || $status === 'draft';

        if ($isDraft || $startCarbon->isToday()) {
            $time = now()->format('H:i:s');
        } else {
            $time = '12:00:00';
        }

        $formattedStart = convertToEHealthISO8601($startCarbon->format('Y-m-d') . ' ' . $time);

        return $this->clipScheduledStartToCarePlanPeriod($activity, $formattedStart);
    }

    private function clipScheduledStartToCarePlanPeriod(CarePlanActivity $activity, string $formattedStart): string
    {
        $activity->loadMissing('carePlan.effectivePeriod');
        $carePlan = $activity->carePlan;
        if (!$carePlan) {
            return $formattedStart;
        }

        $planStart = app(CarePlanRepository::class)->resolveEHealthPeriodBounds($carePlan)['start'];
        if (!$planStart) {
            return $formattedStart;
        }

        $activityStart = \Carbon\Carbon::parse($formattedStart)->utc();
        if ($activityStart->lt($planStart)) {
            $nowUtc = now()->utc();

            return ($nowUtc->lt($planStart) ? $planStart : $nowUtc)->toIso8601ZuluString();
        }

        return $formattedStart;
    }

    public function formatCarePlanActivityRequest(CarePlanActivity $activity): array
    {
        $kindLower = strtolower((string) $activity->kind);
        $isDevice = str_contains($kindLower, 'device');

        $productReference = null;
        $productCodeableConcept = null;

        if ($isDevice) {
            $deviceProduct = $this->resolveDeviceProductFields($activity);
            $productReference = $deviceProduct['product_reference'];
            $productCodeableConcept = $deviceProduct['product_codeable_concept'];
        } elseif (!empty($activity->product_reference)) {
            if (str_contains($kindLower, 'service')) {
                $code = 'service';
            } elseif (str_contains($kindLower, 'medication')) {
                $code = 'medication';
            } else {
                $code = 'service';
            }

            $productReference = [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            [
                                'system' => 'eHealth/resources',
                                'code' => $code,
                            ],
                        ],
                    ],
                    'value' => $activity->product_reference,
                ],
            ];
        }

        $authorUuid = $activity->author?->uuid ?? auth()->user()?->activeDoctorEmployee()?->uuid;

        $quantityRelation = $activity->quantityQuantity;
        $quantityValue = $quantityRelation ? $quantityRelation->value : $activity->quantity;
        $quantitySystem = $quantityRelation ? $quantityRelation->system : $activity->quantity_system;
        $quantityCode = $quantityRelation ? $quantityRelation->code : $activity->quantity_code;
        $quantityNormalizedCode = $this->normalizeUnitCode($quantitySystem, $quantityCode);
        $quantityUnit = $quantityRelation ? $quantityRelation->unit : null;

        $dailyAmountRelation = $activity->dailyAmountQuantity;
        $dailyAmountValue = $dailyAmountRelation ? $dailyAmountRelation->value : $activity->daily_amount;
        $dailyAmountSystem = $dailyAmountRelation ? $dailyAmountRelation->system : ($activity->daily_amount_system ?? $quantitySystem);
        $dailyAmountCode = $dailyAmountRelation ? $dailyAmountRelation->code : ($activity->daily_amount_code ?? $quantityCode);
        $dailyAmountNormalizedCode = $this->normalizeUnitCode($dailyAmountSystem, $dailyAmountCode);
        $dailyAmountUnit = $dailyAmountRelation ? $dailyAmountRelation->unit : null;

        $scheduledPeriod = $activity->scheduledPeriod;
        $startDate = $scheduledPeriod ? $scheduledPeriod->getRawOriginal('start') : $activity->scheduled_period_start;
        $endDate = $scheduledPeriod ? $scheduledPeriod->getRawOriginal('end') : $activity->scheduled_period_end;

        $formattedStart = $this->formatScheduledPeriodStart($activity, $startDate, $scheduledPeriod);

        $formattedEnd = null;
        if ($endDate) {
            if ($activity->uuid && $scheduledPeriod) {
                $formattedEnd = \Carbon\Carbon::parse($endDate, 'UTC')->utc()->toIso8601ZuluString();
            } else {
                $endCarbon = \Carbon\Carbon::parse($endDate);
                $formattedEnd = convertToEHealthISO8601($endCarbon->format('Y-m-d') . ' 23:59:59');
            }
        }

        // For non-medication requests, eHealth does not allow daily_amount system/code to be set
        $isMedication = str_contains(strtolower($activity->kind), 'medication');
        $kind = (string) $activity->kind;

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
                'product_codeable_concept' => $productCodeableConcept,
                'scheduled_period' => removeEmptyKeys([
                    'start' => $formattedStart,
                    'end' => $formattedEnd,
                ]),
                'quantity' => $quantityValue ? removeEmptyKeys([
                    'value' => $this->formatQuantityValueForKind($quantityValue, $kind),
                    'system' => $quantitySystem,
                    'code' => $quantityNormalizedCode,
                    'unit' => $quantityUnit ?: null,
                ]) : null,
                'daily_amount' => $dailyAmountValue ? removeEmptyKeys([
                    'value' => $this->formatQuantityValueForKind($dailyAmountValue, $kind),
                    'system' => $isMedication ? $dailyAmountSystem : null,
                    'code' => $isMedication ? $dailyAmountNormalizedCode : null,
                    'unit' => $isMedication ? ($dailyAmountUnit ?: null) : null,
                ]) : null,
                'reason_code' => $activity->reason_code ? [['coding' => [['code' => $activity->reason_code]]]] : null,
                'reason_reference' => !empty($activity->reason_reference) ? array_map(function ($r) {
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
                'goal' => !empty($activity->goal) ? array_map(fn ($g) => [
                    'coding' => [
                        [
                            'system' => 'eHealth/care_plan_activity_goals',
                            'code' => $g
                        ]
                    ]
                ], $activity->goal) : null,
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

    /**
     * @return array{product_reference: ?array<string, mixed>, product_codeable_concept: ?array<string, mixed>}
     */
    private function resolveDeviceProductFields(CarePlanActivity $activity): array
    {
        $allowedCodeTypes = $this->getDeviceRequestAllowedCodeTypes($activity->program);
        $allowsClassification = in_array('CLASSIFICATION_TYPE', $allowedCodeTypes, true);
        $allowsDeviceDefinition = in_array('DEVICE_DEFINITION', $allowedCodeTypes, true);
        $reference = $activity->product_reference;
        $isDeviceDefinitionUuid = is_string($reference)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $reference) === 1;

        if (!empty($allowedCodeTypes)) {
            if ($allowsClassification && !$allowsDeviceDefinition && !empty($activity->product_codeable_concept)) {
                return [
                    'product_reference' => null,
                    'product_codeable_concept' => $this->formatDeviceClassificationConcept($activity->product_codeable_concept),
                ];
            }

            if ($allowsDeviceDefinition && $isDeviceDefinitionUuid) {
                return [
                    'product_reference' => $this->formatDeviceDefinitionReference($reference),
                    'product_codeable_concept' => null,
                ];
            }

            if ($allowsClassification && !empty($activity->product_codeable_concept)) {
                return [
                    'product_reference' => null,
                    'product_codeable_concept' => $this->formatDeviceClassificationConcept($activity->product_codeable_concept),
                ];
            }
        }

        if (!empty($activity->product_codeable_concept)) {
            return [
                'product_reference' => null,
                'product_codeable_concept' => $this->formatDeviceClassificationConcept($activity->product_codeable_concept),
            ];
        }

        if ($isDeviceDefinitionUuid) {
            return [
                'product_reference' => $this->formatDeviceDefinitionReference($reference),
                'product_codeable_concept' => null,
            ];
        }

        return [
            'product_reference' => null,
            'product_codeable_concept' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getDeviceRequestAllowedCodeTypes(?string $programId): array
    {
        if (empty($programId)) {
            return [];
        }

        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);
            $types = $program['medical_program_settings']['device_request_allowed_code_types'] ?? [];

            return is_array($types) ? $types : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDeviceClassificationConcept(string $code): array
    {
        return [
            'coding' => [
                [
                    'system' => 'device_definition_classification_type',
                    'code' => $code,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDeviceDefinitionReference(string $uuid): array
    {
        return [
            'identifier' => [
                'type' => [
                    'coding' => [
                        [
                            'system' => 'eHealth/resources',
                            'code' => 'device_definition',
                        ],
                    ],
                ],
                'value' => $uuid,
            ],
        ];
    }

    /**
     * @param  array<string, string|null>  $uuids
     * @return array{device_request: array<string, mixed>}
     */
    public function buildDevicePrequalifyPayload(CarePlanActivity $activity, CarePlan $carePlan, array $uuids): array
    {
        $formatted = $this->formatCarePlanActivityRequest($activity);
        $detail = $formatted['detail'] ?? [];

        $deviceId = $detail['product_codeable_concept']['coding'][0]['code']
            ?? $detail['product_reference']['identifier']['value']
            ?? null;

        if (empty($deviceId)) {
            throw new \InvalidArgumentException('Device product is required for prequalify.');
        }

        $supportingInfo = [];
        foreach ($activity->reason_reference ?? [] as $reference) {
            if (!is_string($reference)) {
                continue;
            }

            $parts = explode('/', $reference);
            if (count($parts) === 2) {
                $supportingInfo[] = ['type' => $parts[0], 'uuid' => $parts[1]];
            }
        }

        return Fhir::deviceRequest()->toPrequalifyPayload(
            [
                'device_id' => $deviceId,
                'quantity' => $activity->quantity,
                'program_id' => $activity->program,
                'intent' => 'order',
                'supporting_info' => $supportingInfo,
            ],
            $uuids,
            (string) $carePlan->uuid,
            (string) $activity->uuid,
        );
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

                $productReferenceValue = $rawProductReference['identifier']['value'] ?? null;

                $reasonReferenceArray = [];
                if (!empty($rawReasonReference)) {
                    foreach ($rawReasonReference as $ref) {
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
                if (!empty($rawGoal)) {
                    foreach ($rawGoal as $g) {
                        $val = null;
                        if (isset($g['coding'][0]['code'])) {
                            $val = $g['coding'][0]['code'];
                        } elseif (isset($g['identifier']['value'])) {
                            $val = $g['identifier']['value'];
                        }
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

    /**
     * Prepare eHealth activity details for cancel/complete PKCS#7 signing.
     * Strips read-only fields that are not part of the originally created activity payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeEHealthActivityForSigning(array $payload): array
    {
        $excludeKeys = [
            'remaining_quantity',
            'remaining_quantity_type',
            'inserted_at',
            'inserted_by',
            'updated_at',
            'updated_by',
            'status_history',
            'database_id',
            'display_value',
            'links',
            'urgent',
            'ehealth_inserted_at',
            'ehealth_updated_at',
            'ehealth_inserted_by',
        ];

        $normalized = $this->stripActivityPayloadKeys($payload, $excludeKeys);

        if (isset($normalized['author']) && is_array($normalized['author']) && isset($normalized['author'][0])) {
            $normalized['author'] = $normalized['author'][0];
        }

        return removeEmptyKeys($normalized);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $excludeKeys
     * @return array<string, mixed>
     */
    private function stripActivityPayloadKeys(array $payload, array $excludeKeys): array
    {
        $cleaned = [];

        foreach ($payload as $key => $value) {
            $snakeKey = \Illuminate\Support\Str::snake($key);
            if (in_array($snakeKey, $excludeKeys, true)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }

                $nested = $this->stripActivityPayloadKeys($value, $excludeKeys);
                if ($nested !== []) {
                    $cleaned[$key] = $nested;
                }

                continue;
            }

            $cleaned[$key] = $value;
        }

        return $cleaned;
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
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
                [
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
                        $type = strtolower(trim($parts[0]));
                        if ($type === 'diagnosticreport') {
                            $type = 'diagnostic_report';
                        }
                        $uuid = trim($parts[1]);
                    } else {
                        $type = 'condition';
                        $uuid = trim($r);
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

        // Prefer a concrete device definition UUID over classification when both are present.
        if ($isDeviceDefinitionUuid) {
            return [
                'product_reference' => $this->formatDeviceDefinitionReference($reference),
                'product_codeable_concept' => null,
            ];
        }

        if (!empty($activity->product_codeable_concept)) {
            return [
                'product_reference' => null,
                'product_codeable_concept' => $this->formatDeviceClassificationConcept($activity->product_codeable_concept),
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
                $type = strtolower(trim($parts[0]));
                if ($type === 'diagnosticreport') {
                    $type = 'diagnostic_report';
                }
                $supportingInfo[] = ['type' => $type, 'uuid' => trim($parts[1])];
            }
        }

        $scheduledPeriod = $activity->scheduledPeriod;
        $startDate = $scheduledPeriod ? $scheduledPeriod->getRawOriginal('start') : $activity->scheduled_period_start;
        $endDate = $scheduledPeriod ? $scheduledPeriod->getRawOriginal('end') : $activity->scheduled_period_end;

        $deviceFields = $this->resolveDeviceRequestFieldsFromActivity($activity, $detail);

        return Fhir::deviceRequest()->toPrequalifyPayload(
            array_merge([
                'quantity' => $activity->quantity,
                'program_id' => $activity->program,
                'intent' => 'order',
                'supporting_info' => $supportingInfo,
                'started_at' => $startDate ? \Carbon\Carbon::parse($startDate)->format('Y-m-d') : null,
                'ended_at' => $endDate ? \Carbon\Carbon::parse($endDate)->format('Y-m-d') : null,
            ], $deviceFields),
            $uuids,
            (string) $carePlan->uuid,
            (string) $activity->uuid,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{device_id: string, device_code_type: string, quantity_system: string, quantity_code: string}
     */
    private function resolveDeviceRequestFieldsFromActivity(CarePlanActivity $activity, array $detail): array
    {
        $quantitySystem = $activity->quantity_system ?: 'device_unit';
        $quantityCode = strtolower($activity->quantity_code ?: 'piece');

        if (!empty($detail['product_reference']['identifier']['value'])) {
            return [
                'device_id' => (string) $detail['product_reference']['identifier']['value'],
                'device_code_type' => 'DEVICE_DEFINITION',
                'quantity_system' => $quantitySystem,
                'quantity_code' => $quantityCode,
            ];
        }

        if (!empty($detail['product_codeable_concept']['coding'][0]['code'])) {
            return [
                'device_id' => (string) $detail['product_codeable_concept']['coding'][0]['code'],
                'device_code_type' => 'CLASSIFICATION_TYPE',
                'quantity_system' => $quantitySystem,
                'quantity_code' => $quantityCode,
            ];
        }

        $fallbackId = (string) ($activity->product_reference ?: $activity->product_codeable_concept ?: '');
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $fallbackId) === 1;

        return [
            'device_id' => $fallbackId,
            'device_code_type' => $isUuid ? 'DEVICE_DEFINITION' : 'CLASSIFICATION_TYPE',
            'quantity_system' => $quantitySystem,
            'quantity_code' => $quantityCode,
        ];
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
     * Creation-shaped payload for cancel PKCS#7 signing (API-007-006-0005).
     *
     * eHealth compares signed content (minus $.detail.status_reason) with the activity as stored
     * from create. GET /activities/{id} adds computed/display fields and different key shapes,
     * so cancel must rebuild the create snapshot locally — not sign the GET response.
     *
     * @return array<string, mixed>
     */
    public function resolveActivityCreationPayloadForCancelSigning(CarePlanActivity $activity): array
    {
        return $this->formatCarePlanActivityRequest($activity);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveActivityPayloadBase(
        CarePlanActivity $activity,
        string $personUuid,
        string $carePlanUuid,
    ): array {
        if (!empty($activity->uuid)) {
            try {
                $response = EHealth::carePlanActivity()->getDetails(
                    $personUuid,
                    $carePlanUuid,
                    (string) $activity->uuid,
                );
                $matchingActivity = $response->getData();
                if (isset($matchingActivity['data']) && is_array($matchingActivity['data'])) {
                    $matchingActivity = $matchingActivity['data'];
                }

                if (is_array($matchingActivity) && $matchingActivity !== []) {
                    return $this->normalizeEHealthActivityForSigning($matchingActivity);
                }
            } catch (\Throwable) {
                // Fall back to locally formatted creation payload.
            }
        }

        return $this->formatCarePlanActivityRequest($activity);
    }

    /**
     * Raw payload for cancel PKCS#7 signing.
     *
     * For API-007-006-0005 we intentionally keep eHealth GET response structure as-is
     * (except wrapper extraction) and only add detail.status_reason later.
     *
     * @return array<string, mixed>
     */
    public function resolveActivityPayloadForCancelSigning(
        CarePlanActivity $activity,
        string $personUuid,
        string $carePlanUuid,
    ): array {
        if (!empty($activity->uuid)) {
            try {
                $response = EHealth::carePlanActivity()->getDetails(
                    $personUuid,
                    $carePlanUuid,
                    (string) $activity->uuid,
                );

                $activityPayload = $response->getData();
                if (isset($activityPayload['data']) && is_array($activityPayload['data'])) {
                    $activityPayload = $activityPayload['data'];
                }

                if (is_array($activityPayload) && $activityPayload !== []) {
                    return $activityPayload;
                }
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        return $this->formatCarePlanActivityRequest($activity);
    }

    /**
     * Detail block for complete PATCH body (transition fields required by eHealth).
     * Note: unlike cancel, the complete action schema only allows 'status_reason' in detail.
     * Including 'do_not_perform' causes a validation error: "schema does not allow additional properties".
     *
     * @param  array<string, mixed>  $statusReasonCodeableConcept
     * @return array<string, mixed>
     */
    public function buildActivityCompletePatchDetail(
        array $statusReasonCodeableConcept,
    ): array {
        return [
            'status_reason' => $statusReasonCodeableConcept,
        ];
    }

    /**
     * PKCS#7 payload for cancel (API-007-006-0005).
     *
     * Signed content must equal the activity stored in eHealth DB, with the only allowed
     * delta being $.detail.status_reason. Validation excludes status_reason before compare.
     * Do not change status / do_not_perform, and do not strip business fields.
     *
     * @param  array<string, mixed>  $activityPayload
     * @param  array<string, mixed>  $statusReasonCodeableConcept
     * @return array<string, mixed>
     */
    public function buildActivityCancelSignPayload(
        array $activityPayload,
        array $statusReasonCodeableConcept,
    ): array {
        $payload = $activityPayload;

        if (!isset($payload['detail']) || !is_array($payload['detail'])) {
            $payload['detail'] = [];
        }

        $payload['detail']['status_reason'] = $statusReasonCodeableConcept;

        return $payload;
    }

    /**
     * Build diagnostics for cancel signature content mismatch.
     *
     * @param  array<string, mixed>  $originalPayload
     * @param  array<string, mixed>  $payloadForSign
     * @return array<string, mixed>
     */
    public function buildCancelSignatureDebugContext(array $originalPayload, array $payloadForSign): array
    {
        $originalSnake = Arr::toSnakeCase($originalPayload);
        $signedSnake = Arr::toSnakeCase($payloadForSign);

        $originalComparable = $this->removeStatusReason($originalSnake);
        $signedComparable = $this->removeStatusReason($signedSnake);

        $diffs = $this->diffPayload($originalComparable, $signedComparable);

        return [
            'original_snake' => $originalSnake,
            'signed_snake' => $signedSnake,
            'diff_count_excluding_status_reason' => count($diffs),
            'diffs_excluding_status_reason' => $diffs,
        ];
    }

    /**
     * PKCS#7 payload for complete — current activity snapshot plus outcome fields.
     *
     * @param  array<string, mixed>  $activityPayload
     * @return array<string, mixed>
     */
    public function buildActivityCompleteSignPayload(
        array $activityPayload,
        ?string $outcomeCode,
        array $outcomeReferences,
    ): array {
        $detail = is_array($activityPayload['detail'] ?? null) ? $activityPayload['detail'] : [];
        $status = $detail['status'] ?? 'scheduled';
        if (strtolower((string) $status) === 'processed') {
            $status = 'scheduled';
        }

        $payload = removeEmptyKeys([
            'id' => $activityPayload['id'] ?? null,
            'author' => $activityPayload['author'] ?? null,
            'care_plan' => $activityPayload['care_plan'] ?? null,
            'detail' => removeEmptyKeys([
                'kind' => $detail['kind'] ?? null,
                'status' => $status,
            ]),
        ]);

        if ($outcomeCode) {
            // eHealth expects outcome_codeable_concept as an array (list) of CodeableConcept objects.
            $payload['outcome_codeable_concept'] = [
                [
                    'coding' => [
                        [
                            'system' => 'eHealth/care_plan_activity_outcomes',
                            'code' => $outcomeCode,
                        ],
                    ],
                ],
            ];
        }

        if ($outcomeReferences !== []) {
            $payload['outcome_reference'] = array_map(
                static fn (string $id): array => ['identifier' => ['value' => $id]],
                $outcomeReferences,
            );
        }

        return $payload;
    }

    /**
     * Prepare eHealth activity details for cancel/complete PKCS#7 signing.
     * Strips read-only/computed fields that are not part of the rendered activity snapshot.
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

            // Create/cancel schema expects author as a list; GET may return a single object.
            if ($snakeKey === 'author' && is_array($value) && !array_is_list($value)) {
                $value = [$value];
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function removeStatusReason(array $payload): array
    {
        if (isset($payload['detail']) && is_array($payload['detail'])) {
            unset($payload['detail']['status_reason']);
        }

        return $payload;
    }

    /**
     * @param  mixed  $left
     * @param  mixed  $right
     * @return list<string>
     */
    private function diffPayload(mixed $left, mixed $right, string $path = ''): array
    {
        if (is_array($left) && is_array($right)) {
            $diffs = [];
            $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));

            foreach ($keys as $key) {
                $nextPath = $path === '' ? (string) $key : $path . '.' . $key;
                $leftHas = array_key_exists($key, $left);
                $rightHas = array_key_exists($key, $right);

                if (!$leftHas || !$rightHas) {
                    $diffs[] = $nextPath . ' (key missing in ' . (!$leftHas ? 'original' : 'signed') . ')';
                    continue;
                }

                $diffs = array_merge($diffs, $this->diffPayload($left[$key], $right[$key], $nextPath));
            }

            return $diffs;
        }

        if ($left !== $right) {
            return [$path . ' (value mismatch)'];
        }

        return [];
    }
}

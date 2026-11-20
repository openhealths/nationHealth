<?php

declare(strict_types=1);

namespace Tests\Fixtures\CarePlan;

use App\Models\CarePlanActivity;

final class ActivityPreview
{
    public static function cards(): array
    {
        $rows = [];
        foreach ([
            'service_request' => ['T67002 — Консультація ендокринолога', 'PIECE'],
            'medication_request' => [self::medications()[0]['name'], 'PIECE'],
            'device_request' => [self::devices()[0]['name'], 'piece'],
        ] as $kind => [$name, $unit]) {
            $activity = (new CarePlanActivity())->forceFill([
                'id' => count($rows) + 1, 'uuid' => 'fixture-'.$kind, 'kind' => $kind,
                'status' => $kind === 'device_request' ? 'scheduled' : 'in-progress',
                'quantity' => 10, 'remaining_quantity' => 8, 'quantity_code' => $unit,
                'daily_amount' => 5, 'daily_amount_code' => $kind === 'medication_request' ? 'MG' : $unit,
                'goal' => ['Покращення стану'], 'scheduled_period_start' => '2026-05-25', 'scheduled_period_end' => '2026-06-26',
            ]);
            $activity->setRelation('author', null);
            $document = [
                'uuid' => 'fixture-document-'.$kind, 'based_on_uuid' => $activity->uuid,
                'quantity' => 3, 'remaining_quantity' => 0, 'status' => 'active', 'created_at' => '2026-05-25',
            ];
            $rows[$kind] = [
                'activity' => $activity, 'compact' => true, 'activityProductLabel' => $name,
                'activityReasonReferenceLabels' => [], 'activityActionsView' => 'care-plan-fixtures::card-actions',
                'activeReferrals' => [$document], 'activePrescriptions' => [$document],
                'dictionaries' => ['MEDICATION_UNIT' => ['MG' => 'мг', 'PIECE' => 'шт'], 'device_unit' => ['piece' => 'шт']],
            ];
        }

        return $rows;
    }

    public static function deviceProgram(): array
    {
        return self::medicationProgram('preview-reimbursement');
    }

    public static function devices(): array
    {
        return [[
            'id' => '00000000-0000-4000-8000-000000000051',
            'name' => 'Реагент швидкого тестування на глюкозу',
            'display_name' => 'Реагент швидкого тестування на глюкозу',
            'display_type' => 'Медичні вироби для визначення рівня глюкози в крові',
            'display_packaging' => 'Коробка 50 штук',
            'search_label' => 'Тест-смужки Реагент швидкого тестування на глюкозу DM RightTest ELSA RightTest Ultra',
            'model_number' => 'DM', 'is_active' => true,
            'classification_types' => [['code' => 'GLUCOSE_TEST', 'name' => 'Реагент швидкого тестування на глюкозу', 'system' => 'device_definition_classification_type']],
            'packaging' => ['packaging_count' => 50, 'packaging_unit' => 'piece'],
            'program_devices' => array_map(static fn (string $name, int $limit): array => [
                'medical_program_id' => 'preview-device-program', 'name' => $name,
                'display_name' => 'Тест-смужки для контролю рівня глюкози в крові '.$name,
                'start_date' => '2023-08-31', 'end_date' => null, 'max_daily_count' => $limit,
            ], ['DM', 'RightTest ELSA', 'RightTest Ultra'], [5, 4, 6]),
        ]];
    }

    public static function medicationProgram(string $id): array
    {
        return [
            'funding_source' => $id === 'preview-reimbursement' ? 'Державні фінансові гарантії' : 'Власні кошти пацієнта',
            'mr_blank_type' => 'Ф-1',
            'medical_program_settings' => [
                'care_plan_required' => true,
                'employee_types_to_create_request' => ['Лікар', 'Спеціаліст'],
                'speciality_types_request_allowed' => ['Загальна практика — сімейна медицина', 'Терапія'],
                'speciality_types_care_plan_activity_allowed' => ['Ендокринологія', 'Терапія'],
                'skip_treatment_period' => false, 'request_max_period_day' => 90,
                'skip_request_employee_declaration_verify' => true,
                'skip_request_legal_entity_declaration_verify' => true,
                'multi_medication_dispense_allowed' => true,
                'request_notification_disabled' => false,
                'patient_categories_allowed' => ['Дорослі', 'Діти'],
            ],
        ];
    }

    public static function medications(): array
    {
        return array_map(static fn (int $index): array => [
            'id' => 'preview-drotaverine-'.$index,
            'name' => $index < 4 ? 'Дротаверин 20 мг/мл, розчин для ін’єкцій' : 'Дротаверин 40 мг, таблетки',
            'innm_name' => 'Дротаверин', 'dosage_form' => $index < 4 ? 'INJECTION' : 'TABLET',
            'dosage_form_label' => $index < 4 ? 'розчин для ін’єкцій' : 'таблетки',
            'release_form' => $index < 4 ? 'ампула' : 'таблетки', 'medication_code_atc' => 'A03AD02',
            'mr_blank_type' => 'Ф-1', 'dosage_form_is_dosed' => true,
            'daily_dosage' => 1, 'max_daily_dosage' => 6,
            'ingredients' => [['name' => 'дротаверин (drotaverine)', 'is_primary' => true, 'dosage' => [
                'numerator_value' => $index < 4 ? 20 : 40, 'numerator_unit' => 'MG',
                'denumerator_value' => 1, 'denumerator_unit' => $index < 4 ? 'ML' : 'PIECE',
            ]]],
            'packages' => [['package_min_qty' => 5, 'package_qty' => 10], ['package_qty' => 20], ['package_qty' => 50], ['package_qty' => 200]],
        ], [1, 2, 3, 4]);
    }

    public static function services(): array
    {
        $service = static fn (string $id, string $code, string $name): array => [
            'id' => $id, 'code' => $code, 'name' => $name, 'category' => 'diagnostic_procedure',
            'is_active' => true, 'request_allowed' => true,
        ];

        return [
            ['id' => 'preview-referrals', 'code' => '32313-113', 'name' => 'Направлення до спеціаліста', 'is_active' => true, 'request_allowed' => true, 'services' => [
                $service('preview-endocrinologist', 'T67002', 'Консультація ендокринолога (Консультація лікаря спеціаліста)'),
            ]],
            ['id' => 'preview-treatment', 'code' => '32313-114', 'name' => 'Лікувально-діагностичні процедури', 'is_active' => false, 'request_allowed' => true, 'services' => []],
            ['id' => 'preview-diagnostics', 'code' => '32313-115', 'name' => 'Діагностичні процедури', 'is_active' => true, 'request_allowed' => true, 'services' => [
                $service('preview-ecg-1', '13121-123', 'Електрокардіографія'),
                $service('preview-ecg-2', '31221-123', 'Електрокардіографія'),
                $service('preview-ecg-3', '5435-123', 'Електрокардіографія'),
            ]],
        ];
    }
}

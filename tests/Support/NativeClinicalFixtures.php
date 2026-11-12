<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\SchemaResponseContract;
use GuzzleHttp\Psr7\Response;
use ReflectionMethod;

/** Explicit complete native Medical Events / General MIS fixtures, independent of schema traversal. */
class NativeClinicalFixtures
{
    public const string UUID = '3e34da3d-9b8c-4aaf-be8e-24a161279b6a';

    public static function reference(string $type, string $value = self::UUID, string $display = 'Reference'): array
    {
        return ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]], 'value' => $value], 'display_value' => $display];
    }

    public static function deviceRequest(string $id, string $personId, array $overrides = []): array
    {
        if (isset($overrides['program'])) {
            $overrides['program'] = array_replace_recursive(self::reference('medical_program'), $overrides['program']);
        }
        foreach ($overrides['parameter'] ?? [] as $index => $parameter) {
            $overrides['parameter'][$index] = array_replace(['code' => ['coding' => [['system' => 'device_request_code_parameter', 'code' => 'presence_of_brakes']]]], $parameter);
        }

        return array_replace_recursive([
            'id' => $id, 'status' => 'active', 'intent' => 'order', 'authored_on' => '2026-10-20T09:00:00Z',
            'occurrence_period' => ['start' => '2026-10-20T09:00:00Z', 'end' => '2026-11-10T09:00:00Z'],
            'code' => ['coding' => [['system' => 'device_definition_classification_type', 'code' => '30221']]],
            'quantity' => ['value' => 50, 'system' => 'device_unit', 'code' => 'piece'],
            'remaining_quantity' => ['value' => 50, 'system' => 'device_unit', 'code' => 'piece', 'unit' => 'шт'],
            'subject' => self::reference('patient', $personId, 'Пацієнт'),
            'encounter' => self::reference('encounter'), 'requester' => self::reference('employee', display: 'Лікар'),
            'requester_legal_entity' => self::reference('legal_entity', display: 'Заклад'),
            'requisition' => 'ABCD-1234-5678-9012', 'dispense_valid_to' => '2026-11-10', 'note' => '',
        ], $overrides);
    }

    public static function deviceDispense(string $id, string $personId, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'status' => 'completed', 'subject' => self::reference('patient', $personId),
            'primary_source' => true, 'note' => '',
            'details' => [['device_code' => ['coding' => [['system' => 'device_definition_classification_type', 'code' => '30221']]], 'quantity' => ['value' => 5, 'system' => 'device_unit', 'code' => 'piece', 'unit' => 'шт']]],
            'inserted_at' => '2026-10-20T09:00:00Z', 'updated_at' => '2026-10-20T09:00:00Z',
            'inserted_by' => self::UUID, 'updated_by' => self::UUID,
        ], $overrides);
    }

    public static function definition(array $overrides = []): array
    {
        $definition = array_replace_recursive([
            'id' => self::UUID, 'device_names' => [['name' => 'Виріб', 'type' => 'user_friendly']],
            'packaging' => ['packaging_type' => 'undefined', 'packaging_count' => 50, 'packaging_unit' => 'piece'],
            'classification_types' => [['system' => 'assistive_devices', 'code' => 'test']],
            'is_active' => true, 'inserted_at' => '2026-10-20T09:00:00Z', 'updated_at' => '2026-10-20T09:00:00Z',
            'inserted_by' => self::UUID, 'updated_by' => self::UUID,
        ], $overrides);
        foreach ($definition['program_devices'] ?? [] as $index => $program) {
            $definition['program_devices'][$index] = array_replace(['id' => self::UUID, 'max_daily_count' => 100, 'care_plan_activity_allowed' => true], $program);
        }

        return $definition;
    }

    public static function configuration(array $overrides = []): array
    {
        $record = array_replace([
            'id' => self::UUID, 'name' => 'assistive_devices', 'code' => 'test', 'system' => 'assistive_devices',
            'is_active' => true, 'settings' => '{}', 'inserted_at' => '2026-10-20T09:00:00Z', 'updated_at' => '2026-10-20T09:00:00Z',
            'inserted_by' => self::UUID, 'updated_by' => self::UUID,
        ], $overrides);
        if (is_array($record['settings'])) {
            $record['settings'] = json_encode($record['settings'], JSON_THROW_ON_ERROR);
        }

        return $record;
    }

    public static function dictionaryValue(array $value): array
    {
        $value = array_replace(['id' => self::UUID, 'inserted_at' => '2026-10-20T09:00:00Z', 'updated_at' => '2026-10-20T09:00:00Z', 'is_active' => true], $value);
        if (isset($value['child_values'])) {
            $value['child_values'] = array_map(self::dictionaryValue(...), $value['child_values']);
        }

        return $value;
    }

    public static function response(string $operation, array $data, ?array $paging = null, ?array $urgent = null): EHealthResponse
    {
        $body = ['data' => $data];
        if ($paging !== null) {
            $body['paging'] = $paging;
        }
        if ($urgent !== null) {
            $body['urgent'] = $urgent;
        }

        return new EHealthResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), static fn (EHealthResponse $response): array => (new SchemaResponseContract())->validate($response, $operation));
    }

    public static function definitionResponse(array $data, ?array $paging = null): EHealthResponse
    {
        $body = ['data' => $data];
        if ($paging !== null) {
            $body['paging'] = $paging;
        }
        $api = new DeviceDefinition();
        $validator = new ReflectionMethod(DeviceDefinition::class, 'validateDefinitions');

        return new EHealthResponse(new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)), static fn (EHealthResponse $response): array => $validator->invoke($api, $response, $paging !== null));
    }

    public static function paging(int $page = 1, int $pages = 1, int $entries = 1): array
    {
        return ['page_number' => $page, 'page_size' => 50, 'total_entries' => $entries, 'total_pages' => $pages];
    }
}

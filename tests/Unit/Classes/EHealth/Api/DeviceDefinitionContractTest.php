<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\DeviceDefinition as DeviceDefinitionApi;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceDefinitionContractTest extends TestCase
{
    private const string UUID = '3e34da3d-9b8c-4aaf-be8e-24a161279b6a';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
    }

    public function test_complete_v2_catalog_preserves_program_caps_and_packaging(): void
    {
        $record = $this->definition();
        $record['properties'] = [
            ['type' => ['system' => 'device_properties', 'code' => 'default'], 'value_boolean' => false],
        ];
        $this->fakeCatalog($this->collection([$record]));

        $result = EHealth::deviceDefinition()->getMany(['medical_program_id' => self::UUID])->validate();

        $this->assertSame([$record], $result);
        $this->assertSame(200, $result[0]['program_devices'][0]['max_daily_count']);
        $this->assertSame(50, $result[0]['packaging']['packaging_count']);
        $this->assertFalse($result[0]['properties'][0]['value_boolean']);
    }

    public function test_empty_search_is_valid_but_paging_is_still_validated(): void
    {
        $this->fakeCatalog($this->collection([]));
        $this->assertSame([], EHealth::deviceDefinition()->getMany()->validate());
    }

    #[DataProvider('invalidDefinitionFields')]
    public function test_incomplete_catalog_cannot_be_used_for_device_quantity(string $path, mixed $value): void
    {
        $record = $this->definition();
        data_set($record, $path, $value);
        $this->fakeCatalog($this->collection([$record]));

        $this->expectException(ValidationException::class);
        EHealth::deviceDefinition()->getMany(['medical_program_id' => self::UUID])->validate();
    }

    public static function invalidDefinitionFields(): array
    {
        return [
            'missing identifier' => ['id', null],
            'missing packaging count' => ['packaging.packaging_count', null],
            'invalid packaging count' => ['packaging.packaging_count', 'many'],
            'missing program cap' => ['program_devices.0.max_daily_count', null],
            'malformed program eligibility' => ['program_devices.0.care_plan_activity_allowed', 'yes'],
            'malformed manufacturer' => ['manufacturer', ['name' => 'Factory']],
            'missing classification code' => ['classification_types.0.code', null],
            'missing timestamp' => ['updated_at', null],
            'multiple property values' => ['properties', [[
                'type' => ['system' => 'device_properties', 'code' => 'default'],
                'value_boolean' => false, 'value_string' => 'blue',
            ]]],
            'missing property value' => ['properties', [['type' => ['system' => 'device_properties', 'code' => 'default']]]],
        ];
    }

    public function test_missing_paging_rejects_otherwise_valid_catalog(): void
    {
        $this->fakeCatalog(['data' => [$this->definition()]]);
        $this->expectException(ValidationException::class);
        EHealth::deviceDefinition()->getMany()->validate();
    }

    public function test_detail_uses_v2_and_rejects_another_definition(): void
    {
        $this->fakeCatalog(['data' => $this->definition()]);
        $this->assertSame($this->definition(), EHealth::deviceDefinition()->getById(self::UUID)->validate());
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/api/v2/device_definitions/'.self::UUID));

        $this->expectException(ValidationException::class);
        EHealth::deviceDefinition()->getById('3bc7a920-53a5-46a6-a88e-b5f298c7b898')->validate();
    }

    private function fakeCatalog(array $body): void
    {
        $factory = Http::getFacadeRoot();
        Http::fake(['ehealth.test/*' => Http::response($body)]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new DeviceDefinitionApi($factory);
        $api->stub($stubs)->preventStrayRequests();
        $this->instance(DeviceDefinitionApi::class, $api);
    }

    private function collection(array $records): array
    {
        return ['data' => $records, 'paging' => [
            'page_number' => 1, 'page_size' => 50, 'total_entries' => count($records), 'total_pages' => count($records) === 0 ? 0 : 1,
        ]];
    }

    /** Based on Get Device definitions V2, preprod General MIS 9.15.9. */
    private function definition(): array
    {
        return [
            'id' => self::UUID,
            'device_names' => [['name' => 'Тест-смужки', 'type' => 'user_friendly']],
            'packaging' => ['packaging_type' => 'undefined', 'packaging_count' => 50, 'packaging_unit' => 'pcs'],
            'classification_types' => [['system' => 'device_definition_classification_type', 'code' => '30221']],
            'program_devices' => [[
                'id' => 'e477a845-972b-47a1-8ca3-1c71e4fd00c2', 'max_daily_count' => 200,
                'care_plan_activity_allowed' => true, 'device_request_allowed' => true,
            ]],
            'is_active' => true,
            'inserted_at' => '2017-04-20T19:14:13Z', 'updated_at' => '2017-04-20T19:14:13Z',
            'inserted_by' => 'e1453f4c-1077-4e85-8c98-c13ffca0063e', 'updated_by' => '2922a240-63db-404e-b730-09222bfeb2dd',
        ];
    }
}

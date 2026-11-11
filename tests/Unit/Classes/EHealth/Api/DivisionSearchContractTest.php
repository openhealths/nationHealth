<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\Division;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DivisionSearchContractTest extends TestCase
{
    private function payload(): array
    {
        return ['data' => [['id' => '10050000-0000-4000-8000-000000000001', 'name' => 'Synthetic division', 'type' => 'CLINIC', 'coordinates' => ['latitude' => 50.4, 'longitude' => 30.5], 'legal_entity' => ['name' => 'Synthetic facility', 'type' => 'OUTPATIENT', 'owner_property_type' => 'PRIVATE', 'legal_form' => 'TEST', 'edrpou' => '12345678', 'addresses' => [['country' => 'UA']], 'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']], 'email' => 'test@example.com'], 'healthcare_services' => [['id' => '10050000-0000-4000-8000-000000000002', 'division_id' => '10050000-0000-4000-8000-000000000001', 'legal_entity_id' => '10050000-0000-4000-8000-000000000003', 'category' => ['coding' => [['system' => 'TEST', 'code' => 'TEST']]], 'status' => 'ACTIVE', 'is_active' => true, 'inserted_at' => '2026-11-11T00:00:00Z', 'inserted_by' => 'employee', 'updated_at' => '2026-11-11T00:00:00Z', 'updated_by' => 'employee', 'available_time' => [['days_of_week' => ['mon'], 'all_day' => true]]]]]], 'paging' => ['page_number' => 1, 'page_size' => 100, 'total_entries' => 1, 'total_pages' => 1]];
    }

    private function response(array $payload): EHealthResponse
    {
        return new EHealthResponse(new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    public function test_complete_public_division_search_contract_is_accepted(): void
    {
        $payload = $this->payload();
        $this->assertSame($payload['data'], Division::validateSearch($this->response($payload)));
    }

    public static function malformedFields(): array
    {
        return [['data.0.id', null], ['data.0.type', 'UNKNOWN'], ['data.0.legal_entity.email', []], ['data.0.legal_entity.addresses', 'invalid'], ['data.0.healthcare_services.0.is_active', 'invalid'], ['data.0.healthcare_services.0.category.coding.0.code', []], ['data.0.healthcare_services.0.available_time.0.days_of_week', 'mon'], ['data.0.coordinates.latitude', 'invalid'], ['paging.total_pages', -1], ['paging.page_number', null]];
    }

    #[DataProvider('malformedFields')]
    public function test_malformed_nested_search_fields_are_rejected(string $field, mixed $value): void
    {
        $payload = $this->payload();
        data_set($payload, $field, $value);
        $this->expectException(ValidationException::class);
        Division::validateSearch($this->response($payload));
    }

    public function test_missing_data_cannot_be_consumed_as_an_empty_search(): void
    {
        $this->expectException(ValidationException::class);
        Division::validateSearch($this->response(['paging' => $this->payload()['paging']]));
    }
}

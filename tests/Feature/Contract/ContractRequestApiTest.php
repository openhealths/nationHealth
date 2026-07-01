<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Classes\eHealth\Api\ContractRequest;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests for ContractRequest API class:
 *  - create() must use POST /api/contract_requests/{contract_type}/{id}
 *  - getMany() must use GET /api/contract_requests/{contract_type} (API-005-012-0007)
 *  - validateDetails() must not fail for NEW status contract requests
 */
class ContractRequestApiTest extends TestCase
{
    // -----------------------------------------------------------------------
    // create() — must use POST /api/contract_requests/{contract_type}/{id}
    // -----------------------------------------------------------------------

    public function test_create_sends_post_request_to_typed_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->contractRequestData(), 'meta' => []], 201)]);

        $api = $this->makeApi();
        $api->create('some-uuid', 'reimbursement', ['signed_content' => 'base64data', 'signed_content_encoding' => 'base64']);

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/api/contract_requests/reimbursement/some-uuid');
        });
    }

    public function test_create_does_not_use_legacy_put_when_primary_route_works(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->contractRequestData(), 'meta' => []], 201)]);

        $api = $this->makeApi();
        $api->create('some-uuid', 'reimbursement', ['signed_content' => 'x', 'signed_content_encoding' => 'base64']);

        Http::assertNotSent(static function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/api/contract_requests/reimbursement/some-uuid');
        });
    }

    public function test_get_signed_content_uses_id_only_route_first(): void
    {
        Http::fake(['*' => Http::response(['data' => ['content' => 'partially-signed']], 200)]);

        $api = $this->makeApi();
        $api->getSignedContent('some-uuid', 'reimbursement');

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'GET'
                && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/api/contract_requests/some-uuid/signed_content');
        });
    }

    public function test_approve_msp_sends_patch_request(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->contractRequestData(['status' => 'PENDING_NHS_SIGN'])], 200)]);

        $api = $this->makeApi();
        $api->approveMsp('some-uuid', 'reimbursement', ['signed_content' => 'sig', 'signed_content_encoding' => 'base64']);

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/api/contract_requests/some-uuid/actions/approve_msp');
        });
    }

    // -----------------------------------------------------------------------
    // getMany() — null safety
    // -----------------------------------------------------------------------

    public function test_get_many_does_not_throw_when_query_options_are_unset(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'paging' => ['page_number' => 1, 'total_pages' => 1],
            ], 200),
        ]);

        $api = $this->makeApi();

        // Must not throw TypeError from array_merge(null, [])
        $this->expectNotToPerformAssertions();
        $api->getMany('reimbursement');
    }

    public function test_get_many_merges_query_params_correctly(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'paging' => ['page_number' => 1, 'total_pages' => 1],
            ], 200),
        ]);

        $api = $this->makeApi();
        $api->getMany('reimbursement', ['contractor_legal_entity_id' => 'some-entity-uuid']);

        Http::assertSent(static function (Request $request): bool {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            return $request->method() === 'GET'
                && str_ends_with($path, '/api/contract_requests/reimbursement')
                && str_contains($request->url(), 'contractor_legal_entity_id=some-entity-uuid')
                && !str_contains($request->url(), 'type=');
        });
    }

    public function test_get_many_passes_page_query_param(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'paging' => ['page_number' => 2, 'total_pages' => 3],
            ], 200),
        ]);

        $api = $this->makeApi();
        $api->getMany('capitation', ['contractor_legal_entity_id' => 'entity-uuid', 'page' => 2]);

        Http::assertSent(static function (Request $request): bool {
            return str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/api/contract_requests/capitation')
                && str_contains($request->url(), 'page=2');
        });
    }

    public function test_validate_many_maps_contract_type_to_type(): void
    {
        $api = new ContractRequest();
        $response = $this->makeEHealthResponseForMany($api, [[
            'id' => 'c4f40d3a-1111-2222-3333-444455556666',
            'contract_type' => 'CAPITATION',
            'status' => 'NEW',
            'contract_number' => '0001-CAP-0001',
        ]]);

        $result = $response->validate();

        $this->assertSame('CAPITATION', $result[0]['type']);
    }

    // -----------------------------------------------------------------------
    // validateDetails() — tested directly without making an HTTP call
    // -----------------------------------------------------------------------

    public function test_validate_details_succeeds_for_new_status_without_contractor_objects(): void
    {
        $api = new ContractRequest();
        $response = $this->makeEHealthResponse($api, [
            'id' => 'c4f40d3a-1111-2222-3333-444455556666',
            'status' => 'NEW',
            // contractor_legal_entity and contractor_owner intentionally absent
        ]);

        try {
            $validated = $response->validate();
            $this->assertSame('NEW', $validated['status']);
        } catch (ValidationException $e) {
            $this->fail('validateDetails() threw ValidationException for a NEW status contract request: ' . $e->getMessage());
        }
    }

    public function test_validate_details_fails_when_uuid_is_missing(): void
    {
        $api = new ContractRequest();
        $response = $this->makeEHealthResponse($api, ['status' => 'NEW']);

        $this->expectException(ValidationException::class);
        $response->validate();
    }

    public function test_validate_details_returns_full_data_not_just_validated_subset(): void
    {
        $api = new ContractRequest();
        $response = $this->makeEHealthResponse($api, [
            'id' => 'c4f40d3a-1111-2222-3333-444455556666',
            'status' => 'NEW',
            'some_extra_ehealth_field' => 'extra_value',
        ]);

        $result = $response->validate();

        $this->assertArrayHasKey('some_extra_ehealth_field', $result, 'validateDetails() must not strip extra fields from the eHealth response');
    }

    // -----------------------------------------------------------------------
    // mapCreate() — pure PHP mapping logic
    // -----------------------------------------------------------------------

    public function test_map_create_maps_api_id_to_uuid_field(): void
    {
        $api = new ContractRequest();
        $mapped = $api->mapCreate(['id' => 'c4f40d3a-1111-2222-3333-444455556666', 'status' => 'NEW']);

        $this->assertSame('c4f40d3a-1111-2222-3333-444455556666', $mapped['uuid']);
        $this->assertArrayNotHasKey('id', $mapped);
    }

    public function test_map_create_extracts_contractor_legal_entity_id_from_nested_object(): void
    {
        $api = new ContractRequest();
        $mapped = $api->mapCreate([
            'id' => 'uuid-1',
            'status' => 'NEW',
            'contractor_legal_entity' => ['id' => 'entity-uuid', 'name' => 'Test'],
        ]);

        $this->assertSame('entity-uuid', $mapped['contractor_legal_entity_id']);
    }

    public function test_map_create_extracts_nhs_signer_id_from_nested_object(): void
    {
        $api = new ContractRequest();
        $mapped = $api->mapCreate([
            'id' => 'uuid-1',
            'status' => 'SIGNED',
            'nhs_signer' => ['id' => 'nhs-uuid'],
        ]);

        $this->assertSame('nhs-uuid', $mapped['nhs_signer_id']);
    }

    public function test_map_create_preserves_full_data_field(): void
    {
        $api = new ContractRequest();
        $mapped = $api->mapCreate(['id' => 'uuid-1', 'status' => 'NEW', 'extra_key' => 'extra_value']);

        $this->assertIsArray($mapped['data']);
        $this->assertSame('extra_value', $mapped['data']['extra_key']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create a ContractRequest instance with Http::fake() stubs properly transferred.
     *
     * Http::fake() stores stubs on the Factory instance. When a PendingRequest subclass is
     * instantiated directly (not through Factory::request()), the stubs are not copied.
     * This helper transfers them via PendingRequest::stub().
     */
    private function makeApi(): ContractRequest
    {
        $factory = Http::getFacadeRoot();
        $api = new ContractRequest($factory);

        // Transfer the factory's protected $stubCallbacks to the PendingRequest instance.
        $stubs = (function () { return $this->stubCallbacks; })->call($factory);
        $api->stub($stubs);

        return $api;
    }

    public function test_map_create_maps_contract_type_field(): void
    {
        $api = new ContractRequest();
        $mapped = $api->mapCreate([
            'id' => 'uuid-1',
            'status' => 'NEW',
            'contract_type' => 'CAPITATION',
        ]);

        $this->assertSame('CAPITATION', $mapped['type']);
    }

    /**
     * Build an EHealthResponse wired to ContractRequest::validateMany() via reflection.
     */
    private function makeEHealthResponseForMany(ContractRequest $api, array $data): EHealthResponse
    {
        $guzzleResponse = new GuzzleResponse(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['data' => $data])
        );

        $reflector = new \ReflectionClass($api);
        $method = $reflector->getMethod('validateMany');
        $method->setAccessible(true);
        $validator = $method->getClosure($api);

        return new EHealthResponse($guzzleResponse, $validator);
    }

    /**
     * Build an EHealthResponse wired to ContractRequest::validateDetails() via reflection.
     */
    private function makeEHealthResponse(ContractRequest $api, array $data): EHealthResponse
    {
        $guzzleResponse = new GuzzleResponse(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['data' => $data])
        );

        $reflector = new \ReflectionClass($api);
        $method = $reflector->getMethod('validateDetails');
        $method->setAccessible(true);
        $validator = $method->getClosure($api);

        return new EHealthResponse($guzzleResponse, $validator);
    }

    private function contractRequestData(): array
    {
        return [
            'id' => 'c4f40d3a-1111-2222-3333-444455556666',
            'status' => 'NEW',
            'contract_number' => '0001-REI-0001',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'type' => 'REIMBURSEMENT',
        ];
    }
}

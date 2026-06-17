<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Classes\eHealth\Api\ContractRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests for ContractRequest API class bugs:
 *  - create() must use PUT, not POST
 *  - getMany() must not throw when options['query'] is null
 *  - validateDetails() must not fail for NEW status contract requests
 */
class ContractRequestApiTest extends TestCase
{
    // -----------------------------------------------------------------------
    // create() — must use PUT
    // -----------------------------------------------------------------------

    public function test_create_sends_put_request(): void
    {
        Http::fake([
            '*/api/contract_requests/reimbursement/*' => Http::response(
                ['data' => $this->contractRequestData(), 'meta' => []],
                201
            ),
        ]);

        $api = app(ContractRequest::class);
        $api->create('some-uuid', 'reimbursement', ['signed_content' => 'base64data', 'signed_content_encoding' => 'base64']);

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/api/contract_requests/reimbursement/some-uuid');
        });
    }

    public function test_create_does_not_send_post_request(): void
    {
        Http::fake([
            '*/api/contract_requests/reimbursement/*' => Http::response(
                ['data' => $this->contractRequestData(), 'meta' => []],
                201
            ),
        ]);

        $api = app(ContractRequest::class);
        $api->create('some-uuid', 'reimbursement', ['signed_content' => 'x', 'signed_content_encoding' => 'base64']);

        Http::assertNotSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/contract_requests/reimbursement/some-uuid');
        });
    }

    // -----------------------------------------------------------------------
    // getMany() — null safety
    // -----------------------------------------------------------------------

    public function test_get_many_does_not_throw_when_query_options_are_unset(): void
    {
        Http::fake([
            '*/api/contract_requests*' => Http::response([
                'data' => [],
                'paging' => ['page_number' => 1, 'total_pages' => 1],
            ], 200),
        ]);

        $api = app(ContractRequest::class);

        // Should not throw TypeError from array_merge(null, [])
        $this->expectNotToPerformAssertions();
        $api->getMany('reimbursement');
    }

    public function test_get_many_merges_query_params_correctly(): void
    {
        Http::fake([
            '*/api/contract_requests*' => Http::response([
                'data' => [],
                'paging' => ['page_number' => 1, 'total_pages' => 1],
            ], 200),
        ]);

        $api = app(ContractRequest::class);
        $api->getMany('reimbursement', ['contractor_legal_entity_id' => 'some-entity-uuid']);

        Http::assertSent(static function (Request $request): bool {
            return str_contains($request->url(), 'contractor_legal_entity_id=some-entity-uuid')
                && str_contains($request->url(), 'type=REIMBURSEMENT');
        });
    }

    // -----------------------------------------------------------------------
    // validateDetails() — must not require contractor_legal_entity for NEW
    // -----------------------------------------------------------------------

    public function test_validate_details_succeeds_for_new_status_without_contractor_objects(): void
    {
        Http::fake([
            '*/api/contract_requests/reimbursement/*' => Http::response([
                'data' => [
                    'id' => 'c4f40d3a-1111-2222-3333-444455556666',
                    'status' => 'NEW',
                    // contractor_legal_entity and contractor_owner intentionally absent
                ],
                'meta' => [],
            ], 200),
        ]);

        $api = app(ContractRequest::class);
        $response = $api->getDetails('reimbursement', 'c4f40d3a-1111-2222-3333-444455556666');

        // validate() must not throw ValidationException for NEW status requests
        try {
            $validated = $response->validate();
            $this->assertSame('NEW', $validated['status']);
        } catch (ValidationException $e) {
            $this->fail('validateDetails() threw ValidationException for a NEW status contract request: ' . $e->getMessage());
        }
    }

    public function test_validate_details_fails_when_uuid_is_missing(): void
    {
        Http::fake([
            '*/api/contract_requests/reimbursement/*' => Http::response([
                'data' => ['status' => 'NEW'],
                'meta' => [],
            ], 200),
        ]);

        $api = app(ContractRequest::class);
        $response = $api->getDetails('reimbursement', 'irrelevant');

        $this->expectException(ValidationException::class);
        $response->validate();
    }

    public function test_validate_details_returns_full_data_not_just_validated_subset(): void
    {
        $extraField = 'some_extra_ehealth_field';
        Http::fake([
            '*/api/contract_requests/reimbursement/*' => Http::response([
                'data' => [
                    'id' => 'c4f40d3a-1111-2222-3333-444455556666',
                    'status' => 'NEW',
                    $extraField => 'extra_value',
                ],
                'meta' => [],
            ], 200),
        ]);

        $api = app(ContractRequest::class);
        $response = $api->getDetails('reimbursement', 'c4f40d3a-1111-2222-3333-444455556666');
        $result = $response->validate();

        // validateDetails() must return the full transformed data, not just validated fields
        $this->assertArrayHasKey($extraField, $result, 'validateDetails() must not strip extra fields from the eHealth response');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

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

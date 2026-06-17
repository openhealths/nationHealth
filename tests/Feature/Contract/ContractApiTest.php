<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Classes\eHealth\Api\Contract;
use Tests\TestCase;

/**
 * Tests for Contract API class: mapCreate() mapping, replaceEHealthPropNames().
 */
class ContractApiTest extends TestCase
{
    private Contract $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = app(Contract::class);
    }

    public function test_map_create_maps_id_to_uuid(): void
    {
        $data = $this->eHealthContractPayload();

        $mapped = $this->api->mapCreate($data);

        $this->assertSame($data['id'], $mapped['uuid']);
        $this->assertArrayNotHasKey('id', $mapped);
    }

    public function test_map_create_extracts_nhs_signer_id_from_nested_object(): void
    {
        $nhsSignerId = 'aabbccdd-1234-5678-abcd-ef0123456789';
        $data = array_merge($this->eHealthContractPayload(), [
            'nhs_signer' => ['id' => $nhsSignerId, 'party' => []],
        ]);

        $mapped = $this->api->mapCreate($data);

        $this->assertSame($nhsSignerId, $mapped['nhs_signer_id']);
    }

    public function test_map_create_falls_back_to_flat_nhs_signer_id(): void
    {
        $nhsSignerId = 'aabbccdd-1234-5678-abcd-ef0123456789';
        $data = array_merge($this->eHealthContractPayload(), [
            'nhs_signer_id' => $nhsSignerId,
        ]);
        unset($data['nhs_signer']);

        $mapped = $this->api->mapCreate($data);

        $this->assertSame($nhsSignerId, $mapped['nhs_signer_id']);
    }

    public function test_map_create_extracts_nhs_legal_entity_id_from_nested_object(): void
    {
        $entityId = 'bbaaccdd-1234-5678-abcd-ef0123456789';
        $data = array_merge($this->eHealthContractPayload(), [
            'nhs_legal_entity' => ['id' => $entityId, 'name' => 'НСЗУ'],
        ]);

        $mapped = $this->api->mapCreate($data);

        $this->assertSame($entityId, $mapped['nhs_legal_entity_id']);
    }

    public function test_map_create_preserves_medical_programs(): void
    {
        $programs = [['id' => 'prog-uuid-1'], ['id' => 'prog-uuid-2']];
        $data = array_merge($this->eHealthContractPayload(), [
            'medical_programs' => $programs,
        ]);

        $mapped = $this->api->mapCreate($data);

        $this->assertSame($programs, $mapped['medical_programs']);
    }

    public function test_map_create_defaults_medical_programs_to_empty_array(): void
    {
        $data = $this->eHealthContractPayload();
        unset($data['medical_programs']);

        $mapped = $this->api->mapCreate($data);

        $this->assertSame([], $mapped['medical_programs']);
    }

    public function test_map_create_stores_full_payload_in_data_field(): void
    {
        $data = $this->eHealthContractPayload();

        $mapped = $this->api->mapCreate($data);

        $this->assertSame($data, $mapped['data']);
    }

    public function test_map_create_defaults_status_when_missing(): void
    {
        $data = $this->eHealthContractPayload();
        unset($data['status']);

        $mapped = $this->api->mapCreate($data);

        $this->assertSame('ACTIVE', $mapped['status']);
    }

    public function test_replace_ehealth_prop_names_renames_id_to_uuid(): void
    {
        $input = ['id' => 'abc-123', 'status' => 'ACTIVE'];

        $result = Contract::replaceEHealthPropNames($input);

        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayNotHasKey('id', $result);
        $this->assertSame('abc-123', $result['uuid']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function eHealthContractPayload(): array
    {
        return [
            'id' => 'c4f40d3a-1111-2222-3333-444455556666',
            'contract_number' => '0001-REI-0001',
            'status' => 'ACTIVE',
            'type' => 'REIMBURSEMENT',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contractor_base' => 'На підставі статуту',
            'contractor_payment_details' => ['payer_account' => 'UA000000000000000000000000000'],
            'contractor_rmsp_amount' => null,
            'contractor_divisions' => [],
            'external_contractor_flag' => false,
            'external_contractors' => [],
            'nhs_signer_id' => null,
            'nhs_legal_entity_id' => null,
            'nhs_signer_base' => null,
            'nhs_payment_method' => null,
            'nhs_contract_price' => null,
            'nhs_signed_date' => null,
            'id_form' => 'GENERAL',
            'issue_city' => 'Київ',
            'medical_programs' => [],
            'inserted_at' => '2026-01-01T00:00:00Z',
        ];
    }
}

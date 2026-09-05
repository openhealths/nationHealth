<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\ContractRequestStatus;
use App\Enums\Contract\Type;
use App\Livewire\Contract\ContractIndex;
use App\Livewire\ContractRequest\ContractRequestIndex;
use App\Models\LegalEntity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ContractListFiltersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_contract_request_reset_filters_clears_status_and_type(): void
    {
        $this->instance('legalEntity', $this->createLegalEntity());

        Livewire::test(ContractRequestIndex::class)
            ->set('statusFilter', [ContractRequestStatus::SIGNED->value])
            ->set('pendingStatusFilter', [ContractRequestStatus::SIGNED->value])
            ->set('typeFilter', [Type::CAPITATION->value])
            ->set('pendingTypeFilter', [Type::CAPITATION->value])
            ->set('search', '0001')
            ->call('resetFilters')
            ->assertSet('statusFilter', [])
            ->assertSet('pendingStatusFilter', [])
            ->assertSet('typeFilter', [])
            ->assertSet('pendingTypeFilter', [])
            ->assertSet('search', '')
            ->assertSet('isFiltersApplied', false);
    }

    public function test_contract_reset_filters_clears_status_and_type(): void
    {
        $this->instance('legalEntity', $this->createLegalEntity());

        Livewire::test(ContractIndex::class)
            ->set('statusFilter', ['VERIFIED'])
            ->set('pendingStatusFilter', ['VERIFIED'])
            ->set('typeFilter', [Type::REIMBURSEMENT->value])
            ->set('pendingTypeFilter', [Type::REIMBURSEMENT->value])
            ->set('search', '0001')
            ->call('resetFilters')
            ->assertSet('statusFilter', [])
            ->assertSet('pendingStatusFilter', [])
            ->assertSet('typeFilter', [])
            ->assertSet('pendingTypeFilter', [])
            ->assertSet('search', '')
            ->assertSet('isFiltersApplied', false);
    }

    public function test_contract_request_apply_filters_uses_pending_status_and_type(): void
    {
        $this->instance('legalEntity', $this->createLegalEntity());

        Livewire::test(ContractRequestIndex::class)
            ->set('pendingStatusFilter', [ContractRequestStatus::SIGNED->value])
            ->set('pendingTypeFilter', [Type::CAPITATION->value])
            ->assertSet('statusFilter', [])
            ->assertSet('typeFilter', [])
            ->call('applyFilters')
            ->assertSet('statusFilter', [ContractRequestStatus::SIGNED->value])
            ->assertSet('typeFilter', [Type::CAPITATION->value]);
    }

    public function test_contract_apply_filters_uses_pending_status_and_type(): void
    {
        $this->instance('legalEntity', $this->createLegalEntity());

        Livewire::test(ContractIndex::class)
            ->set('pendingStatusFilter', ['VERIFIED'])
            ->set('pendingTypeFilter', [Type::REIMBURSEMENT->value])
            ->assertSet('statusFilter', [])
            ->assertSet('typeFilter', [])
            ->call('applyFilters')
            ->assertSet('statusFilter', ['VERIFIED'])
            ->assertSet('typeFilter', [Type::REIMBURSEMENT->value]);
    }

    private function createLegalEntity(string $typeName = 'PHARMACY'): LegalEntity
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')
            ->where('name', $typeName)
            ->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')
                ->insertGetId(['name' => $typeName]);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }
}

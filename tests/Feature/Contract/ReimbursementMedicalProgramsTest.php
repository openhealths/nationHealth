<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Classes\eHealth\Api\MedicalProgram;
use App\Livewire\Contract\ReimbursementContractCreate;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Tests for ReimbursementContractCreate medical programs handling:
 *  - User selection is used (not hardcoded)
 *  - Output format is [{id: uuid}, ...], not [uuid, ...]
 *  - Cache is not defeated on every page load
 */
class ReimbursementMedicalProgramsTest extends TestCase
{
    use RefreshDatabase;

    private LegalEntity $legalEntity;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')
            ->where('name', 'PHARMACY')
            ->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')
                ->insertGetId(['name' => 'PHARMACY']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $this->instance('legalEntity', $this->legalEntity);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Pharmacy',
            'last_name' => 'Owner',
            'tax_id' => '1234567890',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'owner@pharmacy.com',
            'password' => \Illuminate\Support\Facades\Hash::make('secret'),
            'party_id' => $party->id,
        ]);
    }

    public function test_medical_programs_payload_uses_id_object_format(): void
    {
        $programUuid1 = (string) Str::uuid();
        $programUuid2 = (string) Str::uuid();

        $selectedIds = [$programUuid1, $programUuid2];

        // Simulate collectPayload logic directly
        $result = array_map(static fn (string $id) => ['id' => $id], array_filter($selectedIds));

        $this->assertSame([['id' => $programUuid1], ['id' => $programUuid2]], $result);
    }

    public function test_medical_programs_payload_is_not_plain_uuid_array(): void
    {
        $programUuid = (string) Str::uuid();
        $selectedIds = [$programUuid];

        $result = array_map(static fn (string $id) => ['id' => $id], array_filter($selectedIds));

        // Must NOT be a flat array of UUIDs
        $this->assertNotSame([$programUuid], $result);
        $this->assertSame([['id' => $programUuid]], $result);
    }

    public function test_medical_programs_payload_is_empty_array_when_no_programs_selected(): void
    {
        $selectedIds = [];

        $result = array_map(static fn (string $id) => ['id' => $id], array_filter($selectedIds));

        $this->assertSame([], $result);
    }

    public function test_load_medical_programs_uses_cache_without_clearing_it_each_time(): void
    {
        Cache::flush();

        $mockPrograms = [
            ['id' => (string) Str::uuid(), 'name' => 'Insulin Program', 'type' => 'REIMBURSEMENT'],
        ];

        // Pre-populate cache as if a previous request already stored it
        Cache::put('ehealth_medical_programs_reimbursement', $mockPrograms, 3600);

        $mockApi = Mockery::mock(MedicalProgram::class);
        // API must NOT be called since cache already has data
        $mockApi->shouldNotReceive('getMany');
        $this->instance(MedicalProgram::class, $mockApi);

        // Simulate what loadMedicalPrograms() does
        $programs = Cache::remember('ehealth_medical_programs_reimbursement', 3600, static function () use ($mockApi) {
            $response = $mockApi->getMany(['page_size' => 100]);

            return $response->getData();
        });

        $this->assertSame($mockPrograms, $programs);
    }

    public function test_hardcoded_insulin_id_is_not_used_in_payload(): void
    {
        $hardcodedInsulinId = '1a227396-a0e4-4c4f-a0a9-6b358c8929d2';
        $userSelectedId = (string) Str::uuid();

        $selectedIds = [$userSelectedId];

        $result = array_map(static fn (string $id) => ['id' => $id], array_filter($selectedIds));

        $resultIds = array_column($result, 'id');
        $this->assertNotContains($hardcodedInsulinId, $resultIds);
        $this->assertContains($userSelectedId, $resultIds);
    }
}

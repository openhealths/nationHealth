<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Classes\eHealth\Api\Patient\MedicationDispense as MedicationDispenseApi;
use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\MedicationRequest\MedicationRequestIndex;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\SignatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class PharmacyDispenseTest extends TestCase
{
    use DatabaseTransactions;

    protected LegalEntity $pharmacy;

    protected User $user;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PHARMACY')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PHARMACY']);

        $this->pharmacy = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->pharmacy);

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
            'email' => 'pharm_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $division = Division::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Аптека 1',
            'type' => 'DRUGSTORE',
            'email' => 'div@example.com',
            'is_active' => true,
            'legal_entity_id' => $this->pharmacy->id,
            'status' => 'ACTIVE',
            'mountain_group' => false,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Pharmacist',
            'employee_type' => 'PHARMACIST',
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $this->pharmacy->id,
            'division_id' => $division->id,
            'is_active' => true,
            'position' => 'Pharmacist',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);

        $this->user->employees()->attach($this->employee->id);
    }

    public function test_pharmacy_legal_entity_is_detected(): void
    {
        $this->pharmacy->load('type');

        $this->assertTrue($this->pharmacy->isPharmacy());
    }

    public function test_search_finds_prescription_by_request_number(): void
    {
        $this->actingAs($this->user);

        $uuid = (string) Str::uuid();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn([
            'data' => [[
                'id' => $uuid,
                'status' => 'active',
                'request_number' => '0000-70K0-6MTX-K8M8',
                'medication_qty' => 10,
                'medication_id' => (string) Str::uuid(),
                'medical_program_id' => (string) Str::uuid(),
                'medication' => ['name' => 'Test Drug'],
            ]],
        ]);

        $api = Mockery::mock(MedicationRequestApi::class);
        $api->shouldReceive('searchByPharmacy')->once()->andReturn($response);
        $this->app->instance(MedicationRequestApi::class, $api);

        Livewire::test(MedicationRequestIndex::class, ['legalEntity' => $this->pharmacy])
            ->set('requestNumber', '000070K06MTXK8M8')
            ->call('search')
            ->assertSet('hasSearched', true)
            ->assertSet('selectedRequestId', $uuid)
            ->assertSet('medicationQty', '10')
            ->assertSee('Test Drug');
    }

    public function test_dispense_signs_and_flashes_success(): void
    {
        $this->actingAs($this->user);

        $requestId = (string) Str::uuid();
        $dispenseId = (string) Str::uuid();

        $searchResponse = Mockery::mock(EHealthResponse::class);
        $searchResponse->shouldReceive('getData')->andReturn([
            'data' => [[
                'id' => $requestId,
                'status' => 'active',
                'request_number' => '0000-70K0-6MTX-K8M8',
                'medication_qty' => 10,
                'medication_id' => (string) Str::uuid(),
                'medical_program_id' => (string) Str::uuid(),
            ]],
        ]);

        $mrApi = Mockery::mock(MedicationRequestApi::class);
        $mrApi->shouldReceive('searchByPharmacy')->andReturn($searchResponse);
        $this->app->instance(MedicationRequestApi::class, $mrApi);

        $createResponse = Mockery::mock(EHealthResponse::class);
        $createResponse->shouldReceive('getData')->andReturn(['id' => $dispenseId, 'status' => 'new']);
        $processResponse = Mockery::mock(EHealthResponse::class);
        $processResponse->shouldReceive('getData')->andReturn(['id' => $dispenseId, 'status' => 'processed']);

        $dispenseApi = Mockery::mock(MedicationDispenseApi::class);
        $dispenseApi->shouldReceive('create')->once()->andReturn($createResponse);
        $dispenseApi->shouldReceive('process')->once()->with($dispenseId, Mockery::type('array'))->andReturn($processResponse);
        $this->app->instance(MedicationDispenseApi::class, $dispenseApi);

        $signature = Mockery::mock(SignatureService::class);
        $signature->shouldReceive('signData')->once()->andReturn('signed-base64');
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $this->instance(SignatureService::class, $signature);

        Livewire::test(MedicationRequestIndex::class, ['legalEntity' => $this->pharmacy])
            ->set('requestNumber', '0000-70K0-6MTX-K8M8')
            ->call('search')
            ->set('code', '1234')
            ->set('form.knedp', 'acsk_test')
            ->set('form.password', '12345678')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 10))
            ->call('sign')
            ->assertSet('showSignatureModal', false)
            ->assertDispatched('flashMessage');
    }
}

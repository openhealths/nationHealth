<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use App\Livewire\MedicationRequest\MedicationRequestIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;

use App\Models\User;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class MedicationRequestTest extends TestCase
{
    use DatabaseTransactions;
    use MocksBasicDictionaries;

    protected User $user;
    protected LegalEntity $legalEntity;
    protected Employee $employee;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockBasicDictionaries();

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'test_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($this->employee->id);
    }

    public function test_it_can_prequalify_medication_request(): void
    {
        Http::fake([
            '*' => Http::response(['data' => json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['prequalify']], 200),
        ]);
        $this->bindFakedMedicationRequestClient();

        $result = app(MedicationRequestLifecycleService::class)->preQualify([
            'person_id' => 'patient-123',
        ]);

        $this->assertSame('VALID', $result[0]['status']);
        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/medication_request_requests/prequalify')
                && ($request->data()['person_id'] ?? null) === 'patient-123';
        });
    }

    /**
     * Http::fake() stubs live on the Factory. The eHealth client is a PendingRequest
     * subclass and only sees those stubs when they are copied onto the instance.
     */
    private function bindFakedMedicationRequestClient(): void
    {
        $factory = Http::getFacadeRoot();
        $api = new MedicationRequestApi($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);

        $this->app->instance(MedicationRequestApi::class, $api);
    }

    public function test_medication_request_index_component_renders()
    {
        $this->actingAs($this->user);

        Livewire::test(MedicationRequestIndex::class, ['legalEntity' => $this->legalEntity])
            ->assertStatus(200)
            ->assertSee('Електронні рецепти');
    }

    public function test_medication_request_form_component_prequalify()
    {
        $this->actingAs($this->user);

        $mockService = Mockery::mock(MedicationRequestLifecycleService::class);
        $mockService->shouldReceive('preQualify')->once()->andReturn([]);
        $this->app->instance(MedicationRequestLifecycleService::class, $mockService);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', 'uuid-123')
            ->set('medicalProgram', 'program-123')
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('preQualify')
            ->assertSee('PreQualify успішно пройдено');
    }
}

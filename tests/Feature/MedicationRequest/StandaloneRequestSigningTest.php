<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use App\Services\SignatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * The standalone eRx / device-request forms used to submit `base64(json)` as if it were a
 * qualified electronic signature, and told the doctor it had been signed with a KEP.
 * These tests pin the corrected behaviour.
 */
class StandaloneRequestSigningTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected SignatureService $signature;

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
            'email' => 'sign_' . Str::random(6) . '@example.com',
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

        $employee = Employee::create([
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
        $this->user->employees()->attach($employee->id);

        $this->actingAs($this->user);

        $this->signature = Mockery::mock(SignatureService::class);
        $this->signature->shouldReceive('getCertificateAuthorities')->andReturn([
            ['id' => 'knedp-1', 'name' => 'Тестовий КНЕДП'],
        ]);
        $this->app->instance(SignatureService::class, $this->signature);
    }

    public function test_prescription_is_signed_with_the_kep_over_the_ehealth_draft_content(): void
    {
        $draftId = (string) Str::uuid();
        $draftContent = ['id' => $draftId, 'status' => 'NEW', 'medication_id' => (string) Str::uuid()];

        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldReceive('createDraft')->once()->andReturn($draftContent);
        $lifecycle->shouldReceive('sign')
            ->once()
            ->withArgs(static function (string $id, array $payload) use ($draftId): bool {
                return $id === $draftId
                    && $payload['signed_medication_request_request'] === 'REAL-KEP-SIGNATURE'
                    && $payload['signed_content_encoding'] === 'base64';
            })
            ->andReturn([]);
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        $this->signature->shouldReceive('signData')
            ->once()
            ->withArgs(static function (array $data, string $password, string $knedp, $keyFile, string $taxId) use ($draftContent): bool {
                return $data === $draftContent
                    && $password === 'secret'
                    && $knedp === 'knedp-1'
                    && $keyFile instanceof UploadedFile
                    && $taxId === '9876543210';
            })
            ->andReturn('REAL-KEP-SIGNATURE');

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())
            ->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('createDraft')
            ->assertSet('isDraftCreated', true)
            ->assertSet('draftId', $draftId)
            ->set('form.knedp', 'knedp-1')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 10))
            ->set('form.password', 'secret')
            ->call('sign')
            ->assertSet('showSignatureModal', false)
            ->assertHasNoErrors();
    }

    public function test_prescription_cannot_be_signed_without_kep_credentials(): void
    {
        $draftId = (string) Str::uuid();

        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldReceive('createDraft')->once()->andReturn(['id' => $draftId]);
        $lifecycle->shouldNotReceive('sign');
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        $this->signature->shouldNotReceive('signData');

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())
            ->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('createDraft')
            ->call('sign')
            ->assertHasErrors(['form.knedp', 'form.keyContainerUpload', 'form.password']);
    }

    public function test_prescription_signing_is_refused_before_a_draft_exists(): void
    {
        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('sign');
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->call('sign')
            ->assertSet('showSignatureModal', false);
    }

    public function test_draft_without_an_identifier_is_not_treated_as_created(): void
    {
        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldReceive('createDraft')->once()->andReturn(['status' => 'NEW']);
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())
            ->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('createDraft')
            ->assertSet('isDraftCreated', false)
            ->assertSet('draftId', null);
    }

    public function test_device_request_is_signed_with_the_kep_over_the_ehealth_draft_content(): void
    {
        $draftId = (string) Str::uuid();
        $draftContent = ['id' => $draftId, 'status' => 'NEW'];

        $lifecycle = Mockery::mock(DeviceRequestLifecycleService::class);
        $lifecycle->shouldReceive('createDraft')->once()->andReturn($draftContent);
        $lifecycle->shouldReceive('sign')
            ->once()
            ->withArgs(static function (string $id, array $payload) use ($draftId): bool {
                return $id === $draftId
                    && $payload['signed_device_request_request'] === 'REAL-KEP-SIGNATURE';
            })
            ->andReturn([]);
        $this->app->instance(DeviceRequestLifecycleService::class, $lifecycle);

        $this->signature->shouldReceive('signData')->once()->andReturn('REAL-KEP-SIGNATURE');

        Livewire::test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())
            ->set('medicalProgram', (string) Str::uuid())
            ->set('deviceType', 'device-code')
            ->set('quantity', '2')
            ->call('createDraft')
            ->assertSet('draftId', $draftId)
            ->set('form.knedp', 'knedp-1')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 10))
            ->set('form.password', 'secret')
            ->call('sign')
            ->assertSet('showSignatureModal', false)
            ->assertHasNoErrors();
    }
}

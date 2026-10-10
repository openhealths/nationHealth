<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Classes\eHealth\Api\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\DeviceRequest\DraftResult as DeviceDraftResult;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Dto\MedicationRequest\DraftResult;
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

        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult($draftContent, $draftContent));
        $lifecycle->shouldReceive('signAndResolve')
            ->once()
            ->withArgs(static function (string $id, array $payload) use ($draftId): bool {
                return $id === $draftId
                    && $payload['signed_medication_request_request'] === 'REAL-KEP-SIGNATURE'
                    && $payload['signed_content_encoding'] === 'base64';
            })
            ->andReturn([]);
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

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

    public function test_original_draft_is_retained_when_resolved_job_contains_only_metadata(): void
    {
        $draftId = (string) Str::uuid();
        $raw = ['id' => $draftId, 'person' => ['id' => 'patient-id'], 'unknown_extension' => ['zero' => 0, 'list' => []]];
        $api = Mockery::mock(MedicationRequestApi::class);
        $api->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult(
            ['data' => $raw],
            ['id' => $draftId, 'status' => 'processed']
        ));
        $this->instance(MedicationRequestApi::class, $api);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')->set('duration', '30')
            ->call('createDraft')->assertSet('isDraftCreated', true)
            ->assertSet('draftId', $draftId)->assertSet('draftContent', $raw);
    }

    public function test_prescription_cannot_be_signed_without_kep_credentials(): void
    {
        $draftId = (string) Str::uuid();

        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult(['id' => $draftId], ['id' => $draftId]));
        $lifecycle->shouldNotReceive('signAndResolve');
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

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
        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldNotReceive('signAndResolve');
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->call('sign')
            ->assertSet('showSignatureModal', false);
    }

    public function test_draft_without_an_identifier_is_not_treated_as_created(): void
    {
        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult(['status' => 'NEW'], ['status' => 'NEW']));
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

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

        $lifecycle = Mockery::mock(DeviceRequestApi::class);
        $lifecycle->shouldReceive('createAndResolve')->once()->andReturn(new DeviceDraftResult($draftContent, $draftContent));
        $lifecycle->shouldReceive('signAndResolve')
            ->once()
            ->withArgs(static function (string $id, array $payload) use ($draftId): bool {
                return $id === $draftId
                    && $payload['signed_device_request_request'] === 'REAL-KEP-SIGNATURE';
            })
            ->andReturn([]);
        $this->app->instance(DeviceRequestApi::class, $lifecycle);

        $this->signature->shouldReceive('signData')->once()
            ->withArgs(static fn (array $data, string $password, string $knedp, $file, string $taxId): bool =>
                $data === $draftContent && $password === 'secret' && $knedp === 'knedp-1'
                && $file instanceof UploadedFile && $taxId === '9876543210')
            ->andReturn('REAL-KEP-SIGNATURE');

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

    public function test_device_prequalify_does_not_report_success_for_an_invalid_verdict(): void
    {
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('prequalifyAndValidate')->once()
            ->with(['person_id' => 'person-id', 'programs' => [['id' => 'program-id']]])
            ->andThrow(new EHealthValidationException(['error' => ['message' => 'Program not covered']]));
        $this->instance(DeviceRequestApi::class, $api);

        $this->deviceForm()->call('preQualify')->assertSet('statusMessage', __('Помилка від ЕСОЗ:').' Program not covered')
            ->assertSet('isDraftCreated', false);
    }

    public function test_device_create_keeps_original_raw_when_job_returns_only_metadata(): void
    {
        $raw = ['id' => 'device-id', 'status' => 'NEW', 'unknown_extension' => ['zero' => 0, 'list' => []]];
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('createAndResolve')->once()->andReturn(new DeviceDraftResult(
            ['device_request_request' => $raw],
            ['id' => 'device-id', 'status' => 'processed']
        ));
        $this->instance(DeviceRequestApi::class, $api);

        $this->deviceForm()->call('createDraft')->assertSet('isDraftCreated', true)
            ->assertSet('draftId', 'device-id')->assertSet('draftContent', $raw);
    }

    public function test_device_create_failure_does_not_enable_signing(): void
    {
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('createAndResolve')->once()
            ->andThrow(new EHealthValidationException(['error' => ['message' => 'Creation rejected']]));
        $api->shouldNotReceive('signAndResolve');
        $this->instance(DeviceRequestApi::class, $api);
        $this->signature->shouldNotReceive('signData');

        $this->deviceForm()->call('createDraft')->assertSet('isDraftCreated', false)->assertSet('draftId', null)
            ->call('sign')->assertNotDispatched('device-request-created');
    }

    public function test_device_job_metadata_without_a_document_does_not_enable_signing(): void
    {
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('createAndResolve')->once()->andReturn(new DeviceDraftResult(
            ['job_id' => 'create-job'],
            ['id' => 'device-id', 'status' => 'processed']
        ));
        $api->shouldNotReceive('signAndResolve');
        $this->instance(DeviceRequestApi::class, $api);
        $this->signature->shouldNotReceive('signData');

        $this->deviceForm()->call('createDraft')->assertSet('isDraftCreated', false)
            ->assertSet('draftId', null)->assertSet('draftContent', [])
            ->assertSet('statusMessage', __('care-plan.draft_missing_document'))->call('sign');
    }

    public function test_failed_device_sign_job_preserves_draft_clears_credentials_and_emits_no_success(): void
    {
        $raw = ['id' => 'device-id', 'status' => 'NEW', 'unknown_extension' => ['zero' => 0, 'flag' => false]];
        $api = Mockery::mock(DeviceRequestApi::class)->makePartial();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn(['job_id' => 'sign-job']);
        $api->shouldReceive('signDeviceRequest')->once()
            ->with('device-id', ['signed_device_request_request' => 'REAL-KEP-SIGNATURE', 'signed_content_encoding' => 'base64'])
            ->andReturn($response);
        $this->instance(DeviceRequestApi::class, $api);
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolve')->once()->with(['job_id' => 'sign-job'])
            ->andThrow(new EHealthValidationException(['error' => ['message' => 'Signing rejected']]));
        $this->instance(Job::class, $job);
        $this->signature->shouldReceive('signData')->once()
            ->withArgs(static fn (array $data): bool => $data === $raw)->andReturn('REAL-KEP-SIGNATURE');

        $this->deviceForm()->set('isDraftCreated', true)->set('draftId', 'device-id')->set('draftContent', $raw)
            ->set('showSignatureModal', true)->set('form.knedp', 'knedp-1')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 10))
            ->set('form.password', 'secret')->call('sign')
            ->assertSet('statusMessage', __('Помилка від ЕСОЗ:').' Signing rejected')->assertSet('isDraftCreated', true)
            ->assertSet('draftContent', $raw)->assertSet('showSignatureModal', false)
            ->assertSet('form.password', '')->assertSet('form.keyContainerUpload', null)
            ->assertNotDispatched('device-request-created');
    }

    private function deviceForm(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', 'person-id')->set('medicalProgram', 'program-id')
            ->set('deviceType', 'device-code')->set('quantity', '2');
    }
}

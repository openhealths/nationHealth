<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostic;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequest;
use App\Classes\eHealth\Api\ServiceRequest as ExecutorServiceRequest;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Livewire\Encounter\Concerns\ManagesEncounterEPrescription;
use App\Livewire\Encounter\Concerns\ManagesEncounterReferrals;
use App\Livewire\Encounter\Concerns\ResolvesEncounterStandaloneContext;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Regression suite for e-referral / e-prescription create & redeem after PR #652 / #768.
 *
 * @group diagnostic
 * @group referral
 * @group eprescription
 */
class ElectronicReferralAndPrescriptionDiagnosticTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Employee $employee;

    protected Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'diag_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олена',
            'last_name' => 'Коваль',
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);
    }

    public function test_ehealth_service_request_facade_exposes_executor_actions(): void
    {
        $api = EHealth::serviceRequest();

        $this->assertInstanceOf(PatientServiceRequest::class, $api);

        foreach (['qualify', 'process', 'complete', 'cancelUsage'] as $method) {
            $this->assertTrue(method_exists($api, $method));
        }

        $executor = app(ExecutorServiceRequest::class);
        foreach (['qualify', 'process', 'complete', 'cancelUsage'] as $method) {
            $this->assertTrue(method_exists($executor, $method));
        }
    }

    public function test_take_into_work_does_not_call_missing_patient_api_methods(): void
    {
        $referralUuid = (string) Str::uuid();
        $programId = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => ServiceRequestStatus::ACTIVE->value,
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'program_id' => $programId,
            'priority' => 'routine',
        ]);

        $this->bindExecutorApi([
            'qualify' => ['data' => [['status' => 'VALID']]],
            'process' => ['status' => ServiceRequestStatus::IN_PROGRESS->value],
        ]);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->takeIntoWork($referralUuid, $this->employee, $this->person->uuid);

        $this->assertSame(ServiceRequestStatus::IN_PROGRESS->value, $result['status'] ?? null);
    }

    public function test_complete_referral_uses_available_complete_api(): void
    {
        $referralUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => ServiceRequestStatus::IN_PROGRESS->value,
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'priority' => 'routine',
        ]);

        $this->createEncounter($encounterUuid);
        $this->bindExecutorApi([
            'complete' => ['status' => ServiceRequestStatus::COMPLETED->value],
        ]);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->completeReferral($referralUuid, $encounterUuid);

        $this->assertSame(ServiceRequestStatus::COMPLETED->value, $result['status'] ?? null);
    }

    public function test_take_into_work_from_search_includes_program_when_not_local(): void
    {
        $referralUuid = (string) Str::uuid();
        $programId = (string) Str::uuid();

        $capturedPayload = null;
        $mock = Mockery::mock(ExecutorServiceRequest::class);
        $mock->shouldReceive('qualify')
            ->once()
            ->andReturn($this->responseWithData(['data' => [['status' => 'VALID']]]));
        $mock->shouldReceive('process')
            ->once()
            ->withArgs(function (string $uuid, array $payload) use ($referralUuid, &$capturedPayload): bool {
                $capturedPayload = $payload;

                return $uuid === $referralUuid;
            })
            ->andReturn($this->responseWithData([
                'status' => ServiceRequestStatus::IN_PROGRESS->value,
                'requisition' => 'SR-DIAG-1',
                'code' => ['identifier' => ['value' => '59300-00']],
                'quantity' => ['value' => 1],
                'category' => ['coding' => [['code' => 'procedure']]],
                'intent' => 'order',
            ]));
        $this->app->instance(ExecutorServiceRequest::class, $mock);

        $service = app(ReferralRequestLifecycleService::class);
        $service->takeIntoWork($referralUuid, $this->employee, $this->person->uuid, [
            'program_id' => $programId,
        ]);

        $this->assertSame($programId, data_get($capturedPayload, 'program.identifier.value'));
    }

    public function test_standalone_referral_drawer_defaults_to_pmg_program(): void
    {
        $pmgId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $otherId = '11111111-2222-3333-4444-555555555555';

        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('medicalPrograms')->andReturn(collect([
            [
                'id' => $otherId,
                'name' => 'Інша програма',
                'type' => 'SERVICE',
                'is_active' => true,
            ],
            [
                'id' => $pmgId,
                'name' => 'Програма державних фінансових гарантій медичного обслуговування населення',
                'type' => 'SERVICE',
                'is_active' => true,
            ],
        ]));
        $this->instance(DictionaryManager::class, $manager);

        $encounter = $this->createEncounter((string) Str::uuid(), EncounterStatus::FINISHED->value);
        $harness = new DiagnosticEncounterStandaloneHarness();
        $harness->encounterId = $encounter->id;
        $harness->openEncounterReferralDrawer();

        $this->assertTrue($harness->showEncounterReferralDrawer);
        $this->assertSame($pmgId, $harness->encounterReferralForm['program_id']);
    }

    public function test_complete_referral_does_not_mark_local_completed_while_job_pending(): void
    {
        $referralUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();
        $jobId = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => ServiceRequestStatus::IN_PROGRESS->value,
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'priority' => 'routine',
        ]);

        $this->createEncounter($encounterUuid);

        config([
            'ehealth.jobs.max_attempts' => 1,
            'ehealth.jobs.interval_seconds' => 0,
        ]);

        $this->bindExecutorApi([
            'complete' => [
                'status' => 'pending',
                'links' => [['href' => '/api/jobs/'.$jobId]],
            ],
        ]);

        $jobApi = Mockery::mock(\App\Classes\eHealth\Api\Job::class);
        $jobApi->shouldReceive('getDetails')
            ->andReturn($this->responseWithData(['status' => 'pending']));
        $jobApi->shouldReceive('getDetailsByHref')
            ->andReturn($this->responseWithData(['status' => 'pending']));
        $this->app->instance(\App\Classes\eHealth\Api\Job::class, $jobApi);

        $service = app(ReferralRequestLifecycleService::class);

        try {
            $service->completeReferral($referralUuid, $encounterUuid);
            $this->fail('Expected completeReferral to reject pending job before returning.');
        } catch (\Throwable) {
            // Expected: timeout or validation from job resolver.
        }

        $local = ServiceRequestRequest::query()->where('uuid', $referralUuid)->first();
        $this->assertNotSame(ServiceRequestStatus::COMPLETED->value, $local?->status);
    }

    /**
     * @param  array<string, array<string, mixed>>  $methods
     */
    private function bindExecutorApi(array $methods): void
    {
        $mock = Mockery::mock(ExecutorServiceRequest::class);
        foreach ($methods as $method => $data) {
            $mock->shouldReceive($method)->andReturn($this->responseWithData($data));
        }
        $this->app->instance(ExecutorServiceRequest::class, $mock);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function responseWithData(array $data): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn($data);

        return $response;
    }

    private function createEncounter(string $uuid, string $status = 'finished'): Encounter
    {
        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

        return Encounter::create([
            'uuid' => $uuid,
            'person_id' => $this->person->id,
            'status' => $status,
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);
    }
}

/**
 * Lightweight host for standalone encounter referral drawer diagnostics.
 */
class DiagnosticEncounterStandaloneHarness
{
    use ResolvesEncounterStandaloneContext;
    use ManagesEncounterEPrescription;
    use ManagesEncounterReferrals;

    public int $encounterId;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    public function dispatch(string $event, mixed ...$params): static
    {
        return $this;
    }
}

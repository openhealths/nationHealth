<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostic;

use App\Classes\eHealth\Api\Job as JobApi;
use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequest;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\Api\ServiceRequest as ExecutorServiceRequest;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterEPrescription;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use App\Livewire\EncounterPackage\Concerns\ResolvesEncounterStandaloneContext;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Component;
use Mockery;
use ReflectionMethod;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\Support\NativeClinicalFixtures;
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
    use GrantsReferralEmployeeRole;

    private const string SERVICE_UUID = '59300000-0000-4000-8000-000000000001';

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Employee $employee;

    protected Person $person;

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
            'email' => 'diag_'.Str::random(6).'@example.com',
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
            'start_date' => '2020-01-01',
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($this->employee->id);
        $this->grantReferralEmployeeRole($this->user, $this->employee);
        foreach (['service_request:write', 'service_request:use', 'service_request:complete', 'encounter:read'] as $scope) {
            $permission = Permission::findOrCreate($scope, 'web');
            $permission->legalEntityTypes()->syncWithoutDetaching([$typeId]);
            $this->user->givePermissionToParent($permission);
        }
        $this->actingAs($this->user);
        Session::put(config('ehealth.api.oauth.bearer_token'), 'diagnostic-test-token');

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олена',
            'last_name' => 'Коваль',
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);
        Approval::create([
            'uuid' => (string) Str::uuid(), 'approvable_type' => Person::class, 'approvable_id' => $this->person->id,
            'granted_to_id' => Identifier::create(['value' => $this->employee->uuid])->id,
            'granted_to_type' => 'employee', 'access_level' => 'read', 'status' => 'active', 'is_verified' => true,
            'expires_at' => now()->addHour(),
        ]);
        Bus::fake();
        Notification::fake();
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
        $this->mockRemoteReferral($referralUuid, $programId);

        $record = $this->storeReferral($this->nativeReferral($referralUuid, $programId));

        $this->bindExecutorApi([
            'qualify' => $this->qualificationResponse($programId),
            'process' => $this->submissionResponse($this->nativeReferral($referralUuid, $programId, [
                'program_processing_status' => 'in_progress',
                'used_by_employee' => NativeClinicalFixtures::reference('employee', $this->employee->uuid),
                'used_by_legal_entity' => NativeClinicalFixtures::reference('legal_entity', $this->legalEntity->uuid),
            ])),
        ]);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->takeIntoWork($referralUuid, $this->employee, $this->person->uuid);

        $this->assertSame(ServiceRequestStatus::ACTIVE->value, $result['status']);
        $this->assertSame('in_progress', $result['program_processing_status']);
        $this->assertSame(ServiceRequestStatus::IN_PROGRESS->value, $record->fresh()->status);
        $this->assertSame(ServiceRequestStatus::ACTIVE->value, $record->fresh()->clinicalStatus);
        $this->assertSame('in_progress', $record->fresh()->programProcessingStatus);
    }

    public function test_complete_referral_uses_available_complete_api(): void
    {
        $referralUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $programId = (string) Str::uuid();
        $native = $this->nativeReferral($referralUuid, $programId, [
            'program_processing_status' => 'in_progress',
            'used_by_employee' => NativeClinicalFixtures::reference('employee', $this->employee->uuid),
            'used_by_legal_entity' => NativeClinicalFixtures::reference('legal_entity', $this->legalEntity->uuid),
        ]);
        $record = $this->storeReferral($native);
        $this->createEncounter($encounterUuid, referralUuid: $referralUuid);
        $this->mockRemoteReferral($referralUuid, $programId, $native, true);
        $this->bindExecutorApi([
            'complete' => $this->submissionResponse(array_replace($native, [
                'status' => ServiceRequestStatus::COMPLETED->value,
                'program_processing_status' => 'completed',
                'completed_with' => NativeClinicalFixtures::reference('encounter', $encounterUuid),
            ])),
        ]);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->completeReferral($referralUuid, $encounterUuid);

        $this->assertSame(ServiceRequestStatus::COMPLETED->value, $result['status']);
        $this->assertSame(ServiceRequestStatus::COMPLETED->value, $record->fresh()->status);
    }

    public function test_take_into_work_from_search_includes_program_when_not_local(): void
    {
        $referralUuid = (string) Str::uuid();
        $programId = (string) Str::uuid();
        $this->mockRemoteReferral($referralUuid, $programId);

        $capturedPayload = null;
        $mock = Mockery::mock(ExecutorServiceRequest::class);
        $mock->shouldReceive('qualify')
            ->once()
            ->andReturn($this->qualificationResponse($programId));
        $mock->shouldReceive('process')
            ->once()
            ->withArgs(function (string $uuid, array $payload) use ($referralUuid, &$capturedPayload): bool {
                $capturedPayload = $payload;

                return $uuid === $referralUuid;
            })
            ->andReturn($this->submissionResponse($this->nativeReferral($referralUuid, $programId, [
                'program_processing_status' => 'in_progress',
                'used_by_employee' => NativeClinicalFixtures::reference('employee', $this->employee->uuid),
                'used_by_legal_entity' => NativeClinicalFixtures::reference('legal_entity', $this->legalEntity->uuid),
            ])));
        $this->app->instance(ExecutorServiceRequest::class, $mock);

        $service = app(ReferralRequestLifecycleService::class);
        $service->takeIntoWork($referralUuid, $this->employee, $this->person->uuid, [
            'program_id' => $programId,
        ]);

        $this->assertSame($programId, data_get($capturedPayload, 'program.identifier.value'));
        $this->assertSame('in_progress', Repository::serviceRequest()->findByUuid($referralUuid)->programProcessingStatus);
    }

    public function test_standalone_referral_drawer_defaults_to_pmg_program(): void
    {
        $pmgId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $otherId = '11111111-2222-3333-4444-555555555555';

        $manager = Mockery::mock(DictionaryManager::class)->makePartial();
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
        $personApi = $this->mock(PersonApi::class);
        $authValidator = new ReflectionMethod(PersonApi::class, 'validateAuthMethods');
        $personApi->shouldReceive('getAuthMethods')->once()->with($this->person->uuid)
            ->andReturn($this->nativeResponse([], static fn (EHealthResponse $response): array => $authValidator->invoke(new PersonApi(), $response)));

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

        $programId = (string) Str::uuid();
        $native = $this->nativeReferral($referralUuid, $programId, [
            'program_processing_status' => 'in_progress',
            'used_by_employee' => NativeClinicalFixtures::reference('employee', $this->employee->uuid),
            'used_by_legal_entity' => NativeClinicalFixtures::reference('legal_entity', $this->legalEntity->uuid),
        ]);
        $record = $this->storeReferral($native);
        $this->createEncounter($encounterUuid, referralUuid: $referralUuid);
        $this->mockRemoteReferral($referralUuid, $programId, $native, true);

        $this->bindExecutorApi([
            'complete' => $this->submissionResponse([
                'status' => 'pending',
                'links' => [['entity' => 'job', 'href' => '/api/jobs/'.$jobId]],
            ], 202),
        ]);

        $jobApi = Mockery::mock(JobApi::class);
        $jobValidator = new ReflectionMethod(JobApi::class, 'validateDetails');
        $jobApi->shouldNotReceive('getDetails');
        $jobApi->shouldReceive('getDetailsByHref')
            ->once()->with('/api/jobs/'.$jobId)
            ->andReturn($this->nativeResponse(['status' => 'pending'], static fn (EHealthResponse $response): array => $jobValidator->invoke(new JobApi(), $response)));
        $this->app->instance(JobApi::class, $jobApi);

        $service = app(ReferralRequestLifecycleService::class);

        // An accepted completion is pending until its tracked job confirms the final resource.
        $result = $service->completeReferral($referralUuid, $encounterUuid);
        $this->assertTrue($result['submissionPending']);
        $this->assertTrue($service->checkCompletion($record->fresh())['submissionPending']);
        $this->assertSame(ServiceRequestStatus::ACTIVE->value, $record->fresh()->status);
        $this->assertSame('in_progress', $record->fresh()->programProcessingStatus);
        $link = $record->ehealthLinks()->sole();
        $this->assertSame(JobStatus::PENDING->value, $link->status);
        $this->assertSame('/api/jobs/'.$jobId, $link->href);
        Bus::assertBatchCount(1);
    }

    /**
     * @param  array<string, EHealthResponse>  $methods
     */
    private function bindExecutorApi(array $methods): void
    {
        $mock = Mockery::mock(ExecutorServiceRequest::class);
        foreach ($methods as $method => $response) {
            $mock->shouldReceive($method)->once()->andReturn($response);
        }
        $this->app->instance(ExecutorServiceRequest::class, $mock);
    }

    private function mockRemoteReferral(string $uuid, string $programId, array $overrides = [], bool $preserveApiKeys = false): void
    {
        $api = Mockery::mock(PatientServiceRequest::class)->makePartial();
        $validator = new ReflectionMethod(PatientServiceRequest::class, 'validateDetails');
        $expectation = $api->shouldReceive('getById')->once();
        $preserveApiKeys
            ? $expectation->with($this->person->uuid, $uuid, [], true)
            : $expectation->with($this->person->uuid, $uuid);
        $expectation->andReturn($this->nativeResponse(
            $this->nativeReferral($uuid, $programId, $overrides),
            static fn (EHealthResponse $response): array => $validator->invoke(new PatientServiceRequest(), $response, $preserveApiKeys)
        ));
        $this->instance(PatientServiceRequest::class, $api);
    }

    private function nativeResponse(array $data, callable $validator, int $status = 200): EHealthResponse
    {
        return new EHealthResponse(new HttpResponse($status, ['Content-Type' => 'application/json'], json_encode(['data' => $data], JSON_THROW_ON_ERROR)), $validator(...));
    }

    private function qualificationResponse(string $programId): EHealthResponse
    {
        return $this->nativeResponse([['program_id' => $programId, 'program_name' => 'Diagnostic program', 'status' => 'VALID']], PatientServiceRequest::validateQualification(...));
    }

    private function submissionResponse(array $data, int $status = 200): EHealthResponse
    {
        return $this->nativeResponse($data, PatientServiceRequest::validateSubmission(...), $status);
    }

    private function nativeReferral(string $uuid, string $programId, array $overrides = []): array
    {
        $native = array_replace(Arr::toSnakeCase(json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR)), [
            'id' => $uuid, 'requisition' => 'SR-DIAGNOSTIC-001', 'status' => 'active', 'program_processing_status' => 'new',
            'expiration_date' => now()->addMonth()->toIso8601String(),
            'occurrence_period' => ['start' => now()->addHour()->toIso8601String(), 'end' => now()->addWeek()->toIso8601String()],
            'subject' => NativeClinicalFixtures::reference('patient', $this->person->uuid),
            'code' => NativeClinicalFixtures::reference('service', self::SERVICE_UUID),
            'requester_employee' => NativeClinicalFixtures::reference('employee', $this->employee->uuid),
            'requester_legal_entity' => NativeClinicalFixtures::reference('legal_entity', $this->legalEntity->uuid),
            'program' => NativeClinicalFixtures::reference('medical_program', $programId),
            'quantity' => ['value' => 1, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'],
            'remaining_quantity' => ['value' => 0, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'],
            'based_on' => [], 'focus' => [], 'specimens' => [],
        ], $overrides);

        return PatientServiceRequest::validateResourceData($native);
    }

    private function storeReferral(array $native): ServiceRequestRequest
    {
        return Repository::serviceRequest()->importResource((new ServiceRequestMapper())->normalize($native), $this->person, $this->employee->id);
    }

    private function createEncounter(string $uuid, string $status = 'finished', ?string $referralUuid = null): Encounter
    {
        $episode = Episode::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'active',
            'name' => 'Diagnostic referral episode',
            'managing_organization_id' => Identifier::create(['value' => $this->legalEntity->uuid])->id,
        ]);
        $identifierId = Identifier::create(['value' => $episode->uuid])->id;
        $codingId = Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = CodeableConcept::create()->id;

        $encounter = Encounter::create([
            'uuid' => $uuid,
            'person_id' => $this->person->id,
            'status' => $status,
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'performer_id' => Identifier::create(['value' => $this->employee->uuid])->id,
            'incoming_referral_id' => $referralUuid === null ? null : Identifier::create(['value' => $referralUuid])->id,
            'ehealth_inserted_at' => now(),
        ]);
        $encounter->actionReferences()->attach(Identifier::create(['value' => self::SERVICE_UUID])->id);

        return $encounter;
    }
}

/**
 * Lightweight host for standalone encounter referral drawer diagnostics.
 */
class DiagnosticEncounterStandaloneHarness extends Component
{
    use ManagesEncounterEPrescription;
    use ManagesEncounterReferrals;
    use ResolvesEncounterStandaloneContext;

    public int $encounterId;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

}

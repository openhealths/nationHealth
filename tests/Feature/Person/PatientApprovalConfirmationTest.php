<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Enums\Person\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\MedicalEvents\ApprovalVerificationException;
use App\Livewire\Person\Forms\PersonForm;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\Person\Records\PatientData;
use App\Livewire\Person\Records\PatientApproval;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\User;
use App\Policies\ApprovalPolicy;
use App\Policies\PersonPolicy;
use App\Repositories\MedicalEvents\ApprovalRepository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\PersonRepository;
use App\Repositories\Repository;
use App\Traits\HasApproval;
use BackedEnum;
use ReflectionMethod;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Mockery;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientApprovalConfirmationTest extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedTransaction;
    }

    private Person $person;
    private string $employeeUuid;
    private object $actions;
    private ApprovalApi $api;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        // Isolate the test before providers boot, even when development config is cached.
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set([
                'database.default' => 'approval_confirmation_test',
                'database.connections.approval_confirmation_test' => [
                    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                ],
                'session.driver' => 'array',
                'cache.default' => 'array',
                'queue.default' => 'sync',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->timestamps();
        });
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('employee_type');
            $table->string('status');
            $table->boolean('is_active');
        });
        Schema::create('employee_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('identifiers', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
            $table->timestamps();
        });
        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->unsignedBigInteger('granted_to_id');
            $table->string('status');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('approval_granted_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('approval_id');
            $table->unsignedBigInteger('granted_to_id');
            $table->timestamps();
        });
        Schema::create('ehealth_links', function (Blueprint $table): void {
            $table->id();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        $this->beginIsolatedTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->person = Person::create(['uuid' => (string) Str::uuid()]);
        $this->employeeUuid = (string) Str::uuid();
        $employee = $this->createEmployee(['uuid' => $this->employeeUuid]);
        $this->instance('legalEntity', (new LegalEntity())->forceFill(['id' => 1]));
        setPermissionsTeamId(1);
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['id' => 1]);
        $user->shouldReceive('cannot')->byDefault()->andReturnFalse();
        $user->shouldReceive('can')->with('createForEmployee', Mockery::type('array'))->byDefault()->andReturnTrue();
        $user->shouldReceive('activeDoctorEmployee')->andReturn($employee);
        Auth::shouldReceive('user')->andReturn($user);

        $this->api = Mockery::mock(ApprovalApi::class);
        $this->instance(ApprovalApi::class, $this->api);
        $this->actions = new class
        {
            use HasApproval;
        };
    }

    public static function verificationResponses(): array
    {
        return [
            'pending, HTTP 200' => ['pending', 200],
            'pending, HTTP 204' => ['pending', 204],
            'legacy active, HTTP 200' => ['active', 200],
        ];
    }

    #[DataProvider('offlineMethods')]
    public function test_document_confirmation_waits_for_explicit_acknowledgement(string $type): void
    {
        $component = Mockery::mock(PatientApproval::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->personId = $this->person->id;
        $component->step = 'confirming';
        $component->approvalId = (string) Str::uuid();
        $component->selectedAuthType = $type;
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        if ($type === 'THIRD_PERSON') {
            $service->shouldReceive('details')->once()->andReturn([
                'authentication_method_current' => ['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OFFLINE']],
            ]);
        }
        $service->shouldReceive('verify')->once()->with(
            Mockery::on(fn (Person $person): bool => $person->is($this->person)),
            $component->approvalId,
            null,
        )->andReturnUsing(function () use ($component): null {
            $this->assertTrue($component->leafletConfirmed);

            return null;
        });
        $component->shouldReceive('finish')->once();

        $component->continueAfterCreation();

        $this->assertSame('confirming', $component->step);
        $this->assertSame('OFFLINE', $component->selectedAuthType);
        $this->assertTrue($component->getErrorBag()->has('leafletConfirmed'));

        $component->leafletConfirmed = true;
        $component->continueAfterCreation();
    }

    public static function offlineMethods(): array
    {
        return ['patient' => ['OFFLINE'], 'representative' => ['THIRD_PERSON']];
    }

    public function test_representative_otp_still_opens_code_entry_without_document_acknowledgement(): void
    {
        $component = new PatientApproval();
        $component->personId = $this->person->id;
        $component->step = 'confirming';
        $component->approvalId = (string) Str::uuid();
        $component->selectedAuthType = 'THIRD_PERSON';
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('details')->once()->andReturn([
            'authentication_method_current' => ['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OTP', 'phone_number' => '+38093*****85']],
        ]);
        $service->shouldReceive('smsResendAvailableAt')->once()->andReturn(123);
        $service->shouldNotReceive('verify');

        $component->continueAfterCreation();

        $this->assertSame('code', $component->step);
        $this->assertSame('+38093*****85', $component->smsPhone);
        $this->assertFalse($component->getErrorBag()->has('leafletConfirmed'));
    }

    public function test_document_confirmation_denial_stops_before_api_calls(): void
    {
        $component = new PatientApproval();
        $component->step = 'confirming';
        $component->approvalId = (string) Str::uuid();
        $component->selectedAuthType = 'OFFLINE';
        $component->leafletConfirmed = true;
        Auth::user()->shouldReceive('cannot')->once()->with('create', Approval::class)->andReturnTrue();
        $service = $this->mock(PatientApprovalService::class);
        $service->shouldNotReceive('details');
        $service->shouldNotReceive('verify');

        $component->continueAfterCreation();

        $this->assertSame('confirming', $component->step);
        $this->assertSame(__('approval.create.role_forbidden'), Session::get('error'));
    }

    #[DataProvider('incompleteSnapshotPages')]
    public function test_incomplete_snapshot_preserves_existing_approval(bool $failsOnSecondPage): void
    {
        $approval = $this->createApproval(['status' => 'active', 'is_verified' => true]);
        $attributesBefore = $approval->fresh()->getAttributes();
        $filters = ['granted_resource_type' => 'person', 'granted_resources' => $this->person->uuid];
        $validator = fn (EHealthResponse $response): array => (new ReflectionMethod(ApprovalApi::class, 'validateApprovalList'))
            ->invoke($this->api, $response);
        if ($failsOnSecondPage) {
            $this->api->shouldReceive('getMany')->once()->with($filters)->andReturn(new EHealthResponse(
                new Response(200, [], '{"data":[],"paging":{"page_number":1,"total_pages":2}}'),
                $validator,
            ));
            $filters['page'] = 2;
        }
        $this->api->shouldReceive('getMany')->once()->with($filters)->andReturn(new EHealthResponse(
            new Response(200, [], '{}'),
            $validator,
        ));

        MedicalEventsRepository::approval()->syncApprovals($this->person, 'person');

        $this->assertSame($attributesBefore, $approval->fresh()->getAttributes());
    }

    public static function incompleteSnapshotPages(): array
    {
        return ['first page' => [false], 'later page' => [true]];
    }

    #[DataProvider('approvalPagingFields')]
    public function test_complete_empty_snapshot_reconciles_after_all_pages(string $pageField): void
    {
        Schema::table('ehealth_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('ehealth_job_id')->nullable();
        });
        Schema::create('ehealth_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });
        $approval = $this->createApproval(['status' => 'active', 'is_verified' => true]);
        $filters = ['granted_resource_type' => 'person', 'granted_resources' => $this->person->uuid];
        foreach ([1, 2] as $page) {
            $query = $page === 1 ? $filters : $filters + ['page' => $page];
            $response = new EHealthResponse(new Response(200, [], json_encode([
                'data' => [], 'paging' => [$pageField => $page, 'total_pages' => 2],
            ], JSON_THROW_ON_ERROR)), fn (EHealthResponse $response): array => (new ReflectionMethod(ApprovalApi::class, 'validateApprovalList'))
                ->invoke($this->api, $response));
            $this->api->shouldReceive('getMany')->once()->with($query)->andReturnUsing(function () use ($approval, $response): EHealthResponse {
                $this->assertSame('active', $approval->fresh()->status);

                return $response;
            });
        }

        MedicalEventsRepository::approval()->syncApprovals($this->person, 'person');

        $this->assertSame('inactive', $approval->fresh()->status);
    }

    public static function approvalPagingFields(): array
    {
        return ['runtime' => ['page_number'], 'documented' => ['page']];
    }

    public function test_representative_leaflet_can_override_a_care_plan_form_without_person_data(): void
    {
        $form = new CarePlanForm(new class extends Component
        {}, 'form');
        $html = view('livewire.person.parts.patient-leaflet', ['form' => $form, 'isThirdPerson' => true])->render();

        $this->assertStringContainsString(__('patients.leaflet.informed_representative'), $html);
        $this->assertStringContainsString(__('patients.leaflet.confirm_representative_authority'), $html);
        $this->assertStringNotContainsString(__('patients.leaflet.self_intro'), $html);

        $html = view('livewire.person.parts.patient-leaflet', ['form' => $form, 'isThirdPerson' => false])->render();
        $this->assertStringContainsString(__('patients.leaflet.self_intro'), $html);
        $this->assertStringNotContainsString(__('patients.leaflet.confirm_representative_authority'), $html);

        $patientForm = (object) ['person' => ['authenticationMethods' => [['type' => 'THIRD_PERSON']]]];
        $html = view('livewire.person.parts.patient-leaflet', ['form' => $patientForm])->render();
        $this->assertStringContainsString(__('patients.leaflet.informed_representative'), $html);
    }

    public function test_authentication_method_sync_preserves_stored_fields_and_rejects_metadata(): void
    {
        Schema::create('authentication_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->morphs('authenticatable');
            $table->string('type');
            foreach (['phone_number', 'value', 'alias', 'url'] as $field) {
                $table->string($field)->nullable();
            }
            $table->timestamp('ehealth_ended_at')->nullable();
            $table->timestamps();
        });
        $method = [
            'uuid' => (string) Str::uuid(), 'type' => 'OTP', 'phone_number' => '+38093*****85',
            'value' => null, 'alias' => 'Original', 'url' => 'https://example.invalid/auth',
            'ehealth_ended_at' => null,
            'reference' => ['type' => 'OTP', 'phone_number' => '+38093*****85'],
            'authenticatable_type' => 'untrusted', 'authenticatable_id' => 999999,
        ];
        $otherPerson = Person::create(['uuid' => (string) Str::uuid()]);
        $otherMethod = $otherPerson->authenticationMethods()->create(array_replace(Arr::only($method, [
            'uuid', 'type', 'phone_number', 'value', 'alias', 'url', 'ehealth_ended_at',
        ]), ['uuid' => (string) Str::uuid()]));
        $this->person->authenticationMethods()->create(['uuid' => (string) Str::uuid(), 'type' => 'OFFLINE']);
        $updated = array_replace($method, [
            'type' => 'THIRD_PERSON', 'value' => (string) Str::uuid(),
            'alias' => 'Updated', 'phone_number' => null, 'url' => null,
        ]);
        $previous = Model::preventsSilentlyDiscardingAttributes();
        Model::preventSilentlyDiscardingAttributes();
        try {
            Repository::authenticationMethod()->sync($this->person, [$method]);
            $stored = $this->person->authenticationMethods()->sole();
            $this->assertSame($method['phone_number'], $stored->phoneNumber);
            $this->assertSame($method['url'], $stored->url);
            Repository::authenticationMethod()->sync($this->person, [$updated]);
        } finally {
            Model::preventSilentlyDiscardingAttributes($previous);
        }

        $stored = $this->person->authenticationMethods()->sole();
        foreach (['uuid', 'type', 'phone_number', 'value', 'alias', 'url', 'ehealth_ended_at'] as $field) {
            $this->assertSame($updated[$field], $stored->getRawOriginal($field));
        }
        $this->assertSame($this->person->id, $stored->authenticatableId);
        $this->assertSame(Person::class, $stored->authenticatableType);
        $this->assertArrayNotHasKey('reference', $stored->getAttributes());
        $this->assertSame('Original', $otherMethod->fresh()->alias);
        $this->assertSame(AuthenticationMethod::OTP, AuthenticationMethod::confirmationMethod($method));
    }

    #[DataProvider('verificationResponses')]
    public function test_person_approval_can_be_verified(string $status, int $responseStatus): void
    {
        $approval = $this->createApproval(['status' => $status]);
        $result = MedicalEventsRepository::approval()->findApprovalForVerification($this->person);

        $this->assertTrue($result->is($approval));
        $this->assertFalse(Session::has('error'));

        $details = ['id' => $approval->uuid, 'status' => 'active', 'is_verified' => true];
        $this->api->shouldReceive('verify')->once()
            ->with($this->person->uuid, $approval->uuid, ['code' => 1234])
            ->andReturn(new EHealthResponse(new Response($responseStatus)));
        $this->api->shouldReceive('getApprovalDetails')->once()
            ->with($this->person->uuid, $approval->uuid)
            ->andReturn(new EHealthResponse(new Response(200, [], json_encode(['data' => $details]))));
        $this->mock(ApprovalRepository::class)->makePartial()->shouldReceive('sync')->once()->with(
            $details,
            $this->person,
            Mockery::on(fn (Approval $selected): bool => $selected->is($approval)),
        );

        $this->assertTrue($this->actions->verifyApproval($this->person, '1234'));
        $this->assertFalse(Session::has('error'));
    }

    public function test_pending_person_approval_sms_can_be_resent_for_the_current_employee(): void
    {
        $this->createApproval();
        $latest = $this->createApproval();
        $otherEmployee = Identifier::create(['value' => (string) Str::uuid()]);
        $this->createApproval(['granted_to_id' => $otherEmployee->id]);
        $this->api->shouldReceive('resendSms')->once()
            ->with($this->person->uuid, $latest->uuid)
            ->andReturn(new EHealthResponse(new Response(202)));

        $this->actions->resendApprovalSms($this->person);

        $this->assertFalse(Session::has('error'));
    }

    public function test_verification_lookup_prefers_a_pending_approval_over_a_newer_verified_approval(): void
    {
        $pending = $this->createApproval();
        $this->createApproval(['status' => 'active', 'is_verified' => true]);

        $result = MedicalEventsRepository::approval()->findApprovalForVerification($this->person);

        $this->assertTrue($result->is($pending));
    }

    public function test_verified_approval_passed_directly_cannot_have_sms_resent(): void
    {
        $approval = $this->createApproval(['status' => 'active', 'is_verified' => true]);
        $attributesBefore = $approval->fresh()->getAttributes();
        $this->api->shouldNotReceive('resendSms');

        $this->actions->resendApprovalSms($approval);

        $this->assertSame(
            __('patients.errors.approval_sms_error').': '.__('patients.errors.approval_already_verified'),
            Session::get('error'),
        );
        $this->assertSame($attributesBefore, $approval->fresh()->getAttributes());
    }

    public function test_verification_actions_stop_before_lookup_when_permission_is_denied(): void
    {
        $approval = $this->createApproval();
        $attributesBefore = $approval->fresh()->getAttributes();
        Auth::user()->shouldReceive('cannot')->twice()->with('create', Approval::class)->andReturnTrue();
        $this->mock(ApprovalRepository::class)->shouldNotReceive('findApprovalForVerification');
        $this->api->shouldNotReceive('verify');
        $this->api->shouldNotReceive('getApprovalDetails');
        $this->api->shouldNotReceive('resendSms');

        $this->assertFalse($this->actions->verifyApproval($this->person, 1234));
        $this->assertSame(__('patients.policy.approval_sms_code'), Session::get('error'));

        $this->actions->resendApprovalSms($this->person);

        $this->assertSame(__('patients.policy.approval_sms_code'), Session::get('error'));
        $this->assertSame($attributesBefore, $approval->fresh()->getAttributes());
    }

    public static function unavailableApprovals(): array
    {
        return [
            'missing' => [null, 'approval_not_found'],
            'creation unfinished' => [['uuid' => null], 'approval_creation_pending'],
            'expired' => [['expires_at' => '2000-01-01 00:00:00'], 'approval_expired'],
            'inactive' => [['status' => 'inactive'], 'approval_unavailable'],
            'unknown status' => [['status' => 'unknown'], 'approval_unavailable'],
            'failed creation' => [['uuid' => null, 'status' => 'FAILED'], 'approval_not_created'],
            'verified active' => [['status' => 'active', 'is_verified' => true], 'approval_already_verified'],
            'verified approved' => [['status' => 'APPROVED', 'is_verified' => true], 'approval_already_verified'],
        ];
    }

    #[DataProvider('unavailableApprovals')]
    public function test_unavailable_approval_reports_the_reason_without_calling_esoz(?array $attributes, string $error): void
    {
        if ($attributes !== null) {
            $this->createApproval($attributes);
        }
        $expectedError = __('patients.errors.'.$error, ['status' => $attributes['status'] ?? 'pending']);
        $approvalsBefore = Approval::all()->map->getAttributes()->all();

        try {
            MedicalEventsRepository::approval()->findApprovalForVerification($this->person);
            $this->fail('An unavailable approval must throw a verification exception.');
        } catch (ApprovalVerificationException $exception) {
            $this->assertSame($expectedError, $exception->getMessage());
        }
        $this->assertFalse(Session::has('error'));

        $this->api->shouldNotReceive('verify');
        $this->api->shouldNotReceive('getApprovalDetails');
        $this->api->shouldNotReceive('resendSms');
        $this->mock(ApprovalRepository::class)->makePartial()->shouldNotReceive('sync');

        $this->assertFalse($this->actions->verifyApproval($this->person, 1234));
        $this->assertSame(
            __('patients.errors.approval_verification_failed').': '.$expectedError,
            Session::get('error'),
        );

        $this->actions->resendApprovalSms($this->person);

        $this->assertSame(__('patients.errors.approval_sms_error').': '.$expectedError, Session::get('error'));
        $this->assertSame($approvalsBefore, Approval::all()->map->getAttributes()->all());
    }

    public function test_rejected_creation_reports_the_esoz_validation_details(): void
    {
        $approval = $this->createApproval(['uuid' => null, 'status' => 'FAILED']);
        $approval->ehealthLinks()->create([
            'status' => 'FAILED',
            'error' => ['type' => 'validation_failed', 'invalid' => [[
                'entry' => '$.granted_to.identifier.value',
                'rules' => [['description' => 'Invalid employee type']],
            ]]],
        ]);
        $this->api->shouldNotReceive('resendSms');

        $this->actions->resendApprovalSms($this->person);

        $this->assertStringContainsString(__('patients.errors.approval_not_created'), Session::get('error'));
        $this->assertStringContainsString(__('errors.ehealth.messages.approval_invalid_employee_type'), Session::get('error'));
        $this->assertStringNotContainsString('Invalid employee type', Session::get('error'));
        $this->assertStringNotContainsString('$.granted_to.identifier.value', Session::get('error'));
    }

    public function test_patient_component_preserves_the_verification_error_details(): void
    {
        $this->createApproval();
        $this->api->shouldReceive('verify')->once()->andThrow(new EHealthResponseException(
            new HttpResponse(new Response(400, [], json_encode(['error' => ['message' => 'Invalid verification code']]))),
        ));
        $component = new PatientData();
        $component->personId = $this->person->id;
        $component->form = Mockery::mock(PersonForm::class);
        $component->form->shouldReceive('rulesForApprove')->once()->andReturn([]);
        $component->form->shouldReceive('validate')->once()->with([])->andReturn(['verificationCode' => '1234']);
        Session::flash('error', 'Previous error');

        $component->approvePersonalDataUpdate();

        $this->assertStringContainsString(__('errors.ehealth.messages.verification_code_invalid'), Session::get('error'));
        $this->assertNotSame(__('patients.errors.approval_verification_failed'), Session::get('error'));
    }

    public function test_verified_approval_is_reused_until_its_exact_expiry(): void
    {
        $this->freezeSecond();
        $approval = $this->createApproval(['status' => 'active', 'is_verified' => true]);
        $expiresAt = $approval->expiresAt->copy();
        $component = $this->makeSyncComponent();
        $component->shouldReceive('syncPersonDataAfterGetApproval')->twice();
        $component->shouldReceive('createApproval')->once();
        $this->api->shouldNotReceive('createApproval');

        $component->syncPersonDataFromEHealth();
        $this->travelTo($expiresAt->copy()->subSecond());
        $component->syncPersonDataFromEHealth();

        $this->assertFalse($component->showConfirmationUpdateModal);
        $this->assertSame($expiresAt->getTimestamp(), $approval->fresh()->expiresAt->getTimestamp());

        $this->travelTo($expiresAt);
        $component->syncPersonDataFromEHealth();

        $this->assertTrue($component->showConfirmationUpdateModal);
        $this->assertTrue($component->isSyncing);
    }

    public static function approvalsRequiringNewRequest(): array
    {
        return [
            'missing' => [null, 'current'],
            'expired' => [['expires_at' => '2000-01-01 00:00:00'], 'current'],
            'inactive' => [['status' => 'inactive'], 'current'],
            'failed' => [['status' => 'FAILED'], 'current'],
            'missing UUID' => [['uuid' => null], 'current'],
            'unknown expiry' => [['expires_at' => null], 'current'],
            'another employee' => [[], 'another employee'],
            'another patient' => [[], 'another patient'],
            'failed creation without UUID' => [['uuid' => null, 'expires_at' => null, 'status' => 'FAILED', 'is_verified' => false], 'current'],
            'another employee awaiting creation' => [['uuid' => null, 'expires_at' => null, 'status' => 'pending', 'is_verified' => false], 'another employee'],
            'another patient awaiting creation' => [['uuid' => null, 'expires_at' => null, 'status' => 'pending', 'is_verified' => false], 'another patient'],
        ];
    }

    #[DataProvider('approvalsRequiringNewRequest')]
    public function test_new_approval_is_requested_only_when_no_suitable_approval_exists(?array $attributes, string $context): void
    {
        if ($attributes !== null) {
            if ($context === 'another employee') {
                $attributes['granted_to_id'] = Identifier::create(['value' => (string) Str::uuid()])->id;
            } elseif ($context === 'another patient') {
                $attributes['approvable_id'] = Person::create(['uuid' => (string) Str::uuid()])->id;
            }
            $this->createApproval($attributes + ['status' => 'active', 'is_verified' => true]);
        }
        $component = $this->makeSyncComponent();
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $component->shouldReceive('createApproval')->once()->with(
            Mockery::on(fn (Person $person): bool => $person->is($this->person)),
            Mockery::on(fn (array $payload): bool => $payload['granted_to']['value'] === $this->employeeUuid),
        );

        $component->syncPersonDataFromEHealth();

        $this->assertTrue($component->showConfirmationUpdateModal);
        $this->assertFalse(Session::has('error'));
    }

    public function test_expired_approval_cannot_use_the_lifetime_of_another_employees_approval(): void
    {
        $this->createApproval(['status' => 'active', 'is_verified' => true, 'expires_at' => now()->subSecond()]);
        $otherEmployee = Identifier::create(['value' => (string) Str::uuid()]);
        $this->createApproval(['granted_to_id' => $otherEmployee->id]);
        $component = $this->makeSyncComponent();
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $component->shouldReceive('createApproval')->once();

        $component->syncPersonDataFromEHealth();

        $this->assertTrue($component->showConfirmationUpdateModal);
    }

    public static function unfinishedApprovalStatuses(): array
    {
        return ['pending' => ['pending'], 'legacy new' => ['NEW']];
    }

    #[DataProvider('unfinishedApprovalStatuses')]
    public function test_repeated_sync_waits_for_approval_creation_without_side_effects(string $status): void
    {
        Bus::fake();
        $this->createApproval(['uuid' => null, 'expires_at' => null, 'status' => $status]);
        $approvalsBefore = Approval::all()->map->getAttributes()->all();
        $component = $this->makeSyncComponent();
        $component->showConfirmationUpdateModal = true;
        $component->shouldNotReceive('createApproval');
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $component->shouldNotReceive('resendApprovalSms');
        $this->mock(PersonRepository::class)->shouldNotReceive('updateSynchronizationStatusById');
        $this->api->shouldNotReceive('createApproval');
        $this->api->shouldNotReceive('resendSms');

        $component->syncPersonDataFromEHealth();
        $component->syncPersonDataFromEHealth();

        $this->assertSame(__('patients.errors.approval_creation_pending'), Session::get('info'));
        $this->assertFalse(Session::has('error'));
        $this->assertFalse($component->showConfirmationUpdateModal);
        $this->assertSame($approvalsBefore, Approval::all()->map->getAttributes()->all());
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
    }

    public function test_sync_can_retry_after_the_pending_approval_creation_fails(): void
    {
        $approval = $this->createApproval(['uuid' => null, 'expires_at' => null]);
        $component = $this->makeSyncComponent();
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $component->shouldReceive('createApproval')->once();

        $component->syncPersonDataFromEHealth();

        $this->assertSame(__('patients.errors.approval_creation_pending'), Session::get('info'));
        $this->assertFalse($component->showConfirmationUpdateModal);

        $approval->update(['status' => JobStatus::FAILED->value]);
        Session::forget('info');
        $component->syncPersonDataFromEHealth();

        $this->assertTrue($component->showConfirmationUpdateModal);
        $this->assertTrue($component->isSyncing);
        $this->assertFalse(Session::has('error'));
        $this->assertFalse(Session::has('info'));
    }

    public function test_sync_permission_denial_stops_before_checking_pending_creation(): void
    {
        Bus::fake();
        $this->createApproval(['uuid' => null, 'expires_at' => null]);
        $approvalsBefore = Approval::all()->map->getAttributes()->all();
        Auth::user()->shouldReceive('cannot')->once()->with('syncPersonData', Person::class)->andReturnTrue();
        $this->mock(ApprovalRepository::class)->shouldNotReceive('hasPendingCreation');
        $this->mock(PersonRepository::class)->shouldNotReceive('updateSynchronizationStatusById');
        $component = Mockery::mock(PatientData::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->personId = $this->person->id;
        $component->shouldNotReceive('createApproval');
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $this->api->shouldNotReceive('createApproval');

        $component->syncPersonDataFromEHealth();

        $this->assertSame(__('patients.policy.personal_data_update'), Session::get('error'));
        $this->assertFalse(Session::has('info'));
        $this->assertSame($approvalsBefore, Approval::all()->map->getAttributes()->all());
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
    }

    #[DataProvider('pendingApprovalAges')]
    public function test_pending_approval_resumes_confirmation_without_creating_another_request(int $minutes): void
    {
        $this->freezeSecond();
        $approval = $this->createApproval();
        $expiresAt = $approval->expiresAt->getTimestamp();
        $this->travel($minutes)->minutes();
        $component = $this->makeSyncComponent();
        $component->shouldNotReceive('createApproval');
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        if ($minutes === 0) {
            $this->api->shouldNotReceive('resendSms');
        } else {
            $this->api->shouldReceive('resendSms')->once()->with($this->person->uuid, $approval->uuid)
                ->andReturn(new EHealthResponse(new Response(202)));
        }

        $component->syncPersonDataFromEHealth();

        $this->assertTrue($component->showConfirmationUpdateModal);
        $this->assertSame(1, Approval::count());
        $this->assertSame($expiresAt, $approval->fresh()->expiresAt->getTimestamp());
        $this->assertFalse(Session::has('error'));
    }

    public static function pendingApprovalAges(): array
    {
        return ['SMS code still valid' => [0], 'SMS resend needed' => [15]];
    }

    #[DataProvider('newerUnavailableApprovals')]
    public function test_resumed_approval_can_be_resent_and_verified_despite_a_newer_unavailable_approval(array $attributes): void
    {
        $approval = $this->createApproval();
        $this->createApproval($attributes);
        $component = $this->makeSyncComponent();
        $component->shouldNotReceive('createApproval');
        $component->shouldReceive('syncPersonDataAfterGetApproval')->once();
        $component->form = Mockery::mock(PersonForm::class);
        $component->form->shouldReceive('rulesForApprove')->once()->andReturn([]);
        $component->form->shouldReceive('validate')->once()->with([])->andReturn(['verificationCode' => '1234']);
        $details = ['id' => $approval->uuid, 'status' => 'active', 'is_verified' => true];
        $this->api->shouldReceive('resendSms')->once()
            ->with($this->person->uuid, $approval->uuid)
            ->andReturn(new EHealthResponse(new Response(202)));
        $this->api->shouldReceive('verify')->once()
            ->with($this->person->uuid, $approval->uuid, ['code' => 1234])
            ->andReturn(new EHealthResponse(new Response(204)));
        $this->api->shouldReceive('getApprovalDetails')->once()
            ->with($this->person->uuid, $approval->uuid)
            ->andReturn(new EHealthResponse(new Response(200, [], json_encode(['data' => $details]))));
        $this->mock(ApprovalRepository::class)->makePartial()->shouldReceive('sync')->once()->with(
            $details,
            Mockery::on(fn (Person $person): bool => $person->is($this->person)),
            Mockery::on(fn (Approval $selected): bool => $selected->is($approval)),
        );

        $component->syncPersonDataFromEHealth();

        $this->assertTrue($component->showConfirmationUpdateModal);
        $component->resendApprovalSmsCode();

        $this->assertFalse(Session::has('error'));
        $component->approvePersonalDataUpdate();

        $this->assertFalse($component->showConfirmationUpdateModal);
        $this->assertFalse(Session::has('error'));
    }

    public static function newerUnavailableApprovals(): array
    {
        return [
            'expired' => [['expires_at' => '2000-01-01 00:00:00']],
            'inactive' => [['status' => 'inactive']],
            'failed' => [['status' => 'FAILED', 'uuid' => null]],
            'creation unfinished' => [['uuid' => null]],
            'unknown expiry' => [['expires_at' => null]],
        ];
    }

    public function test_missing_approval_employee_stops_personal_data_sync_without_side_effects(): void
    {
        DB::table('employee_users')->delete();
        $this->createEmployee(['employee_type' => 'OWNER']);
        $this->configureApprovalRoles(['OWNER' => false, 'DOCTOR' => true]);
        $this->useSyncPolicy();
        Bus::fake();
        $approvalsBefore = Approval::count();
        $component = Mockery::mock(PatientData::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->personId = $this->person->id;
        $component->shouldNotReceive('createApproval');
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $this->mock(PersonRepository::class)->shouldNotReceive('updateSynchronizationStatusById');
        $this->api->shouldNotReceive('createApproval');

        $this->assertTrue((new ApprovalPolicy())->create(Auth::user())->allowed());
        $this->assertTrue((new PersonPolicy())->syncPersonData(Auth::user())->allowed());
        $component->syncPersonDataFromEHealth();

        $this->assertSame(__('patients.errors.approval_employee_unavailable'), Session::get('error'));
        $this->assertFalse($component->showConfirmationUpdateModal);
        $this->assertFalse($component->isSyncing);
        $this->assertSame($approvalsBefore, Approval::count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
    }

    public static function approvalEmployeeTypes(): array
    {
        return [
            'doctor' => ['DOCTOR'],
            'specialist' => ['SPECIALIST'],
            'assistant' => ['ASSISTANT'],
            'coordinator' => ['MED_COORDINATOR'],
        ];
    }

    public static function usersWithoutApprovalPermission(): array
    {
        return [
            'no role granting approval creation' => [['OWNER' => false], ['OWNER'], false],
            'available role lacks approval permission' => [['DOCTOR' => false], ['DOCTOR'], false],
            'approval role is unavailable in this login' => [['DOCTOR' => true], [], false],
            'approval scope is missing' => [['DOCTOR' => true], ['DOCTOR'], true],
        ];
    }

    #[DataProvider('usersWithoutApprovalPermission')]
    public function test_sync_denies_new_approval_creation_before_selecting_a_creator(array $roles, array $availableRoles, bool $missingScope): void
    {
        $this->configureApprovalRoles($roles, $availableRoles);
        $this->useSyncPolicy();
        if ($missingScope) {
            Auth::user()->shouldReceive('cannot')->with('approval:create')->andReturnTrue();
        }
        $this->assertTrue((new ApprovalPolicy())->create(Auth::user())->denied());
        $canSync = (new PersonPolicy())->syncPersonData(Auth::user())->allowed();
        Auth::user()->shouldNotReceive('can')->with('createForEmployee', Mockery::type('array'));
        Bus::fake();
        $approvalsBefore = Approval::count();
        $component = Mockery::mock(PatientData::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->personId = $this->person->id;
        $component->shouldNotReceive('createApproval');
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $this->mock(PersonRepository::class)->shouldNotReceive('updateSynchronizationStatusById');
        $this->api->shouldNotReceive('createApproval');
        $component->syncPersonDataFromEHealth();

        $this->assertSame(__($canSync ? 'patients.policy.approval' : 'patients.policy.personal_data_update'), Session::get('error'));
        $this->assertNotSame(__('patients.errors.approval_employee_unavailable'), Session::get('error'));
        $this->assertFalse($component->showConfirmationUpdateModal);
        $this->assertFalse($component->isSyncing);
        $this->assertSame($approvalsBefore, Approval::count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
    }

    public function test_verified_approval_allows_personal_data_sync_without_permission_to_create_approvals(): void
    {
        $this->configureApprovalRoles(['DOCTOR' => false]);
        $this->useSyncPolicy();
        $this->createApproval(['status' => 'active', 'is_verified' => true]);
        $this->assertTrue((new ApprovalPolicy())->create(Auth::user())->denied());
        $this->assertTrue((new PersonPolicy())->syncPersonData(Auth::user())->allowed());
        Auth::user()->shouldNotReceive('can')->with('createForEmployee', Mockery::type('array'));
        $component = $this->makeSyncComponent();
        $component->shouldReceive('syncPersonDataAfterGetApproval')->once();
        $component->shouldNotReceive('createApproval');
        $this->api->shouldNotReceive('createApproval');

        $component->syncPersonDataFromEHealth();

        $this->assertFalse(Session::has('error'));
        $this->assertFalse($component->showConfirmationUpdateModal);
    }

    public function test_another_users_verified_approval_cannot_bypass_approval_creation_permission(): void
    {
        $this->configureApprovalRoles(['DOCTOR' => false]);
        $this->useSyncPolicy();
        $otherEmployee = $this->createEmployee([], 2);
        $identifier = Identifier::create(['value' => $otherEmployee->uuid]);
        $this->createApproval(['status' => 'active', 'is_verified' => true, 'granted_to_id' => $identifier->id]);
        $component = $this->makeSyncComponent();
        $component->shouldNotReceive('syncPersonDataAfterGetApproval');
        $component->shouldNotReceive('createApproval');

        $component->syncPersonDataFromEHealth();

        $this->assertSame(__('patients.policy.approval'), Session::get('error'));
        $this->assertFalse($component->showConfirmationUpdateModal);
    }

    public function test_owner_creates_approval_with_the_synchronized_employees_uuid(): void
    {
        $this->createEmployee(['employee_type' => 'OWNER']);
        $this->configureApprovalRoles(['OWNER' => false, 'DOCTOR' => true]);
        $this->useSyncPolicy();
        $component = $this->makeSyncComponent();
        $component->shouldReceive('createApproval')->once()->with(
            Mockery::on(fn (Person $person): bool => $person->is($this->person)),
            Mockery::on(fn (array $payload): bool => $payload['granted_to']['value'] === $this->employeeUuid
                && $payload['created_by']['value'] === $this->employeeUuid),
        );

        $component->syncPersonDataFromEHealth();

        $this->assertTrue($component->showConfirmationUpdateModal);
        $this->assertFalse(Session::has('error'));
    }

    #[DataProvider('approvalEmployeeTypes')]
    public function test_selection_uses_a_linked_employees_own_available_role_with_approval_scope(string $type): void
    {
        DB::table('employee_users')->delete();
        $this->createEmployee(['employee_type' => 'OWNER']);
        $employee = $this->createEmployee(['employee_type' => $type]);
        $this->configureApprovalRoles(['OWNER' => false, $type => true]);

        $selected = Repository::employee()->forApproval(Auth::user(), legalEntity());

        $this->assertTrue($selected->is($employee));
    }

    public function test_single_role_login_does_not_select_an_employee_from_an_unavailable_role(): void
    {
        $assistant = $this->createEmployee(['employee_type' => 'ASSISTANT']);
        $this->configureApprovalRoles(['DOCTOR' => true, 'ASSISTANT' => true], ['ASSISTANT']);

        $selected = Repository::employee()->forApproval(Auth::user(), legalEntity());

        $this->assertTrue($selected->is($assistant));
    }

    public static function unavailableApprovalEmployees(): array
    {
        return [
            'inactive' => [['is_active' => false], 1],
            'stopped' => [['status' => 'STOPPED'], 1],
            'another entity' => [['legal_entity_id' => 2], 1],
            'another user' => [[], 2],
            'no UUID' => [['uuid' => null], 1],
        ];
    }

    #[DataProvider('unavailableApprovalEmployees')]
    public function test_selection_refuses_an_unavailable_employee(array $attributes, int $userId): void
    {
        DB::table('employee_users')->delete();
        $this->createEmployee($attributes, $userId);
        $this->configureApprovalRoles(['DOCTOR' => true]);

        $this->expectException(ApprovalVerificationException::class);
        $this->expectExceptionMessage(__('patients.errors.approval_employee_unavailable'));

        Repository::employee()->forApproval(Auth::user(), legalEntity());
    }

    public function test_creation_and_confirmation_use_the_same_eligible_employee(): void
    {
        $assistant = $this->createEmployee(['employee_type' => 'ASSISTANT']);
        $this->configureApprovalRoles(['DOCTOR' => true, 'ASSISTANT' => true], ['ASSISTANT']);
        $this->employeeUuid = $assistant->uuid;
        $approval = $this->createApproval();
        $component = $this->makeSyncComponent();
        $component->shouldNotReceive('createApproval');

        $component->syncPersonDataFromEHealth();
        $selected = MedicalEventsRepository::approval()->findApprovalForVerification($this->person);

        $this->assertTrue($component->showConfirmationUpdateModal);
        $this->assertTrue($selected->is($approval));
    }

    private function configureApprovalRoles(array $roles, ?array $availableRoles = null): void
    {
        config(['permission.teams' => false]);
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('legal_entity_id')->nullable();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
            $table->unsignedBigInteger('legal_entity_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'approval:create', 'guard_name' => 'web']);
        foreach ($roles as $name => $canCreate) {
            $roleId = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('model_has_roles')->insert([
                'role_id' => $roleId, 'model_id' => 1, 'model_type' => Auth::user()->getMorphClass(), 'legal_entity_id' => 1,
            ]);
            if ($canCreate) {
                DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $availableRoles ??= array_keys($roles);
        $user = Auth::user();
        $user->shouldReceive('getAllowedRolesAttribute')->andReturn(collect($availableRoles));
        $user->shouldReceive('hasAllowedRole')->andReturnUsing(static fn ($roles): bool => collect(is_array($roles) ? $roles : [$roles])
            ->contains(static fn ($role): bool => in_array($role instanceof BackedEnum ? $role->value : $role, $availableRoles, true)));
        $user->shouldReceive('can')->with('create', Approval::class)
            ->andReturnUsing(fn (): bool => (new ApprovalPolicy())->create($user)->allowed());
        $user->shouldReceive('cannot')->with('create', Approval::class)
            ->andReturnUsing(fn (): bool => (new ApprovalPolicy())->create($user)->denied());
        $user->shouldReceive('can')->with('createForEmployee', Mockery::type('array'))
            ->andReturnUsing(fn (string $ability, array $arguments): bool => (new ApprovalPolicy())->createForEmployee($user, $arguments[1])->allowed());
        Auth::shouldReceive('getDefaultDriver')->andReturn('web');
    }

    private function useSyncPolicy(): void
    {
        $user = Auth::user();
        $user->shouldReceive('can')->with('personal_data:read')->andReturnTrue();
        $user->shouldReceive('can')->with('confidant_person_relationship:read')->andReturnTrue();
        $user->shouldReceive('cannot')->with('syncPersonData', Person::class)
            ->andReturnUsing(fn (): bool => (new PersonPolicy())->syncPersonData($user)->denied());
    }

    private function createEmployee(array $attributes = [], int $userId = 1): Employee
    {
        $id = DB::table('employees')->insertGetId($attributes + [
            'uuid' => (string) Str::uuid(), 'employee_type' => 'DOCTOR', 'status' => 'APPROVED',
            'is_active' => true, 'legal_entity_id' => 1,
        ]);
        DB::table('employee_users')->insert(['employee_id' => $id, 'user_id' => $userId]);

        return Employee::findOrFail($id);
    }

    private function makeSyncComponent(): PatientData
    {
        $component = Mockery::mock(PatientData::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->personId = $this->person->id;
        $component->uuid = $this->person->uuid;
        $component->authenticationMethods = [['type' => 'OTP', 'uuid' => (string) Str::uuid()]];
        $this->mock(PersonRepository::class)->shouldReceive('updateSynchronizationStatusById')
            ->with($this->person->id, true);

        return $component;
    }

    private function createApproval(array $attributes = []): Approval
    {
        $identifier = Identifier::firstOrCreate(['value' => $this->employeeUuid]);

        return Approval::create($attributes + [
            'uuid' => (string) Str::uuid(),
            'approvable_type' => Person::class,
            'approvable_id' => $this->person->id,
            'granted_to_id' => $identifier->id,
            'status' => 'pending',
            'is_verified' => false,
            'expires_at' => now()->addHour(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Livewire\Person\Forms\PersonForm;
use App\Livewire\Person\Records\PatientData;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\ApprovalRepository;
use App\Repositories\PersonRepository;
use App\Traits\HasApproval;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Mockery;
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
        $employee = new Employee(['uuid' => $this->employeeUuid]);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->andReturnFalse();
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

    #[DataProvider('verificationResponses')]
    public function test_person_approval_can_be_verified(string $status, int $responseStatus): void
    {
        $approval = $this->createApproval(['status' => $status]);
        $result = app(ApprovalRepository::class)->findApprovalForConfirmation($this->person);

        $this->assertTrue($result['approval']->is($approval));
        $this->assertNull($result['error']);
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

    public static function unavailableApprovals(): array
    {
        return [
            'missing' => [null, 'approval_not_found'],
            'creation unfinished' => [['uuid' => null], 'approval_creation_pending'],
            'expired' => [['expires_at' => '2000-01-01 00:00:00'], 'approval_expired'],
            'inactive' => [['status' => 'inactive'], 'approval_unavailable'],
        ];
    }

    #[DataProvider('unavailableApprovals')]
    public function test_unavailable_approval_reports_the_reason_without_calling_esoz(?array $attributes, string $error): void
    {
        if ($attributes !== null) {
            $this->createApproval($attributes);
        }
        $result = app(ApprovalRepository::class)->findApprovalForConfirmation($this->person);
        $expectedError = __('patients.errors.'.$error, ['status' => $attributes['status'] ?? 'pending']);

        $this->assertNull($result['approval']);
        $this->assertSame($expectedError, $result['error']);
        $this->assertFalse(Session::has('error'));

        $this->api->shouldNotReceive('verify');

        $this->assertFalse($this->actions->verifyApproval($this->person, 1234));
        $this->assertSame(
            __('patients.errors.approval_verification_failed').': '.$expectedError,
            Session::get('error'),
        );
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

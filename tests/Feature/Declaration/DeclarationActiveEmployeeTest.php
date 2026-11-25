<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Classes\eHealth\Api\Declaration as DeclarationApi;
use App\Classes\eHealth\Api\DeclarationRequest as DeclarationRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Enums\User\Role;
use App\Jobs\DeclarationRequestsSync;
use App\Jobs\DeclarationsSync;
use App\Livewire\Declaration\DeclarationIndex;
use App\Livewire\EncounterPackage\Forms\ObservationForm;
use App\Models\Declaration;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\ServiceRequestRequestPolicy;
use App\Repositories\DeclarationRepository;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class DeclarationActiveEmployeeTest extends TestCase
{
    protected ConnectionResolverInterface $originalResolver;
    protected SQLiteConnection $connection;
    protected LegalEntity $legalEntity;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Http::preventStrayRequests();
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'test-token']);

        $base = ['party_id' => 101, 'legal_entity_id' => 1, 'employee_type' => 'DOCTOR', 'status' => 'APPROVED', 'is_active' => true];
        $employees = [
            ['id' => 11, 'uuid' => 'inactive-doctor', 'is_active' => false] + $base,
            ['id' => 12, 'uuid' => 'dismissed-doctor', 'status' => 'DISMISSED'] + $base,
            ['id' => 13, 'uuid' => 'active-admin', 'employee_type' => 'ADMIN'] + $base,
            ['id' => 14, 'uuid' => 'another-party', 'party_id' => 102] + $base,
            ['id' => 15, 'uuid' => 'another-entity', 'legal_entity_id' => 2] + $base,
            ['id' => 16, 'uuid' => 'active-doctor'] + $base,
            ['id' => 17, 'uuid' => 'another-reorganized-party', 'party_id' => 102, 'status' => 'REORGANIZED'] + $base,
        ];

        // Execute the real employee queries without using the application's database.
        $this->originalResolver = Model::getConnectionResolver();
        $this->connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('employees', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->integer('party_id');
            $table->integer('legal_entity_id');
            $table->string('employee_type');
            $table->string('status');
            $table->boolean('is_active');
        });
        $schema->create('declarations', static function (Blueprint $table): void {
            $table->id();
            $table->integer('employee_id');
            $table->integer('legal_entity_id');
            $table->string('status');
        });
        $this->connection->table('employees')->insert($employees);
        $resolver = new ConnectionResolver(['pgsql' => $this->connection]);
        $resolver->setDefaultConnection('pgsql');
        Model::setConnectionResolver($resolver);

        $this->legalEntity = Mockery::mock(LegalEntity::class)->makePartial();
        $this->legalEntity->setRawAttributes(['id' => 1, 'uuid' => 'entity-one', 'status' => 'ACTIVE']);
        $this->legalEntity->shouldReceive('getForeignKey')->andReturn('legal_entity_id');
        $this->legalEntity->shouldReceive('setEntityStatus')->andReturnNull();
        $this->instance('legalEntity', $this->legalEntity);

        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->setRawAttributes(['id' => 1, 'party_id' => 101]);
        $this->user->setRelation('party', (new Party())->forceFill(['id' => 101]));
        $this->user->shouldReceive('hasAllowedRole')->andReturnUsing(fn ($roles): bool => $roles === Role::DOCTOR);
        $this->user->shouldReceive('cannot')->with('sync', Declaration::class)->andReturnFalse();
        $this->user->shouldReceive('notify')->andReturnNull();
        Auth::shouldReceive('user')->andReturn($this->user);
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver($this->originalResolver);

        parent::tearDown();
    }

    protected function index(): DeclarationIndex
    {
        $index = Mockery::mock(DeclarationIndex::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $index->shouldReceive('getDictionary')->andReturnNull();
        $index->shouldReceive('getDoctors')->andReturn(collect());
        $index->shouldReceive('getSyncStatus')->andReturn(JobStatus::COMPLETED->value);
        $index->shouldReceive('isSyncProcessing')->andReturnFalse();

        return $index;
    }

    protected function configureEmployee(string $legalEntityStatus, string $employeeStatus, bool $isActive): void
    {
        $this->legalEntity->status = $legalEntityStatus;
        $this->connection->table('employees')->where('id', 16)->update(['status' => $employeeStatus, 'is_active' => $isActive]);
    }

    #[DataProvider('employeeCases')]
    public function test_initial_filters_only_include_eligible_employees_of_the_current_party_and_entity(
        string $legalEntityStatus,
        string $employeeStatus,
        bool $isActive,
        bool $eligible
    ): void {
        $this->configureEmployee($legalEntityStatus, $employeeStatus, $isActive);
        $index = $this->index();
        $index->mount($this->legalEntity);

        $this->assertSame($eligible ? [13, 16] : [13], $index->employeeIds);
        $this->assertSame($eligible ? ['active-doctor'] : [], $index->ownEmployeeUuids);
        $this->assertSame($eligible ? ['active-doctor'] : [], $index->doctorFilter);
    }

    public static function employeeCases(): array
    {
        return [
            'approved doctor in active entity' => ['ACTIVE', 'APPROVED', true, true],
            'approved doctor in reorganized entity' => ['REORGANIZED', 'APPROVED', true, true],
            'reorganized doctor in reorganized entity' => ['REORGANIZED', 'REORGANIZED', true, true],
            'reorganized doctor in active entity' => ['ACTIVE', 'REORGANIZED', true, false],
            'inactive approved doctor' => ['ACTIVE', 'APPROVED', false, false],
            'inactive reorganized doctor' => ['REORGANIZED', 'REORGANIZED', false, false],
            'dismissed doctor in active entity' => ['ACTIVE', 'DISMISSED', true, false],
            'dismissed doctor in reorganized entity' => ['REORGANIZED', 'DISMISSED', true, false],
        ];
    }

    public static function syncCases(): array
    {
        $cases = [];

        foreach (['index', 'declarations', 'requests'] as $caller) {
            foreach (self::employeeCases() as $name => $case) {
                $cases[$caller . ' / ' . $name] = [$caller, ...$case];
            }
        }

        return $cases;
    }

    #[DataProvider('syncCases')]
    public function test_sync_uses_only_an_eligible_doctor(
        string $caller,
        string $legalEntityStatus,
        string $employeeStatus,
        bool $isActive,
        bool $eligible
    ): void {
        $this->configureEmployee($legalEntityStatus, $employeeStatus, $isActive);
        $apiClass = $caller === 'requests' ? DeclarationRequestApi::class : DeclarationApi::class;
        $api = Mockery::mock($apiClass);
        $this->instance($apiClass, $api);

        if (!$eligible) {
            $api->shouldNotReceive('getMany');
            $this->expectException(ModelNotFoundException::class);
        } else {
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('validate')->andReturn([]);
            $response->shouldReceive('isNotLast')->andReturnFalse();
            $expected = ['legal_entity_id' => 'entity-one', 'employee_id' => 'active-doctor'];
            if ($caller !== 'index') {
                $expected['page'] = 2;
                $api->shouldReceive('withToken')->once()->with('test-token')->andReturnSelf();
            }
            if ($caller === 'requests') {
                $expected['status'] = 'APPROVED';
                $api->shouldReceive('getMany')->once()->with($expected)->andReturn($response);
            } else {
                $api->shouldReceive('getMany')->once()->withArgs(function (array $query, bool $groupByEntities) use ($expected): bool {
                    $this->assertEquals($expected, $query);

                    return $groupByEntities;
                })->andReturn($response);
            }

            $repository = Mockery::mock(DeclarationRepository::class);
            $repository->shouldReceive('storeMany')->with([])->andReturnNull();
            $this->instance(DeclarationRepository::class, $repository);
        }

        if ($caller !== 'index') {
            $jobClass = $caller === 'requests' ? DeclarationRequestsSync::class : DeclarationsSync::class;
            $sync = new $jobClass(legalEntity: $this->legalEntity, page: 2);
            $sync->user = $this->user;
            (new ReflectionMethod($sync, 'sendRequest'))->invoke($sync, 'test-token');
        } else {
            $this->index()->sync();
        }
    }

    #[DataProvider('employeeCases')]
    public function test_active_scope_uses_explicit_legal_entity_without_web_context(
        string $legalEntityStatus,
        string $employeeStatus,
        bool $isActive,
        bool $eligible
    ): void {
        $this->configureEmployee($legalEntityStatus, $employeeStatus, $isActive);
        $this->app->forgetInstance('legalEntity');
        $this->assertNull(legalEntity());
        $approved = $employeeStatus === 'APPROVED' && $isActive;

        $this->assertSame($approved, Employee::whereKey(16)->active()->exists());
        $this->assertSame($approved, Employee::whereKey(16)->active(null)->exists());
        $this->assertSame(
            $eligible,
            Employee::whereKey(16)->active($this->legalEntity)->exists()
        );
        $this->assertSame(
            $approved ? [16] : [],
            Employee::forUserInLegalEntity($this->user, $this->legalEntity)->doctor()->pluck('id')->all()
        );
    }

    #[DataProvider('employeeCases')]
    public function test_observation_performer_still_requires_an_approved_active_employee(
        string $legalEntityStatus,
        string $employeeStatus,
        bool $isActive,
        bool $eligible
    ): void {
        $this->configureEmployee($legalEntityStatus, $employeeStatus, $isActive);
        config(['ehealth.encounter.package_allowed_observation_performer_employee_types' => ['DOCTOR']]);
        $component = new class extends Component
        {
            public object $form;
        };
        $component->form = (object) ['encounter' => ['participant' => [['uuid' => 'active-doctor']]]];
        $form = new ObservationForm($component, 'observations');
        $errors = [];

        (new ReflectionMethod($form, 'validatePerformer'))->invoke(
            $form,
            'active-doctor',
            static function (string $message) use (&$errors): void {
                $errors[] = $message;
            }
        );

        $this->assertSame(
            $employeeStatus === 'APPROVED' && $isActive
                ? []
                : [__('observations.validation.performer_employee_not_found')],
            $errors
        );
    }

    #[DataProvider('employeeCases')]
    public function test_service_request_update_still_requires_an_approved_active_employee(
        string $legalEntityStatus,
        string $employeeStatus,
        bool $isActive,
        bool $eligible
    ): void {
        $this->configureEmployee($legalEntityStatus, $employeeStatus, $isActive);
        $person = new Person();
        $request = Mockery::mock(ServiceRequestRequest::class)->makePartial();
        $request->setRawAttributes(['employee_id' => 16]);
        $request->shouldReceive('isEditableRequest')->andReturnTrue();
        $policy = Mockery::mock(ServiceRequestRequestPolicy::class)->makePartial();
        $policy->shouldReceive('updateAny')->once()->with($this->user, $this->legalEntity)->andReturn(Response::allow());
        $policy->shouldReceive('view')->once()->with($this->user, $request, $person, $this->legalEntity)->andReturn(Response::allow());

        $response = $policy->update($this->user, $request, $person, $this->legalEntity);

        $approved = $employeeStatus === 'APPROVED' && $isActive;
        $this->assertSame($approved, $response->allowed());
        $this->assertSame($approved ? null : 404, $response->status());
    }
}

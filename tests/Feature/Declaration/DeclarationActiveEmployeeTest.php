<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Classes\eHealth\Api\Declaration as DeclarationApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Enums\User\Role;
use App\Jobs\DeclarationsSync;
use App\Livewire\Declaration\DeclarationIndex;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\DeclarationRepository;
use Closure;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class DeclarationActiveEmployeeTest extends TestCase
{
    private ConnectionResolverInterface $originalResolver;
    private LegalEntity $legalEntity;
    private User $user;
    private array $employees;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        $base = ['party_id' => 101, 'legal_entity_id' => 1, 'employee_type' => 'DOCTOR', 'status' => 'APPROVED', 'is_active' => true];
        $this->employees = [
            ['id' => 11, 'uuid' => 'inactive-doctor', 'is_active' => false] + $base,
            ['id' => 12, 'uuid' => 'dismissed-doctor', 'status' => 'DISMISSED'] + $base,
            ['id' => 13, 'uuid' => 'active-admin', 'employee_type' => 'ADMIN'] + $base,
            ['id' => 14, 'uuid' => 'another-party', 'party_id' => 102] + $base,
            ['id' => 15, 'uuid' => 'another-entity', 'legal_entity_id' => 2] + $base,
            ['id' => 16, 'uuid' => 'active-doctor'] + $base,
        ];

        // Evaluate real Eloquent query constraints against fixtures without opening a database connection.
        $this->originalResolver = Model::getConnectionResolver();
        $connection = new class (
            fn () => throw new RuntimeException('Employee fixtures must not connect to a database.'),
            'testing',
        ) extends PostgresConnection
        {
            public Closure $selectRows;

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                return ($this->selectRows)($query, $bindings);
            }
        };
        $connection->selectRows = function (string $query, array $bindings): array {
            if (str_contains($query, 'from "declarations"')) {
                return [(object) ['aggregate' => 0]];
            }
            if (!str_contains($query, 'from "employees"')) {
                throw new RuntimeException('Unexpected fixture query: ' . $query);
            }

            preg_match_all('/(?:"[a-z_]+"\.)?"([a-z_]+)" = \?/', $query, $conditions);
            $this->assertCount(count($bindings), $conditions[1]);

            return array_values(array_map(static fn (array $row): object => (object) $row, array_filter(
                $this->employees,
                static function (array $row) use ($conditions, $bindings): bool {
                    foreach ($conditions[1] as $index => $column) {
                        if ((string) $row[$column] !== (string) $bindings[$index]) {
                            return false;
                        }
                    }

                    return true;
                }
            )));
        };
        $resolver = new ConnectionResolver(['pgsql' => $connection]);
        $resolver->setDefaultConnection('pgsql');
        Model::setConnectionResolver($resolver);

        $this->legalEntity = Mockery::mock(LegalEntity::class)->makePartial();
        $this->legalEntity->setRawAttributes(['id' => 1, 'uuid' => 'entity-one', 'status' => 'ACTIVE']);
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

    private function index(): DeclarationIndex
    {
        $index = Mockery::mock(DeclarationIndex::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $index->shouldReceive('getDictionary')->andReturnNull();
        $index->shouldReceive('getDoctors')->andReturn(collect());
        $index->shouldReceive('getSyncStatus')->andReturn(JobStatus::COMPLETED->value);
        $index->shouldReceive('isSyncProcessing')->andReturnFalse();

        return $index;
    }

    public function test_initial_filters_only_include_active_employees_of_the_current_party_and_entity(): void
    {
        $index = $this->index();
        $index->mount($this->legalEntity);

        $this->assertSame([13, 16], $index->employeeIds);
        $this->assertSame(['active-doctor'], $index->ownEmployeeUuids);
        $this->assertSame(['active-doctor'], $index->doctorFilter);
    }

    public static function syncCases(): array
    {
        return [
            'job with active doctor' => [true, true],
            'index with active doctor' => [false, true],
            'job without active doctor' => [true, false],
            'index without active doctor' => [false, false],
        ];
    }

    #[DataProvider('syncCases')]
    public function test_sync_uses_only_an_active_doctor(bool $job, bool $hasActiveDoctor): void
    {
        $api = Mockery::mock(DeclarationApi::class);
        $this->instance(DeclarationApi::class, $api);

        if (!$hasActiveDoctor) {
            $this->employees[5]['is_active'] = false;
            $api->shouldNotReceive('getMany');
            $this->expectException(ModelNotFoundException::class);
        } else {
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('validate')->andReturn([]);
            $response->shouldReceive('isNotLast')->andReturnFalse();
            $expected = ['legal_entity_id' => 'entity-one', 'employee_id' => 'active-doctor'];
            if ($job) {
                $expected['page'] = 2;
                $api->shouldReceive('withToken')->once()->with('test-token')->andReturnSelf();
            }
            $api->shouldReceive('getMany')->once()->withArgs(function (array $query, bool $groupByEntities) use ($expected): bool {
                $this->assertEquals($expected, $query);

                return $groupByEntities;
            })->andReturn($response);

            $repository = Mockery::mock(DeclarationRepository::class);
            $repository->shouldReceive('storeMany')->with([])->andReturnNull();
            $this->instance(DeclarationRepository::class, $repository);
        }

        if ($job) {
            $sync = new DeclarationsSync(legalEntity: $this->legalEntity, page: 2);
            $sync->user = $this->user;
            (new ReflectionMethod($sync, 'sendRequest'))->invoke($sync, 'test-token');
        } else {
            $this->index()->sync();
        }
    }
}

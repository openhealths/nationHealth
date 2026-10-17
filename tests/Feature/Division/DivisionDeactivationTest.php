<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Dto\Division\Model as DivisionData;
use App\Enums\Status;
use App\Livewire\Division\DivisionIndex;
use App\Livewire\Division\Forms\DivisionForm;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\User;
use App\Policies\DivisionPolicy;
use App\Repositories\DivisionRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DivisionDeactivationTest extends TestCase
{
    use DivisionMappingFixtures;

    private function divisionComponent(Status $status): DivisionIndex
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);

        $division = Mockery::mock(Division::class)->makePartial();
        $division->fill(['uuid' => 'division-uuid', 'name' => 'Local changes', 'status' => $status, 'legal_entity_id' => $entity->id]);
        $division->shouldReceive('refresh')->andReturnSelf();
        Gate::policy($division::class, DivisionPolicy::class);

        $user = Mockery::mock(User::class);
        $user->shouldReceive('checkPermissionTo')->andReturnFalse();
        $user->shouldReceive('cannot')->with('division:read')->andReturnFalse();
        $user->shouldReceive('cannot')->with('division:details')->andReturnTrue();
        $user->shouldReceive('cannot')->with('division:deactivate')->andReturnFalse();
        $user->shouldReceive('cannot')->with('deactivate', $division)
            ->andReturnUsing(fn (): bool => Gate::forUser($user)->denies('deactivate', $division));
        Auth::shouldReceive('user')->andReturn($user);

        $component = new class extends DivisionIndex
        {
            public Division $testDivision;

            public bool $formReloaded = false;

            protected function getDivision(int $id): ?Division
            {
                return $this->testDivision;
            }

            protected function getDeactivationValidationError(Division $division): ?string
            {
                return null;
            }

            public function setDivisionData(Division $division): void
            {
                $this->formReloaded = true;
            }
        };
        $component->testDivision = $division;
        $component->divisionForm = new DivisionForm($component, 'divisionForm');

        return $component;
    }

    private function fakeDeactivationResponse(array $data): void
    {
        $api = new DivisionApi();
        $api->preventStrayRequests()->stub(function (Request $request) use ($data) {
            $this->assertSame('PATCH', $request->method());
            $this->assertStringEndsWith('/api/divisions/' . $data['id'] . '/actions/deactivate', $request->url());

            return Factory::response(['data' => $data]);
        });
        $this->app->instance(DivisionApi::class, $api);
    }

    public static function deactivationStatuses(): array
    {
        return [[Status::ACTIVE], [Status::UNSYNCED]];
    }

    #[DataProvider('deactivationStatuses')]
    public function test_deactivation_discards_local_changes_only_for_unsynced_divisions(Status $status): void
    {
        $component = $this->divisionComponent($status);
        $repository = $this->mock(DivisionRepository::class);
        $response = $this->response([
            'name' => 'Name from eHealth', 'email' => 'synced@example.com', 'status' => 'INACTIVE',
        ]);
        $component->testDivision->uuid = $response['id'];
        $this->fakeDeactivationResponse($response);

        $this->assertTrue(Gate::forUser(Auth::user())->allows('viewAny', Division::class));
        $this->assertTrue(Gate::forUser(Auth::user())->denies('view', $component->testDivision));
        $this->assertTrue(Gate::forUser(Auth::user())->allows('deactivate', $component->testDivision));

        if ($status === Status::UNSYNCED) {
            $repository->shouldReceive('saveMappedDivision')->once()
                ->with(Mockery::on(function (DivisionData $data) use ($response): bool {
                    $this->assertSame('INACTIVE', $data->status);
                    $this->assertSame('Name from eHealth', $data->name);
                    $this->assertSame('synced@example.com', $data->email);
                    $this->assertSame($response['addresses'][0]['settlement_id'], $data->addresses[0]->settlementId);
                    $this->assertSame('+380501234567', $data->phones[0]->number);
                    $this->assertEquals($response['working_hours'], $data->workingHours);

                    return true;
                }), $component->testDivision, legalEntity())->andReturn($component->testDivision);
            $repository->shouldNotReceive('setAction');
        } else {
            $repository->shouldReceive('setAction')->once()->with($component->testDivision, 'INACTIVE');
            $repository->shouldNotReceive('saveMappedDivision');
        }

        $component->deactivate(1);

        $this->assertSame($status === Status::UNSYNCED, $component->formReloaded);
        $this->assertSame('INACTIVE', $component->divisionForm->division['status']);
        $this->assertSame(__('divisions.request.deactivated'), session('success'));
        $this->assertNull(session('error'));
    }

    public function test_failed_deactivation_request_preserves_local_changes(): void
    {
        $component = $this->divisionComponent(Status::UNSYNCED);
        $this->mock(DivisionApi::class)->shouldReceive('deactivate')->once()->with('division-uuid')
            ->andThrow(new RuntimeException('eHealth unavailable'))->shouldNotReceive('getDetails');
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision')->shouldNotReceive('setAction');
        Log::shouldReceive('channel')->once()->with('e_health_errors')->andReturnSelf();
        Log::shouldReceive('error')->once();

        $component->deactivate(1);

        $this->assertSame(Status::UNSYNCED, $component->testDivision->status);
        $this->assertSame('Local changes', $component->testDivision->name);
        $this->assertFalse($component->formReloaded);
        $this->assertSame(__('divisions.errors.deactivate'), session('error'));
        $this->assertNull(session('success'));
    }

    public static function invalidDeactivationResponses(): array
    {
        return [
            'unexpected status' => [['status' => 'ACTIVE']],
            'missing status' => [['status' => null]],
            'missing division data' => [['name' => null]],
        ];
    }

    #[DataProvider('invalidDeactivationResponses')]
    public function test_invalid_deactivation_response_does_not_replace_local_data(array $overrides): void
    {
        $component = $this->divisionComponent(Status::UNSYNCED);
        $response = $this->response($overrides);
        $component->testDivision->uuid = $response['id'];
        $this->fakeDeactivationResponse($response);
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision')->shouldNotReceive('setAction');

        $component->deactivate(1);

        $this->assertSame(Status::UNSYNCED, $component->testDivision->status);
        $this->assertSame('Local changes', $component->testDivision->name);
        $this->assertFalse($component->formReloaded);
        $this->assertSame(__('divisions.errors.deactivate'), session('error'));
        $this->assertNull(session('success'));
    }
}

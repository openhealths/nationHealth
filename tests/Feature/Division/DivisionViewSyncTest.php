<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Division\Model as DivisionData;
use App\Livewire\Division\DivisionView;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\DivisionRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DivisionViewSyncTest extends TestCase
{
    use DatabaseTransactions;

    private function divisionComponent(): DivisionView
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PHARMACY')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PHARMACY']);
        $entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED', 'legal_entity_type_id' => $typeId,
        ]);
        $this->app->instance('legalEntity', $entity);
        $division = Division::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Original division',
            'email' => 'division@example.com', 'status' => 'ACTIVE', 'legal_entity_id' => $entity->id,
        ]);
        $component = new DivisionView();
        $component->divisionUuid = $division->uuid;

        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->once()->with('division:details')->andReturnFalse();
        Auth::shouldReceive('user')->once()->andReturn($user);

        return $component;
    }

    private function mockResponse(DivisionView $component, array $overrides = []): void
    {
        $response = $this->mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(new DivisionCreate(array_replace([
            'uuid' => $component->divisionUuid, 'name' => 'Synchronized division',
            'type' => 'CLINIC', 'email' => 'synced@example.com', 'status' => 'ACTIVE',
            'addresses' => [['type' => 'RESIDENCE', 'country' => 'UA', 'settlement_id' => 'settlement-uuid']],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
        ], $overrides)));
        $this->mock(DivisionApi::class)->shouldReceive('getDetails')->once()
            ->with($component->divisionUuid)->andReturn($response);
    }

    private function expectDatabaseError(): void
    {
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')->once();
        Log::shouldReceive('channel')->once()->with('db_errors')->andReturn($logger);
    }

    public function test_sync_saves_mapped_response_into_existing_division(): void
    {
        $component = $this->divisionComponent();
        $division = Division::where('uuid', $component->divisionUuid)->firstOrFail();
        $this->mockResponse($component);
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->with(Mockery::on(function (DivisionData $data): bool {
                $this->assertSame('Synchronized division', $data->name);
                $this->assertSame('settlement-uuid', $data->addresses[0]->settlementId);
                $this->assertSame('+380501234567', $data->phones[0]->number);

                return true;
            }), Mockery::on(fn (Division $model): bool => $model->is($division)), legalEntity())
            ->andReturn($division)
            ->shouldNotReceive('syncDivisionData');

        $redirect = $component->sync();

        $this->assertSame(route('division.view', [legalEntity(), $division->id]), $redirect->getTargetUrl());
        $this->assertSame(__('Інформацію успішно оновлено'), session('success'));
        $this->assertNull(session('error'));
    }

    public function test_mapping_failure_does_not_save_or_flash_success(): void
    {
        $component = $this->divisionComponent();
        $this->mockResponse($component, ['name' => []]);
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');
        $this->expectDatabaseError();

        $this->assertNull($component->sync());
        $this->assertSame(__('errors.database.messages.save_error'), session('error'));
        $this->assertNull(session('success'));
    }

    public function test_persistence_failure_does_not_redirect_or_flash_success(): void
    {
        $component = $this->divisionComponent();
        $this->mockResponse($component);
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->andThrow(new RuntimeException('Database failed'));
        $this->expectDatabaseError();

        $this->assertNull($component->sync());
        $this->assertSame(__('errors.database.messages.save_error'), session('error'));
        $this->assertNull(session('success'));
    }
}

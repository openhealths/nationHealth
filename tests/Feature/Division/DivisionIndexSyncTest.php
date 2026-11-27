<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Division\DivisionGetMany;
use App\Enums\JobStatus;
use App\Livewire\Division\DivisionIndex;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\DivisionRepository;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class DivisionIndexSyncTest extends TestCase
{
    use DivisionMappingFixtures;

    public function test_first_page_sync_maps_missing_values_and_passes_a_dto_to_the_repository(): void
    {
        config(['ehealth.api.page_size_max' => 75]);
        $entity = Mockery::mock(LegalEntity::class)->makePartial();
        $entity->id = 7;
        $entity->shouldReceive('setEntityStatus')->once()
            ->with(JobStatus::COMPLETED, LegalEntity::ENTITY_DIVISION);
        $this->app->instance('legalEntity', $entity);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->once()->with('viewAny', Division::class)->andReturnFalse();
        Auth::shouldReceive('user')->twice()->andReturn($user);
        $row = $this->response(['external_id' => '0']);
        $row['uuid'] = $row['id'];
        unset($row['id'], $row['mountain_group']);
        $response = $this->mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([$row]);
        $response->shouldReceive('isNotLast')->once()->andReturnFalse();
        $this->mock(DivisionApi::class)->shouldReceive('getMany')->once()
            ->with(DivisionApi::URL, ['page' => 1, 'page_size' => 75])->andReturn($response);
        $this->mock(DivisionRepository::class)->shouldReceive('saveDivisionsList')->once()
            ->with(Mockery::on(function (DivisionGetMany $divisions) use ($row): bool {
                $this->assertCount(1, $divisions->divisions);
                $this->assertSame($row['uuid'], $divisions->divisions[0]->uuid);
                $this->assertSame('0', $divisions->divisions[0]->externalId);
                $this->assertFalse($divisions->divisions[0]->mountainGroup);
                $this->assertCount(1, $divisions->divisions[0]->addresses);
                $this->assertCount(1, $divisions->divisions[0]->phones);

                return true;
            }), $entity);
        $component = new class extends DivisionIndex
        {
            protected function isSyncProcessing(): bool
            {
                return false;
            }
        };

        $component->sync();
    }
}

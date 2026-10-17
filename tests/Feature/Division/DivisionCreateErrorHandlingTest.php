<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate as DivisionResponse;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Division\Model as DivisionData;
use App\Livewire\Division\DivisionCreate;
use App\Livewire\Division\Forms\DivisionForm;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Repositories\DivisionRepository;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DivisionCreateErrorHandlingTest extends TestCase
{
    private function divisionComponent(): DivisionCreate
    {
        $component = new class extends DivisionCreate
        {
            public ?string $redirectUrl = null;

            public function runCreation(Division $division): void
            {
                $this->divisionCreate($division);
            }

            public function redirect($url, $navigate = false): void
            {
                $this->redirectUrl = $url;
            }
        };
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = [
            'name' => 'Main division', 'type' => 'CLINIC', 'email' => 'division@example.com',
            'externalId' => null, 'addresses' => [], 'phones' => [],
            'location' => null, 'workingHours' => [],
        ];

        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);

        return $component;
    }

    private function mockResponse(array $overrides = []): void
    {
        $response = $this->mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(new DivisionResponse(array_replace([
            'uuid' => 'division-uuid', 'name' => 'Main division', 'type' => 'CLINIC',
            'email' => 'division@example.com', 'status' => 'ACTIVE', 'addresses' => [], 'phones' => [],
        ], $overrides)));
        $this->mock(DivisionApi::class)->shouldReceive('create')->once()->andReturn($response);
    }

    private function expectChannelError(string $channel): void
    {
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')->once();
        Log::shouldReceive('channel')->once()->with($channel)->andReturn($logger);
    }

    public function test_api_failure_redirects_to_saved_draft_with_error(): void
    {
        $component = $this->divisionComponent();
        $division = new Division();
        $division->id = 13;
        $this->mock(DivisionApi::class)->shouldReceive('create')->once()
            ->andThrow(new RuntimeException('API failed'));
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');
        $this->expectChannelError('e_health_errors');

        $component->runCreation($division);

        $this->assertSame(__('errors.ehealth.messages.request_error'), session('error'));
        $this->assertSame(route('division.edit', [legalEntity(), $division]), $component->redirectUrl);
        $this->assertNull(session('success'));
    }

    public function test_empty_response_redirects_to_saved_draft_with_error(): void
    {
        $component = $this->divisionComponent();
        $division = new Division();
        $division->id = 13;
        $response = $this->mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(new DivisionResponse([]));
        $this->mock(DivisionApi::class)->shouldReceive('create')->once()->andReturn($response);
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');
        $this->expectChannelError('e_health_errors');

        $component->runCreation($division);

        $this->assertSame(__('errors.ehealth.messages.request_error'), session('error'));
        $this->assertSame(route('division.edit', [legalEntity(), $division]), $component->redirectUrl);
        $this->assertNull(session('success'));
    }

    public function test_response_mapping_failure_uses_database_error_handling(): void
    {
        $component = $this->divisionComponent();
        $this->mockResponse(['name' => []]);
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');
        $this->expectChannelError('db_errors');

        $component->runCreation(new Division());

        $this->assertSame(__('errors.database.messages.save_error'), session('error'));
        $this->assertNull($component->redirectUrl);
        $this->assertNull(session('success'));
    }

    public function test_database_failure_does_not_redirect_or_flash_success(): void
    {
        $component = $this->divisionComponent();
        $this->mockResponse();
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->andThrow(new RuntimeException('Database failed'));
        $this->expectChannelError('db_errors');

        $component->runCreation(new Division());

        $this->assertSame(__('errors.database.messages.save_error'), session('error'));
        $this->assertNull($component->redirectUrl);
        $this->assertNull(session('success'));
    }

    public function test_success_redirects_only_after_saving_mapped_response(): void
    {
        $component = $this->divisionComponent();
        $division = new Division();
        $this->mockResponse();
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->with(Mockery::on(fn (DivisionData $data): bool => $data->uuid === 'division-uuid'), $division, legalEntity())
            ->andReturn($division);

        $component->runCreation($division);

        $this->assertSame(route('division.index', [legalEntity()]), $component->redirectUrl);
        $this->assertSame(__('forms.success_response'), session('success'));
        $this->assertNull(session('error'));
    }
}

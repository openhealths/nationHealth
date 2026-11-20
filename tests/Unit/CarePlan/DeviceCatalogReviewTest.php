<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceCatalogReviewTest extends TestCase
{
    public function test_models_with_the_same_name_keep_distinct_model_numbers_and_ids(): void
    {
        Livewire::test(DeviceCatalogReviewHarness::class)->assertSee('MODEL-A')->assertSee('MODEL-B')
            ->assertSee('00000000-0000-4000-8000-000000000051')->assertSee('00000000-0000-4000-8000-000000000052');
    }

    public function test_participant_details_belong_to_the_displayed_device(): void
    {
        Livewire::test(DeviceCatalogReviewHarness::class)
            ->set('searchResults.1.program_devices', [['name' => 'Program B', 'max_daily_count' => 2]])
            ->assertSee('Program B')->assertSee(__('device-requests.patient.participant_details').' — Program B');
    }
}

class DeviceCatalogReviewHarness extends Component
{
    public array $searchResults = [
        ['id' => '00000000-0000-4000-8000-000000000051', 'display_name' => 'Same name', 'model_number' => 'MODEL-A'],
        ['id' => '00000000-0000-4000-8000-000000000052', 'display_name' => 'Same name', 'model_number' => 'MODEL-B'],
    ];
    public int $deviceSearchTotalPages = 1;
    public int $searchPage = 1;
    public string $searchQuery = '';
    public string $deviceSearchModelNumber = '';
    public array $dictionaries = [];

    public function render()
    {
        return view()->file(base_path('tests/Fixtures/CarePlan/device-catalog-review.blade.php'));
    }
}

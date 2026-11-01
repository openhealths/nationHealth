<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Classes\eHealth\Api\Address as AddressApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalEntityDetailsLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->actingAs(new User(), 'ehealth');
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
        ]);
        $this->app->instance('legalEntity', $this->legalEntity);
        $this->legalEntity->addresses()->create([
            'type' => 'RESIDENCE',
            'country' => 'UA',
            'area' => 'КИЇВСЬКА',
            'region' => 'Бучанський',
            'settlement' => 'Ірпінь',
            'settlement_type' => 'CITY',
            'settlement_id' => (string) Str::uuid(),
            'street_type' => 'STREET',
            'street' => 'Соборна',
        ]);
    }

    public function test_details_render_after_property_updates_and_refreshes(): void
    {
        Livewire::test(LegalEntityDetailsLifecycleComponent::class)
            ->assertSee($this->legalEntity->uuid)
            ->set('exactSettlementMatch', true)
            ->assertSee($this->legalEntity->uuid)
            ->call('$refresh')
            ->assertSee($this->legalEntity->uuid);
    }

    #[DataProvider('addressSearchActions')]
    public function test_details_render_after_address_search_requests(
        string $action,
        string $apiMethod,
        string $suggestionsProperty
    ): void {
        $suggestions = [['id' => 'result-id', 'name' => 'Новий запис']];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn($suggestions);
        $api = Mockery::mock(AddressApi::class);
        $api->shouldReceive($apiMethod)->once()->andReturn($response);
        $this->instance(AddressApi::class, $api);

        Livewire::test(LegalEntityDetailsLifecycleComponent::class)
            ->call($action, 'address', $suggestionsProperty, 'Новий запис')
            ->assertSet($suggestionsProperty, $suggestions)
            ->assertSee($this->legalEntity->uuid);
    }

    public static function addressSearchActions(): array
    {
        return [
            'region' => ['updateRegion', 'getDistricts', 'districts'],
            'settlement' => ['updateSettlement', 'getSettlements', 'settlements'],
            'street' => ['updateStreet', 'getStreets', 'streets'],
        ];
    }
}

class LegalEntityDetailsLifecycleComponent extends LegalEntityDetails
{
    // Keep the inherited lifecycle and actions, omitting unrelated owner and dictionary setup.
    public function mount(?LegalEntity $legalEntity = null): void
    {
        $this->legalEntity = $this->getLegalEntity();
        $this->address = $this->legalEntity->addresses->first()->toArray();
    }

    public function render()
    {
        // Read the protected model the same way as the first line of the details view.
        return '<div>{{ $this->legalEntity->uuid }}</div>';
    }
}

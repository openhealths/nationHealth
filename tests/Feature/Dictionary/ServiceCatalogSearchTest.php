<?php

declare(strict_types=1);

namespace Tests\Feature\Dictionary;

use App\Livewire\Dictionary\ServiceCatalog;
use App\Models\LegalEntity;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class ServiceCatalogSearchTest extends TestCase
{
    use MocksBasicDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        $this->mockBasicDictionaries(['SERVICE_CATEGORY' => ['counselling' => 'Консультування', 'imaging' => 'Візуалізація']]);
        Cache::forever(ServiceDictionary::KEY, [[
            'id' => 'root', 'code' => 'ROOT', 'name' => 'Медичні послуги', 'is_active' => true,
            'groups' => [[
                'id' => 'examinations', 'code' => 'CONSULT', 'name' => 'Консультації', 'is_active' => true,
                'request_allowed' => true,
                'services' => [
                    ['id' => 'arthrography', 'code' => 'A0001', 'name' => 'Артрографія', 'category' => 'imaging', 'is_active' => true, 'request_allowed' => true],
                    ['id' => 'neurological', 'code' => 'N0002', 'name' => 'Огляд; неврологічний', 'category' => 'counselling', 'is_active' => true, 'request_allowed' => true],
                    ['id' => 'inactive', 'code' => 'N0003', 'name' => 'Огляд; неврологічний, повторний', 'category' => 'counselling', 'is_active' => false, 'request_allowed' => false],
                ],
            ]],
        ]]);
        Cache::forever(ServiceDictionary::KEY.':fresh', true);
    }

    #[DataProvider('queries')]
    public function test_ukrainian_words_match_in_any_order_and_keep_the_parent_path(string $query, string $event): void
    {
        $component = Livewire::test(ServiceCatalog::class, [
            'legalEntity' => new LegalEntity(), 'selectionMode' => true, 'selectionEvent' => $event,
        ])->set('filterServiceIsActive', '1')->set('filterCodeOrName', $query)->call('search')
            ->assertSee('Медичні послуги')->assertSee('Консультації')->assertSee('Огляд; неврологічний')
            ->assertDontSee('Артрографія')->assertDontSee('Огляд; неврологічний, повторний');
        $component->call('selectService', 'neurological')->assertDispatched($event, static fn ($name, array $parameters): bool =>
            $parameters['service']['id'] === 'neurological'
            && $parameters['service']['category'] === 'counselling'
            && $parameters['service']['type'] === 'service'
        );
        Http::assertNothingSent();
    }

    public static function queries(): array
    {
        return [
            ['неврологічний огляд', 'encounter-referral-service-selected'],
            ['  НЕВРОЛОГІЧНИЙ   ОГЛЯД  ', 'care-plan-service-selected'],
            ['неврологічний; огляд', 'care-plan-service-selected'],
            ['огляд, невролог', 'encounter-referral-service-selected'],
        ];
    }

    public function test_a_group_search_keeps_its_children_and_still_applies_other_filters(): void
    {
        Livewire::test(ServiceCatalog::class, ['legalEntity' => new LegalEntity(), 'selectionMode' => true])
            ->set('filterCodeOrName', 'Консультації')->set('filterCategory', 'counselling')
            ->set('filterServiceIsActive', '0')->call('search')
            ->assertSee('Огляд; неврологічний, повторний')->assertDontSee('Артрографія');
    }

    public function test_code_search_and_reset_from_another_page_restore_the_catalog(): void
    {
        $component = Livewire::test(ServiceCatalog::class, ['legalEntity' => new LegalEntity(), 'selectionMode' => true])
            ->call('setPage', 4)->set('filterCodeOrName', 'N0002')->call('search')
            ->assertSet('paginators.page', 1)->assertSee('Огляд; неврологічний')->assertDontSee('Артрографія');
        $component->set('filterCodeOrName', 'неврологічний артрографія')->call('search')
            ->assertDontSee('Огляд; неврологічний')->assertDontSee('Артрографія')
            ->call('resetFilters')->assertSet('filterCodeOrName', '')->assertSee('Артрографія');
    }
}

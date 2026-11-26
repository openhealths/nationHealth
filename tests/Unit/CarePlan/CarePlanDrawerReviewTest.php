<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Models\CarePlan;
use App\Models\LegalEntity;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePlanDrawerReviewTest extends TestCase
{
    public function test_device_programs_fall_back_to_the_general_dictionary(): void
    {
        $page = Livewire::test(CarePlanDrawerReviewHarness::class);
        $page->set('dictionaries.medical_programs', ['program-a' => 'General program']);
        $this->assertSame('General program', $this->xpath($page->html())
            ->evaluate('string(//select[@id="review-device-program"]/option[@value="program-a"])'));
        $page->set('dictionaries.medical_programs_device', ['device-program' => 'Device program']);
        $page->assertSee('Device program')->assertDontSee('General program');
    }

    #[DataProvider('deviceNames')]
    public function test_device_model_uses_available_catalog_names(array $product, string $label): void
    {
        $page = Livewire::test(CarePlanDrawerReviewHarness::class)->set('selectedProduct', $product);
        $this->assertSame($label, $this->xpath($page->html())->evaluate('string(//button[@id="review-device-product"])'));
    }

    public static function deviceNames(): array
    {
        return [
            'nested name' => [['device_names' => [['name' => 'Named model']]], 'Named model'],
            'description' => [['description' => 'Described model'], 'Described model'],
            'reference' => [[], 'model-reference'],
        ];
    }

    public function test_service_form_preserves_both_save_actions(): void
    {
        $xpath = $this->xpath(Livewire::test(CarePlanDrawerReviewHarness::class)->html());
        $this->assertSame(1, $xpath->query('//form[.//select[@id="serviceProgram"]]//button[@type="submit" and not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//form[.//select[@id="serviceProgram"]]//button[@*[name()="wire:click"]="saveActivityAndSign" and not(@disabled)]')->length);
    }

    public function test_missing_medical_record_lists_are_empty_and_resource_types_override_record_type(): void
    {
        $page = Livewire::test(CarePlanDrawerReviewHarness::class);
        $page->assertSee(__('care-plan.search_medical_records'));
        foreach (['availableConditions' => 'Condition', 'availableObservations' => 'Observation', 'availableReports' => 'DiagnosticReport'] as $list => $type) {
            $page->set('recordLists', [$list => [['uuid' => 'record-one', 'name' => 'Record label', 'date' => '20.11.2026', 'type' => 'Unknown']]]);
            $page->assertSee('Record label')->assertDontSee('Unknown');
            $this->assertStringContainsString($type, $page->html());
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}

class CarePlanDrawerReviewHarness extends Component
{
    public array $activityForm = ['product_reference' => 'model-reference', 'kind' => 'service_request'];
    public array $selectedProduct = [];
    public string $selectedProgram = '';
    public array $dictionaries = [];
    public array $linkedGrounds = [];
    public array $recordLists = [];

    public function mount(): void
    {
        app()->instance('legalEntity', (new LegalEntity())->forceFill(['id' => 1]));
    }

    public function render()
    {
        return view()->file(base_path('tests/Fixtures/CarePlan/drawer-review.blade.php'), [
            'carePlan' => (new CarePlan())->forceFill(['id' => 1]),
        ]);
    }
}
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
use Tests\TestCase;

class MedicationActivityReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('legalEntity', (new LegalEntity())->forceFill(['id' => 1]));
    }

    public function test_catalog_shows_concentration_and_only_the_prepared_dose_limit(): void
    {
        $catalog = Livewire::test(MedicationReviewHarness::class);
        $catalog->assertSee('250 мг/5 мл');
        $this->assertSame('-', $this->fact($catalog->html(), 'maximum_daily_dose'));
        $catalog->set('searchResults.0.display_max_daily_dosage', '2 мг');
        $this->assertSame('2 мг', $this->fact($catalog->html(), 'maximum_daily_dose'));
    }

    public function test_unknown_dosed_flag_is_distinct_from_false(): void
    {
        $catalog = Livewire::test(MedicationReviewHarness::class);
        $this->assertSame(__('forms.no_data'), $this->fact($catalog->html(), 'dosed_medication'));
        $catalog->set('searchResults.0.dosage_form_is_dosed', false);
        $this->assertSame(__('forms.no'), $this->fact($catalog->html(), 'dosed_medication'));
    }

    public function test_daily_dose_displays_its_own_unit(): void
    {
        $page = Livewire::test(MedicationReviewHarness::class);
        $xpath = $this->xpath($page->html());
        $this->assertSame('мг', $xpath->evaluate('string(//input[@id="med_daily_amount"]/following-sibling::select/option)'));
        $this->assertSame('activityForm.daily_amount', $xpath->evaluate('string(//input[@id="med_daily_amount"]/@*[name()="wire:model"])'));
    }

    public function test_unsupported_filters_do_not_hide_results_from_a_paginated_catalog(): void
    {
        $page = Livewire::test(MedicationReviewHarness::class);
        $xpath = $this->xpath($page->html());
        foreach (['medication-innm', 'medication-atc', 'medication-dosage-form', 'medication-blank-type'] as $id) {
            $this->assertSame(1, $xpath->query('//*[@id="'.$id.'" and @disabled]')->length);
        }
        $page->assertSee(__('care-plan.catalog_filters_unavailable'));
        $this->assertSame(0, $xpath->query('//fieldset[contains(@class,"care-plan-medication-result") and @x-show]')->length);
    }

    public function test_failed_medication_save_displays_session_error_once_without_validation_errors(): void
    {
        $page = Livewire::test(MedicationReviewHarness::class)->call('saveActivity');
        $page->assertHasNoErrors()->assertSee('Fixture medication save error');
        $this->assertSame(1, substr_count($page->html(), 'Fixture medication save error'));
        $this->assertFalse(session()->has('error'));
        $page->call('$refresh')->assertDontSee('Fixture medication save error');
    }

    private function fact(string $html, string $key): string
    {
        return $this->xpath($html)->evaluate('string(//dl[contains(@class,"care-plan-medication-facts")]/div[dt[text()="'.__('care-plan.'.$key).':"]]/dd)');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}

class MedicationReviewHarness extends Component
{
    protected CarePlan $carePlan;
    public array $activityForm = ['quantity_code' => 'ML', 'daily_amount' => '2', 'daily_amount_code' => 'MG'];
    public array $selectedProduct = ['name' => 'Test medication'];
    public string $selectedProgram = '';
    public array $dictionaries = ['MEDICATION_UNIT' => ['MG' => 'мг', 'ML' => 'мл']];
    public array $linkedGrounds = [];
    public string $searchQuery = '';
    public int $searchPage = 1;
    public array $searchResults = [[
        'id' => 'test', 'name' => 'Test medication',
        'ingredients' => [['name' => 'Test ingredient', 'is_primary' => true, 'dosage' => [
            'numerator_value' => 250, 'numerator_unit' => 'MG', 'denumerator_value' => 5, 'denumerator_unit' => 'ML',
        ]]],
        'program_medications' => [['medical_program_id' => 'other', 'max_daily_dosage' => 900]],
    ]];

    public function saveActivity(): void
    {
        Session::flash('error', 'Fixture medication save error');
    }

    public function render()
    {
        $carePlan = (new CarePlan())->forceFill(['id' => 1]);
        $carePlan->setRelation('person', null);

        return view()->file(base_path('tests/Fixtures/CarePlan/medication-review.blade.php'), ['carePlan' => $carePlan]);
    }
}

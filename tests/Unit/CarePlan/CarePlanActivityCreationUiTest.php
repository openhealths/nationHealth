<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Livewire\CarePlan\CarePlanShow;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\View\View;
use Livewire\Livewire;
use Tests\TestCase;

class CarePlanActivityCreationUiTest extends TestCase
{
    public function test_unavailable_actions_remain_visible_without_livewire_calls(): void
    {
        $page = Livewire::test(CarePlanCreationUiHarness::class, ['allowed' => false]);
        $page->assertSee(__('care-plan.new_prescription'))->assertSee(__('care-plan.add_services'))
            ->assertSee(__('care-plan.add_medications'))->assertSee(__('care-plan.add_medical_devices'))
            ->assertSee(__('care-plan.cancel_care_plan'))->assertSee(__('care-plan.complete_care_plan'))
            ->assertDontSeeHtml('wire:click="initActivityForm');
        $xpath = $this->xpath($page->html());
        $this->assertSame(3, $xpath->query('//fieldset//button[@disabled]')->length);
        $this->assertSame(3, $xpath->query('//button[@role="menuitem" and @disabled]')->length);
        foreach ($xpath->query('//fieldset//button') as $button) {
            $this->assertSame(__('care-plan.lifecycle_action_unavailable'), $button->getAttribute('title'));
        }
    }

    public function test_available_actions_open_the_existing_forms(): void
    {
        $page = Livewire::test(CarePlanCreationUiHarness::class);
        $xpath = $this->xpath($page->html());
        $this->assertSame(3, $xpath->query('//fieldset//button[not(@disabled)]')->length);
        foreach (['service_request', 'medication_request', 'device_request'] as $kind) {
            $this->assertSame(2, substr_count($page->html(), 'wire:click="initActivityForm(\''.$kind.'\')"'));
        }
    }

    public function test_buttons_display_the_supplied_reason(): void
    {
        $page = Livewire::test(CarePlanCreationUiHarness::class, ['allowed' => false, 'reason' => 'Fixture reason']);
        foreach ($this->xpath($page->html())->query('//button[@disabled and @role="menuitem"]') as $button) {
            $this->assertSame('Fixture reason', $button->getAttribute('title'));
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($document);
    }
}

class CarePlanCreationUiHarness extends CarePlanShow
{
    public bool $allowed = true;
    public ?string $reason = null;

    public function mount(?CarePlan $carePlan = null, bool $allowed = true, ?string $reason = null): void
    {
        $this->carePlan = (new CarePlan())->forceFill(['id' => 131, 'person_id' => 1, 'status' => 'active']);
        $this->carePlan->setRelation('activities', collect());
        $this->allowed = $allowed;
        $this->reason = $reason;
        app()->instance('legalEntity', (new LegalEntity())->forceFill(['id' => 1]));
    }

    public function getCanManageActivitiesProperty(): bool
    {
        return $this->allowed;
    }

    public function getCanCancelPlanProperty(): bool
    {
        return false;
    }

    public function getCanCompletePlanProperty(): bool
    {
        return false;
    }

    public function render(): View
    {
        return view()->file(base_path('tests/Fixtures/CarePlan/creation-actions.blade.php'), [
            'carePlan' => $this->carePlan,
            'activityCreationBlockReason' => $this->reason,
        ]);
    }
}

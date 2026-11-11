<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\CarePlan;

use App\Livewire\CarePlan\CarePlanShow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePlanActivityProgramChangeTest extends TestCase
{
    #[DataProvider('activityKinds')]
    public function test_program_change_updates_form_before_any_product_is_selected(string $kind): void
    {
        $component = new CarePlanShow();
        $component->activityForm = ['kind' => $kind];
        $component->selectedProgram = 'program-identifier';
        $component->searchQuery = 'previous search';
        $component->searchResults = [['id' => 'old-product']];
        $component->searchPage = 2;

        $component->updatedSelectedProgram();

        $this->assertSame('program-identifier', $component->activityForm['program']);
        $this->assertSame('', $component->searchQuery);
        $this->assertSame([], $component->searchResults);
        $this->assertSame(1, $component->searchPage);
    }

    public static function activityKinds(): array
    {
        return [
            'medication' => ['MedicationRequest'],
            'device' => ['DeviceRequest'],
            'service' => ['ServiceRequest'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Livewire\Employee\Forms\EmployeeForm;
use Livewire\Component;
use Tests\TestCase;

class EmployeeFormPreparedDataTest extends TestCase
{
    private function makeForm(): EmployeeForm
    {
        return new EmployeeForm(new class extends Component
        {
            public function render()
            {
                return '';
            }
        }, 'form');
    }

    public function test_get_prepared_data_normalizes_empty_division_id_to_null(): void
    {
        $form = $this->makeForm();
        $form->divisionId = '';

        $preparedData = $form->getPreparedData();

        $this->assertArrayHasKey('division_id', $preparedData);
        $this->assertNull($preparedData['division_id']);
    }

    public function test_get_prepared_data_keeps_selected_division_id(): void
    {
        $form = $this->makeForm();
        $form->divisionId = '15';

        $preparedData = $form->getPreparedData();

        $this->assertSame('15', $preparedData['division_id']);
    }
}

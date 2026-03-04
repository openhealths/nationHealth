<?php

namespace Tests\Feature\Livewire;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\User;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup base data
        $this->user = User::factory()->create();
        $this->legalEntity = LegalEntity::factory()->create();
        $this->party = Party::factory()->create();
        
        $this->user->update(['party_id' => $this->party->id]);
        
        $this->employee = Employee::factory()->create([
            'legal_entity_id' => $this->legalEntity->id,
            'party_id' => $this->party->id,
            'user_id' => clone $this->user->id, // old legacy, shouldn't matter as we use party now
            'status' => Status::APPROVED,
        ]);
    }

    public function test_employee_show()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Employee\EmployeeShow::class, ['legalEntity' => $this->legalEntity, 'employee' => $this->employee])
            ->assertStatus(200);
    }

    public function test_employee_edit()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Employee\EmployeeEdit::class, ['legalEntity' => $this->legalEntity, 'employee' => $this->employee])
            ->assertStatus(200)
            ->set('form.employeeType', Role::DOCTOR->value)
            ->call('save')
            ->assertHasNoErrors();
    }
    
    public function test_employee_request_edit()
    {
        $request = EmployeeRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'party_id' => $this->party->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Employee\EmployeeRequestEdit::class, ['legalEntity' => $this->legalEntity, 'employee_request' => $request])
            ->assertStatus(200)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_employee_position_add()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Employee\EmployeePositionAdd::class, ['legalEntity' => $this->legalEntity, 'party' => $this->party])
            ->assertStatus(200)
            ->set('form.employeeType', Role::DOCTOR->value)
            ->call('save')
            ->assertHasNoErrors();
    }
}

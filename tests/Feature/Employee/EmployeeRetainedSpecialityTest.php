<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Livewire\Employee\EmployeeEdit;
use App\Livewire\Employee\Forms\EmployeeForm;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\EmployeeRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\FakesEmployeeDictionaries;
use Tests\TestCase;

class EmployeeRetainedSpecialityTest extends TestCase
{
    use DatabaseTransactions;
    use FakesEmployeeDictionaries;

    private LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmployeeDictionaries();
        $type = LegalEntityType::firstOrCreate(['name' => 'OUTPATIENT']);
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'is_active' => true, 'legal_entity_type_id' => $type->id,
        ]);
        $this->instance('legalEntity', $this->legalEntity);
    }

    public function test_imported_therapy_and_nursing_can_be_retained_without_a_linked_user(): void
    {
        $employee = $this->employee();
        $component = $this->editor($employee);
        $this->assertNull($employee->userId);
        $this->assertTrue($this->validateSpecialities($component)->passes());
        $this->assertSame(['THERAPIST', 'NURSING'], array_column($component->form->doctor['specialities'], 'speciality'));
    }

    public function test_saving_an_edit_with_a_supplied_email_persists_a_draft_and_both_original_specialities(): void
    {
        $component = $this->completeEditor();
        $component->form->party['email'] = 'employee@example.test';
        $component->save();

        $this->assertSame([], $component->getErrorBag()->toArray());
        $request = EmployeeRequest::whereEmployeeId($component->employeeId)->sole();
        $this->assertSame('employee@example.test', $request->email);
        $this->assertNull($request->userId);
        $this->assertEqualsCanonicalizing(['THERAPIST', 'NURSING'], array_column(
            $request->revision->data['doctor']['specialities'],
            'speciality'
        ));
    }

    public function test_saving_without_email_still_returns_validation_instead_of_creating_a_draft(): void
    {
        $component = $this->completeEditor();
        $component->form->party['email'] = null;
        $component->save();

        $this->assertArrayHasKey('form.party.email', $component->getErrorBag()->toArray());
        $this->assertSame(0, EmployeeRequest::whereEmployeeId($component->employeeId)->count());
    }

    public function test_changing_an_unavailable_certificate_does_not_inherit_the_exception(): void
    {
        $component = $this->editor($this->employee());
        $component->form->doctor['specialities'][1]['certificateNumber'] = 'CHANGED';
        $this->assertTrue($this->validateSpecialities($component)->fails());
    }

    public function test_duplicating_a_retained_document_does_not_create_another_legacy_certificate(): void
    {
        $component = $this->editor($this->employee());
        $component->form->doctor['specialities'][] = $component->form->doctor['specialities'][1];
        $this->assertTrue($this->validateSpecialities($component)->fails());
    }

    public function test_malformed_sibling_or_certificate_field_returns_validation_errors(): void
    {
        $component = $this->editor($this->employee());
        $component->form->doctor['specialities'][] = 'invalid-row';
        $this->assertTrue($this->validateSpecialities($component)->fails());
        array_pop($component->form->doctor['specialities']);
        $component->form->doctor['specialities'][1]['certificateNumber'] = ['invalid-value'];
        $this->assertTrue($this->validateSpecialities($component)->fails());
    }

    public function test_copied_legacy_document_cannot_be_added_to_another_employee(): void
    {
        $first = $this->editor($this->employee());
        $secondEmployee = $this->employee(withLegacy: false);
        $second = $this->editor($secondEmployee);
        $second->form->doctor['specialities'][] = $first->form->doctor['specialities'][1];
        $this->assertTrue($this->validateSpecialities($second)->fails());
    }

    public function test_reordering_existing_documents_and_removing_a_legacy_document_are_allowed(): void
    {
        $component = $this->editor($this->employee());
        $component->form->doctor['specialities'] = array_reverse($component->form->doctor['specialities']);
        $this->assertTrue($this->validateSpecialities($component)->passes());
        $component->form->doctor['specialities'] = array_values(array_filter(
            $component->form->doctor['specialities'],
            fn (array $item): bool => $item['speciality'] !== 'NURSING'
        ));
        $this->assertTrue($this->validateSpecialities($component)->passes());
    }

    public function test_changing_role_does_not_reuse_the_original_roles_legacy_values(): void
    {
        $component = $this->editor($this->employee());
        $component->form->employeeType = 'ASSISTANT';
        $this->assertTrue($this->validateSpecialities($component)->fails());
    }

    public function test_request_edit_uses_approved_employee_instead_of_the_mutable_revision(): void
    {
        $employee = $this->employee();
        $request = EmployeeRequest::create([
            'legal_entity_id' => $this->legalEntity->id, 'employee_id' => $employee->id,
            'employee_type' => 'SPECIALIST', 'position' => 'P8', 'start_date' => '2024-01-01',
        ]);
        $this->assertSame(['THERAPIST', 'NURSING'], array_column(
            (new EmployeeRepository())->originalSpecialities($request, $this->legalEntity->id, 'SPECIALIST'),
            'speciality'
        ));
        $request->employeeId = null;
        $request->unsetRelation('employee');
        $this->assertSame([], (new EmployeeRepository())->originalSpecialities($request, $this->legalEntity->id, 'SPECIALIST'));
    }

    public function test_another_legal_entity_or_inactive_employee_cannot_supply_retained_values(): void
    {
        $employee = $this->employee();
        $repository = new EmployeeRepository();
        $this->assertSame([], $repository->originalSpecialities($employee, $this->legalEntity->id + 1, 'SPECIALIST'));
        $employee->status = Status::STOPPED;
        $this->assertSame([], $repository->originalSpecialities($employee, $this->legalEntity->id, 'SPECIALIST'));
    }

    private function employee(bool $withLegacy = true): Employee
    {
        $party = Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Тест', 'last_name' => 'Довідник',
            'birth_date' => '1990-01-01', 'gender' => 'MALE', 'tax_id' => '1234567890', 'no_tax_id' => false,
        ]);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $this->legalEntity->id, 'party_id' => $party->id,
            'employee_type' => 'SPECIALIST', 'position' => 'P8', 'status' => Status::APPROVED,
            'start_date' => '2024-01-01', 'user_id' => null, 'is_active' => true,
        ]);
        foreach ($withLegacy ? ['THERAPIST', 'NURSING'] : ['THERAPIST'] as $index => $code) {
            $employee->specialities()->create([
                'speciality' => $code, 'speciality_officio' => $index === 0, 'level' => 'SECOND',
                'qualification_type' => 'AWARDING', 'attestation_name' => 'МОЗ',
                'attestation_date' => '2024-01-01', 'certificate_number' => 'RETAINED-'.$code,
            ]);
        }

        return $employee;
    }

    private function editor(Employee $employee): EmployeeRetainedSpecialityEditor
    {
        $component = new EmployeeRetainedSpecialityEditor();
        $component->form = new EmployeeForm($component, 'form');
        $component->setOriginalEmployee($employee);
        $component->form->employeeType = 'SPECIALIST';
        $component->form->doctor['specialities'] = $component->originalEmployeeSpecialities();

        return $component;
    }

    private function completeEditor(): EmployeeRetainedSpecialityEditor
    {
        $this->actingAs(User::create(['email' => 'hr@example.test', 'password' => bcrypt('test-password')]));
        Gate::before(fn (): bool => true);
        $employee = $this->employee();
        $employee->party->update(['no_tax_id' => true, 'tax_id' => '111222333']);
        $component = $this->editor($employee);
        $component->loadDictionaries();
        $component->form->hydrate($employee);
        $component->form->party['phones'] = [['type' => 'MOBILE', 'number' => '+380500000001']];
        $component->form->documents = [[
            'type' => 'NATIONAL_ID', 'number' => '111222333', 'issuedBy' => 'МОЗ', 'issuedAt' => '01.01.2010',
        ]];
        $component->form->doctor['educations'] = [[
            'country' => 'UA', 'city' => 'Київ', 'institutionName' => 'Університет', 'issuedDate' => null,
            'diplomaNumber' => 'TEST-001', 'degree' => 'MASTER', 'speciality' => 'Медична біохімія',
        ]];

        return $component;
    }

    private function validateSpecialities(EmployeeRetainedSpecialityEditor $component): \Illuminate\Contracts\Validation\Validator
    {
        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($component->form);
        $specialityRules = array_filter($rules, fn (string $key): bool => str_starts_with($key, 'doctor.specialities'), ARRAY_FILTER_USE_KEY);

        return Validator::make(['doctor' => $component->form->doctor], $specialityRules);
    }
}

class EmployeeRetainedSpecialityEditor extends EmployeeEdit
{
    public function setOriginalEmployee(Employee $employee): void
    {
        $this->employee = $employee;
        $this->employeeId = $employee->id;
    }
}

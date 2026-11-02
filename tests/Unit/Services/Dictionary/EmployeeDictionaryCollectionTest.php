<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dictionary;

use App\Livewire\Employee\EmployeeComponent;
use App\Livewire\Employee\Forms\EmployeeForm;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Services\Dictionary\Collections\EmployeeDictionaryCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\FakesEmployeeDictionaries;
use Tests\TestCase;

class EmployeeDictionaryCollectionTest extends TestCase
{
    use FakesEmployeeDictionaries;

    public function test_choices_intersect_the_mapping_with_active_codes_and_labels_keep_retired_codes(): void
    {
        config(['ehealth.employee_type.SPECIALIST.speciality_type' => ['THERAPIST', 'RETIRED', 'MISSING']]);
        $catalogue = new EmployeeDictionaryCollection([[
            'name' => 'SPECIALITY_TYPE',
            'values' => [
                ['code' => 'THERAPIST', 'description' => 'Терапія', 'is_active' => true],
                ['code' => 'NURSING', 'description' => 'Сестринська справа', 'is_active' => true],
                ['code' => 'RETIRED', 'description' => 'Історична спеціальність', 'is_active' => false],
            ],
        ]]);

        $this->assertSame(['THERAPIST' => 'Терапія'], $catalogue->optionsForEmployeeType('SPECIALIST', 'SPECIALITY_TYPE'));
        $this->assertSame('Історична спеціальність', $catalogue->labels(['SPECIALITY_TYPE'])['SPECIALITY_TYPE']['RETIRED']);
        $this->assertSame([], $catalogue->optionsForEmployeeType('UNKNOWN', 'SPECIALITY_TYPE'));
        $this->assertSame([], $catalogue->activeOptions('MISSING_DICTIONARY'));
    }

    public function test_legal_entity_type_filters_roles_before_professional_options_are_built(): void
    {
        $catalogue = $this->fakeEmployeeDictionaries();
        $options = $catalogue->optionsByEmployeeType('OUTPATIENT', 'SPECIALITY_TYPE');
        $this->assertArrayHasKey('SPECIALIST', $options);
        $this->assertArrayHasKey('ASSISTANT', $options);
        $this->assertArrayNotHasKey('DOCTOR', $options);
        $this->assertArrayNotHasKey('PHARMACIST', $options);
        $this->assertSame([], $catalogue->employeeTypes('UNKNOWN'));
    }

    public function test_a_retired_dictionary_has_labels_but_no_selectable_values(): void
    {
        $catalogue = new EmployeeDictionaryCollection([[
            'name' => 'SCIENCE_DEGREE', 'is_active' => false,
            'values' => [['code' => 'PHD', 'description' => 'PhD', 'is_active' => true]],
        ]]);
        $this->assertSame([], $catalogue->activeOptions('SCIENCE_DEGREE'));
        $this->assertSame(['PHD' => 'PhD'], $catalogue->labels(['SCIENCE_DEGREE'])['SCIENCE_DEGREE']);
    }

    public function test_updated_mapping_covers_published_specialities_for_each_medical_role(): void
    {
        $newSpecialistCodes = ['INTERNAL_DISEASES', 'CLINICAL_ONCOLOGY', 'RADIATION_ONCOLOGY', 'NUCLEAR_MEDICINE', 'TRANSPLANT_COORDINATION'];
        foreach (['SPECIALIST', 'MED_COORDINATOR'] as $role) {
            foreach ($newSpecialistCodes as $code) {
                $this->assertContains($code, config("ehealth.employee_type.{$role}.speciality_type"));
            }
        }
        foreach (['ANESTHESIOLOGY_AND_RESUSCITATION', 'OPTOMETRY', 'PROSTHETICS_AND_ORTHOTICS', 'TRAUMATOLOGY_AND_ORTHOPEDICS', 'DENTISTRY_ORTHOPEDIC', 'LABORATORY_DIAGNOSTICS', 'PREVENTIVE_DENTISTRY'] as $code) {
            $this->assertContains($code, config('ehealth.employee_type.ASSISTANT.speciality_type'));
        }
        foreach (['CLINICAL_DIAGNOSTICS', 'LABORATORY_DIAGNOSTICS_VIROLOGY_MICROBIOLOGY', 'LABORATORY_DIAGNOSTICS'] as $code) {
            $this->assertContains($code, config('ehealth.employee_type.LABORANT.speciality_type'));
        }
    }

    public function test_form_rejects_configured_codes_absent_from_the_canonical_dictionary(): void
    {
        $this->fakeEmployeeDictionaries(['SPECIALITY_TYPE' => ['THERAPIST' => 'Терапія']]);
        $form = $this->form();
        $form->employeeType = 'SPECIALIST';
        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($form);

        $valid = Validator::make(['doctor' => ['specialities' => [['speciality' => 'THERAPIST']]]], [
            'doctor.specialities.*.speciality' => $rules['doctor.specialities.*.speciality'],
        ]);
        $missing = Validator::make(['doctor' => ['specialities' => [['speciality' => 'NUCLEAR_MEDICINE']]]], [
            'doctor.specialities.*.speciality' => $rules['doctor.specialities.*.speciality'],
        ]);
        $this->assertTrue($valid->passes());
        $this->assertTrue($missing->fails());
    }

    public function test_public_dictionary_cannot_extend_the_servers_role_or_degree_rules(): void
    {
        $this->fakeEmployeeDictionaries();
        $legalEntity = new LegalEntity();
        $legalEntity->setRelation('type', new LegalEntityType(['name' => 'OUTPATIENT']));
        $this->instance('legalEntity', $legalEntity);
        $form = $this->form();
        $form->employeeType = 'SPECIALIST';
        $rules = (new ReflectionMethod(EmployeeForm::class, 'rootFieldsRules'))->invoke($form);
        $this->assertTrue(Validator::make(['employeeType' => 'FORGED'], ['employeeType' => $rules['employeeType']])->fails());
        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($form);
        $this->assertTrue(Validator::make(['doctor' => ['scienceDegree' => ['degree' => 'FORGED']]], [
            'doctor.scienceDegree.degree' => $rules['doctor.scienceDegree.degree'],
        ])->fails());
    }

    public function test_document_speciality_text_does_not_need_a_certificate_speciality_code(): void
    {
        $this->fakeEmployeeDictionaries();
        $form = $this->form();
        $form->employeeType = 'SPECIALIST';
        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($form);
        $text = 'Медична біохімія (освітня програма)';
        foreach (['educations', 'qualifications'] as $group) {
            $this->assertTrue(Validator::make(['doctor' => [$group => [['speciality' => $text]]]], [
                "doctor.{$group}.*.speciality" => $rules["doctor.{$group}.*.speciality"],
            ])->passes());
        }
        $this->assertTrue(Validator::make(['doctor' => ['scienceDegree' => ['speciality' => $text]]], [
            'doctor.scienceDegree.speciality' => $rules['doctor.scienceDegree.speciality'],
        ])->passes());
    }

    public function test_livewire_locks_dictionary_payload_and_does_not_serialize_role_option_copies(): void
    {
        $component = Livewire::test(EmployeeDictionaryStateProbe::class);
        $state = $component->snapshot['data'];
        foreach (['employeeTypePosition', 'employeeTypeSpecialities', 'employeeTypeLevels', 'employeeTypeDegrees', 'employeeTypeQualifications', 'employeeTypeSpecQualifications'] as $name) {
            $this->assertArrayNotHasKey($name, $state);
        }

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('dictionaries.EMPLOYEE_TYPE.FORGED', 'Forged');
    }

    public function test_select_component_renders_an_explicit_options_provider_and_watch_path(): void
    {
        $html = Blade::render('<x-select2 dictionary-name="SPECIALITY_TYPE" model-path="modalSpeciality.speciality" options="choices[employeeType] ?? {}" options-watch="form.employeeType" id="speciality" />');

        $this->assertStringContainsString('choices[employeeType] ?? {}', $html);
        $this->assertStringContainsString('form.employeeType', $html);
        $this->assertStringNotContainsString('options-watch=', $html);
        $this->assertStringContainsString('id="speciality"', $html);
    }

    public function test_missing_cache_renders_empty_choices_and_flashes_an_error_without_opening_validation(): void
    {
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('employees')->andReturn(new EmployeeDictionaryCollection());
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        $component = new EmployeeDictionaryStateProbe();
        $component->loadDictionaries();

        $this->assertSame([], $component->dictionaries['SPECIALITY_TYPE']);
        $this->assertSame([], $component->dictionaries['EMPLOYEE_TYPE']);
        $this->assertSame(__('employees.dictionaries_unavailable'), session('error'));
        $form = new EmployeeForm($component, 'form');
        $form->employeeType = 'SPECIALIST';
        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($form);
        $this->assertTrue(Validator::make(['doctor' => ['specialities' => [['speciality' => 'THERAPIST']]]], [
            'doctor.specialities.*.speciality' => $rules['doctor.specialities.*.speciality'],
        ])->fails());
    }

    private function form(): EmployeeForm
    {
        $component = new class extends Component
        {
            public array $dictionaries = ['EMPLOYEE_TYPE' => ['FORGED' => 'Forged'], 'SCIENCE_DEGREE' => ['FORGED' => 'Forged']];

            public function render(): string
            {
                return '<div></div>';
            }
        };

        return new EmployeeForm($component, 'form');
    }
}

class EmployeeDictionaryStateProbe extends EmployeeComponent
{
    public function render(): string
    {
        return '<div></div>';
    }
}

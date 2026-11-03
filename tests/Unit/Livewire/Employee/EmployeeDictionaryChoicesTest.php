<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Livewire\Employee\EmployeeComponent;
use App\Livewire\Employee\Forms\EmployeeForm;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\FakesEmployeeDictionaries;
use Tests\TestCase;

class EmployeeDictionaryChoicesTest extends TestCase
{
    use FakesEmployeeDictionaries;

    public function test_choices_intersect_the_mapping_with_active_codes_and_labels_keep_retired_codes(): void
    {
        config(['ehealth.employee_type.SPECIALIST.speciality_type' => ['THERAPIST', 'RETIRED', 'MISSING']]);
        $catalogue = new BasicDictionaryCollection([[
            'name' => 'SPECIALITY_TYPE',
            'values' => [
                ['code' => 'THERAPIST', 'description' => 'Терапія', 'is_active' => true],
                ['code' => 'NURSING', 'description' => 'Сестринська справа', 'is_active' => true],
                ['code' => 'RETIRED', 'description' => 'Історична спеціальність', 'is_active' => false],
            ],
        ]]);

        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($catalogue);
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        $form = $this->form();
        $this->assertSame(['THERAPIST' => 'Терапія'], $form->optionsForEmployeeType('SPECIALIST', 'SPECIALITY_TYPE'));
        $this->assertSame('Історична спеціальність', $catalogue->getMultipleFormatted(['SPECIALITY_TYPE'], onlyActive: false)['SPECIALITY_TYPE']['RETIRED']);
        $this->assertSame([], $form->optionsForEmployeeType('UNKNOWN', 'SPECIALITY_TYPE'));
        $this->assertSame([], $form->activeOptions('MISSING_DICTIONARY'));
    }

    public function test_legal_entity_type_filters_roles_before_professional_options_are_built(): void
    {
        $this->fakeEmployeeDictionaries();
        $legalEntity = new LegalEntity();
        $legalEntity->setRelation('type', new LegalEntityType(['name' => 'OUTPATIENT']));
        $this->instance('legalEntity', $legalEntity);
        $form = $this->form();
        $options = $form->optionsByEmployeeType('SPECIALITY_TYPE');
        $this->assertArrayHasKey('SPECIALIST', $options);
        $this->assertArrayHasKey('ASSISTANT', $options);
        $this->assertArrayNotHasKey('DOCTOR', $options);
        $this->assertArrayNotHasKey('PHARMACIST', $options);
        $legalEntity->type->name = 'UNKNOWN';
        $this->assertSame([], $form->employeeTypes());
    }

    public function test_a_retired_dictionary_has_labels_but_no_selectable_values(): void
    {
        $catalogue = new BasicDictionaryCollection([[
            'name' => 'SCIENCE_DEGREE', 'is_active' => false,
            'values' => [['code' => 'PHD', 'description' => 'PhD', 'is_active' => true]],
        ]]);
        $this->assertSame([], $catalogue->byName('SCIENCE_DEGREE')->asCodeDescription()->all());
        $this->assertSame(['PHD' => 'PhD'], $catalogue->getMultipleFormatted(['SCIENCE_DEGREE'], onlyActive: false)['SCIENCE_DEGREE']->all());
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

    public function test_select_component_renders_a_single_reactive_options_provider(): void
    {
        $html = Blade::render('<x-select2 dictionary-name="SPECIALITY_TYPE" model-path="modalSpeciality.speciality" options="choices[employeeType] ?? {}" id="speciality" />');

        $this->assertStringContainsString('choices[employeeType] ?? {}', $html);
        $this->assertStringNotContainsString('options-watch=', $html);
        $this->assertStringContainsString('id="speciality"', $html);
    }

    public function test_missing_cache_renders_empty_choices_and_flashes_an_error_without_opening_validation(): void
    {
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn(new BasicDictionaryCollection());
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        $component = new EmployeeDictionaryStateProbe();
        $component->form = new EmployeeForm($component, 'form');
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

    public function test_science_degree_select_and_validation_offer_only_active_codes_but_keep_historical_labels(): void
    {
        $catalogue = $this->fakeEmployeeDictionaries();
        $dictionary = $catalogue->firstWhere('name', 'SCIENCE_DEGREE');
        $dictionary['values'][] = ['code' => 'RETIRED', 'description' => 'Історичний ступінь', 'is_active' => false];
        $catalogue->transform(fn (array $item): array => $item['name'] === 'SCIENCE_DEGREE' ? $dictionary : $item);
        $component = Livewire::test(EmployeeScienceDegreeStateProbe::class);
        $this->assertSame('Історичний ступінь', $component->get('dictionaries')['SCIENCE_DEGREE']['RETIRED']);
        $html = $component->html();
        preg_match('/<select[^>]*id="scienceDegreeDegree"[^>]*>(.*?)<\/select>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertStringContainsString('value="PHD"', $matches[1]);
        $this->assertStringNotContainsString('value="RETIRED"', $matches[1]);
        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($component->instance()->form);
        foreach (['PHD' => true, 'RETIRED' => false] as $degree => $valid) {
            $this->assertSame($valid, Validator::make(['doctor' => ['scienceDegree' => ['degree' => $degree]]], [
                'doctor.scienceDegree.degree' => $rules['doctor.scienceDegree.degree'],
            ])->passes());
        }
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

class EmployeeScienceDegreeStateProbe extends EmployeeComponent
{
    public function mount(): void
    {
        $this->loadDictionaries();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.employee.parts.science_degree');
    }
}

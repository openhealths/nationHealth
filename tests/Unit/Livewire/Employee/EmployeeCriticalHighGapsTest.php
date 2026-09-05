<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Enums\Employee\RequestStatus;
use App\Livewire\Employee\EmployeeComponent;
use Illuminate\View\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeCriticalHighGapsTest extends TestCase
{
    #[Test]
    public function sign_success_mentions_email_invitation(): void
    {
        $message = __('employees.sign_success');

        $this->assertStringContainsString('eHealth', $message);
        $this->assertStringContainsString('запрошення', $message);
        $this->assertStringContainsString('електронну пошту', $message);
    }

    #[Test]
    public function medical_employees_config_covers_tz_professional_data_types(): void
    {
        $medical = config('ehealth.medical_employees');

        foreach ([
            'DOCTOR',
            'ASSISTANT',
            'SPECIALIST',
            'LABORANT',
            'MED_COORDINATOR',
            'MED_ADMIN',
            'PHARMACIST',
        ] as $type) {
            $this->assertContains($type, $medical);
        }

        $this->assertSame(['PHARMACIST', 'PHARMACY_OWNER'], config('ehealth.pharmacy_employee_types'));
    }

    #[Test]
    public function position_blade_locks_core_fields_via_is_core_position_data_locked(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/position.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('isCorePositionDataLocked', $blade);
        $this->assertStringContainsString(
            ':disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"',
            $blade
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="division"[^>]*:disabled=/',
            $blade
        );
    }

    #[Test]
    public function employee_show_gates_professional_blocks_by_medical_employees(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/employee-show.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString("config('ehealth.medical_employees'", $blade);
        $this->assertStringNotContainsString('Role::DOCTOR', $blade);
    }

    #[Test]
    public function prepare_for_signing_opens_preview_before_kep(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/AbstractEmployeeFormManager.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString("dispatch('open-request-preview-modal')", $source);
        $this->assertStringContainsString('function proceedToSigning', $source);
        $this->assertStringContainsString("dispatch('open-signature-modal')", $source);
    }

    #[Test]
    public function request_preview_modal_view_exists(): void
    {
        $this->assertFileExists(
            resource_path('views/livewire/employee/parts/modals/request-preview-modal.blade.php')
        );
        $this->assertSame(
            'Перегляд запиту перед підписанням',
            __('forms.employee_request_preview_title')
        );
    }

    #[Test]
    public function request_preview_modal_shows_ukrainian_status_label(): void
    {
        $blade = file_get_contents(
            resource_path('views/livewire/employee/parts/modals/request-preview-modal.blade.php')
        );

        $this->assertNotFalse($blade);
        $this->assertStringNotContainsString('>NEW<', $blade);
        $this->assertStringContainsString('previewRequestStatusLabel()', $blade);
        $this->assertStringContainsString('working_experience', $blade);
        $this->assertStringContainsString('issuedAt', $blade);
        $this->assertSame('Новий', RequestStatus::NEW->label());
    }

    #[Test]
    public function signature_modal_restores_key_file_name_after_reopen(): void
    {
        $blade = file_get_contents(
            resource_path('views/livewire/employee/parts/modals/signature-modal.blade.php')
        );

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('keyContainerFileName', $blade);
        $this->assertStringContainsString('syncFileNameFromWire', $blade);
        $this->assertStringContainsString(
            "x-effect=\"if (!showSignatureModal) { if (\$refs.keyContainerUpload) \$refs.keyContainerUpload.value = ''; } else { syncFileNameFromWire(); }\"",
            $blade
        );
        $this->assertTrue(
            (new \ReflectionClass(\App\Livewire\Employee\Forms\EmployeeForm::class))
                ->hasProperty('keyContainerFileName')
        );
    }

    #[Test]
    public function employee_component_exposes_preview_and_core_lock_flags(): void
    {
        $component = new class extends EmployeeComponent
        {
            public function render(): View|string
            {
                return '';
            }
        };

        $this->assertFalse($component->showRequestPreviewModal);
        $this->assertFalse($component->isCorePositionDataLocked);
        $this->assertFalse($component->isPositionDataLocked);
    }

    #[Test]
    public function sign_success_flashes_and_redirects_to_employee_index(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/AbstractEmployeeFormManager.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString("flashSuccess(__('employees.sign_success'))", $source);
        $this->assertStringContainsString("redirectRoute('employee.index', [legalEntity()], navigate: true)", $source);
        $this->assertStringNotContainsString("return redirect()->route('employee.index'", $source);
    }

    #[Test]
    public function success_flash_banner_keeps_session_for_redirect_render(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/components/x-message.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringNotContainsString("session()->forget('success')", $blade);
        $this->assertStringContainsString('border-green-200', $blade);
    }

    #[Test]
    public function employee_pages_do_not_mount_a_second_flash_component(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $employeeForm = file_get_contents(resource_path('views/livewire/employee/employee.blade.php'));
        $employeeIndex = file_get_contents(resource_path('views/livewire/employee/employee-index.blade.php'));

        $this->assertNotFalse($layout);
        $this->assertNotFalse($employeeForm);
        $this->assertNotFalse($employeeIndex);
        $this->assertStringContainsString("@livewire('components.flash-message')", $layout);
        $this->assertStringNotContainsString('livewire:components.x-message', $employeeForm);
        $this->assertStringNotContainsString('livewire:components.x-message', $employeeIndex);
    }

    #[Test]
    public function science_degree_avoids_entangle_on_object_to_prevent_to_json_rpc(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/science_degree.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringNotContainsString("\$wire.entangle('form.doctor.scienceDegree')", $blade);
        $this->assertStringContainsString("\$wire.get('form.doctor.scienceDegree')", $blade);
        $this->assertStringContainsString("\$wire.set(", $blade);
        $this->assertStringContainsString('scienceDegree = { ...modalScienceDegree }', $blade);
        $this->assertStringContainsString('scienceDegree = {}', $blade);
    }

    #[Test]
    public function employee_module_has_no_live_entangle_and_stores_plain_objects_in_arrays(): void
    {
        $files = [
            resource_path('views/livewire/employee/parts/science_degree.blade.php'),
            resource_path('views/livewire/employee/parts/documents.blade.php'),
            resource_path('views/livewire/employee/parts/qualifications.blade.php'),
            resource_path('views/livewire/employee/parts/education.blade.php'),
            resource_path('views/livewire/employee/parts/specialities.blade.php'),
            resource_path('views/livewire/employee/parts/party.blade.php'),
            resource_path('views/components/forms/multiselect.blade.php'),
        ];

        foreach ($files as $file) {
            $blade = file_get_contents($file);
            $this->assertNotFalse($blade, $file);
            $this->assertDoesNotMatchRegularExpression(
                '/\$wire\.entangle\([^)]+\)\.live/',
                $blade,
                'Live entangle on object/array triggers toJSON RPC: ' . $file
            );
        }

        $multiselect = file_get_contents(resource_path('views/components/forms/multiselect.blade.php'));
        $this->assertStringNotContainsString("selected: \$wire.entangle", $multiselect);
        $this->assertStringContainsString("\$wire.get('{{ \$bind }}')", $multiselect);
        $this->assertStringContainsString("\$wire.set('{{ \$bind }}'", $multiselect);

        $documents = file_get_contents(resource_path('views/livewire/employee/parts/documents.blade.php'));
        $this->assertStringNotContainsString("\$wire.entangle('form.documents')", $documents);
        $this->assertStringContainsString("\$wire.get('form.documents')", $documents);
        $this->assertStringContainsString('documents.push({ ...modalDocument })', $documents);

        $educations = file_get_contents(resource_path('views/livewire/employee/parts/education.blade.php'));
        $this->assertStringNotContainsString("\$wire.entangle('form.doctor.educations')", $educations);
        $this->assertStringContainsString('educations.push({ ...modalEducation })', $educations);

        $qualifications = file_get_contents(resource_path('views/livewire/employee/parts/qualifications.blade.php'));
        $this->assertStringNotContainsString("\$wire.entangle('form.doctor.qualifications')", $qualifications);
        $this->assertStringContainsString('qualifications.push({ ...modalQualification })', $qualifications);

        $specialities = file_get_contents(resource_path('views/livewire/employee/parts/specialities.blade.php'));
        $this->assertStringNotContainsString("\$wire.entangle('form.doctor.specialities')", $specialities);
        $this->assertStringContainsString('this.specialities.push({ ...this.modalSpeciality })', $specialities);

        $party = file_get_contents(resource_path('views/livewire/employee/parts/party.blade.php'));
        $this->assertStringNotContainsString("\$wire.entangle('form.party')", $party);
        $this->assertStringNotContainsString("\$wire.entangle('form.party.phones')", $party);
        $this->assertStringContainsString("\$wire.get('form.party.phones')", $party);
    }

    #[Test]
    public function employee_index_multiselect_passes_initial_status_without_entangle(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/employee-index.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString(':initial="$status"', $blade);
        $this->assertStringContainsString('bind="status"', $blade);
    }

    #[Test]
    public function request_preview_modal_shows_full_science_degree_fields(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/modals/request-preview-modal.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString("\$scienceDegree['institutionName']", $blade);
        $this->assertStringContainsString("\$scienceDegree['diplomaNumber']", $blade);
        $this->assertStringContainsString("\$scienceDegree['issuedDate']", $blade);
        $this->assertStringContainsString("\$scienceDegree['city']", $blade);
        $this->assertStringNotContainsString(
            "\$this->form->doctor['scienceDegree']['degree'] ?? null;",
            $blade
        );
    }

    #[Test]
    public function qualifications_section_label_is_professional_development_wording(): void
    {
        $this->assertSame('Підвищення кваліфікації', __('forms.qualifications'));

        $blade = file_get_contents(resource_path('views/livewire/employee/parts/qualifications.blade.php'));
        $preview = file_get_contents(resource_path('views/livewire/employee/parts/modals/request-preview-modal.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertNotFalse($preview);
        $this->assertStringContainsString("__('forms.qualifications')", $blade);
        $this->assertStringContainsString("__('forms.qualifications')", $preview);
    }

    #[Test]
    public function employee_index_query_does_not_include_employee_requests(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/EmployeeIndex.php'));
        $repository = file_get_contents(app_path('Repositories/EmployeeRepository.php'));

        $this->assertNotFalse($source);
        $this->assertNotFalse($repository);
        $this->assertStringNotContainsString("orWhereHas('employeeRequests'", $source);
        $this->assertStringNotContainsString("\$party->employeeRequests", $source);
        $this->assertStringNotContainsString("'employeeRequests' =>", $repository);
    }

    #[Test]
    public function qualifications_additional_info_is_optional_string_without_cyrillic_rule(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/Forms/EmployeeForm.php'));

        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            "/'doctor\\.qualifications\\.\\*\\.additionalInfo'\\s*=>\\s*\\['nullable',\\s*'string',\\s*'max:255'\\]/",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'doctor\\.qualifications\\.\\*\\.additionalInfo'\\s*=>\\s*\\[[^\\]]*Cyrillic/",
            $source
        );
    }

    #[Test]
    public function employee_professional_free_text_fields_allow_digits_without_cyrillic_rule(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/Forms/EmployeeForm.php'));

        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('use App\\Rules\\Cyrillic', $source);

        foreach ([
            "doctor.educations.*.city",
            "doctor.educations.*.institutionName",
            "doctor.scienceDegree.city",
            "doctor.scienceDegree.institutionName",
            "doctor.qualifications.*.institutionName",
            "doctor.specialities.*.attestationName",
        ] as $field) {
            $quoted = preg_quote($field, '/');
            $this->assertDoesNotMatchRegularExpression(
                "/'{$quoted}'\\s*=>\\s*\\[[^\\]]*Cyrillic/",
                $source,
                "Field {$field} must not use Cyrillic rule (ESOZ allows digits/symbols)."
            );
        }
    }
}

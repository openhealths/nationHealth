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
}

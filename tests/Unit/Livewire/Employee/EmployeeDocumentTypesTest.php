<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Livewire\Employee\EmployeeComponent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

class EmployeeDocumentTypesTest extends TestCase
{
    public function test_employee_identity_document_types_match_ehealth_chart_parameter(): void
    {
        $allowed = config('ehealth.employee_identity_document_types');

        $this->assertCount(7, $allowed);
        $this->assertContains('PASSPORT', $allowed);
        $this->assertContains('NATIONAL_ID', $allowed);
        $this->assertNotContains('BIRTH_CERTIFICATE', $allowed);
        $this->assertNotContains('MARRIAGE_CERTIFICATE', $allowed);
    }

    public function test_birth_certificate_is_rejected_for_employee_documents(): void
    {
        $allowed = config('ehealth.employee_identity_document_types');

        $validator = Validator::make(
            ['type' => 'BIRTH_CERTIFICATE'],
            [
                'type' => ['required', 'string', Rule::in($allowed)],
            ],
            [
                'type.in' => __('validation.custom.employee.document_type_not_allowed'),
            ]
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            'Свідоцтво про народження',
            $validator->errors()->first('type')
        );
    }

    public function test_filter_employee_document_types_limits_dictionary_to_allowed_types(): void
    {
        $component = new class extends EmployeeComponent
        {
            public function render()
            {
                return '';
            }

            public function applyDocumentFilter(array $masterDictionary): array
            {
                $this->dictionaries['DOCUMENT_TYPE'] = $masterDictionary;
                $this->filterEmployeeDocumentTypes();

                return $this->dictionaries['DOCUMENT_TYPE'];
            }
        };

        $filtered = $component->applyDocumentFilter([
            'PASSPORT' => 'Паспорт',
            'BIRTH_CERTIFICATE' => 'Свідоцтво про народження',
            'MARRIAGE_CERTIFICATE' => 'Свідоцтво про шлюб',
            'NATIONAL_ID' => 'ID-картка',
        ]);

        $this->assertSame(['PASSPORT' => 'Паспорт', 'NATIONAL_ID' => 'ID-картка'], $filtered);
    }
}

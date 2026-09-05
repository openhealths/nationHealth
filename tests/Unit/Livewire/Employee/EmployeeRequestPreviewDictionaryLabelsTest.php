<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestPreviewDictionaryLabelsTest extends TestCase
{
    #[Test]
    public function kep_preview_resolves_professional_block_labels_via_dictionaries(): void
    {
        $blade = file_get_contents(
            resource_path('views/livewire/employee/parts/modals/request-preview-modal.blade.php')
        );

        $this->assertNotFalse($blade);
        $this->assertStringContainsString("dictionaries['SPECIALITY_TYPE']", $blade);
        $this->assertStringContainsString("dictionaries['QUALIFICATION_TYPE']", $blade);
        $this->assertStringContainsString("dictionaries['EDUCATION_DEGREE']", $blade);
        $this->assertStringContainsString("dictionaries['SPECIALITY_LEVEL']", $blade);
        $this->assertStringContainsString("dictionaries['SCIENCE_DEGREE']", $blade);
        $this->assertStringContainsString("dictionaries['SPEC_QUALIFICATION_TYPE']", $blade);
    }
}

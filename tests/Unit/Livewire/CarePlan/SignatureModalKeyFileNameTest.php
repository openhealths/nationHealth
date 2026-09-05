<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\CarePlan;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignatureModalKeyFileNameTest extends TestCase
{
    #[Test]
    public function shared_signature_modal_shows_selected_key_file_name_via_alpine(): void
    {
        $blade = file_get_contents(resource_path('views/components/signature-modal.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('keyContainerFileName', $blade);
        $this->assertStringContainsString('setFileNameFromInput', $blade);
        $this->assertStringContainsString('syncFileNameFromWire', $blade);
        $this->assertStringContainsString('x-text="displayFileName()"', $blade);
        $this->assertStringContainsString('@change="setFileNameFromInput($event)"', $blade);
    }

    #[Test]
    public function care_plan_form_tracks_key_container_file_name(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(CarePlanForm::class))->hasProperty('keyContainerFileName')
        );
    }
}

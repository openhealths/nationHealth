<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\CarePlan;

use App\Core\BaseForm;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignatureModalKeyFileNameTest extends TestCase
{
    #[Test]
    public function signature_fields_read_the_key_in_the_browser_instead_of_uploading_it(): void
    {
        $fields = file_get_contents(resource_path('views/components/signature-fields.blade.php'));

        $this->assertNotFalse($fields);
        $this->assertStringContainsString('@change="readKeyContainer($event)"', $fields);
        $this->assertStringContainsString('x-text="chosenKeyContainerName"', $fields);
        $this->assertStringNotContainsString('wire:model="{{ $model }}.keyContainer', $fields);
    }

    #[Test]
    public function shared_signature_modal_renders_the_signature_fields(): void
    {
        $modal = file_get_contents(resource_path('views/components/signature-modal.blade.php'));

        $this->assertNotFalse($modal);
        $this->assertStringContainsString('<x-signature-fields />', $modal);
    }

    #[Test]
    public function care_plan_form_takes_the_signing_fields_from_the_base_form(): void
    {
        $this->assertTrue(is_subclass_of(CarePlanForm::class, BaseForm::class));
    }
}

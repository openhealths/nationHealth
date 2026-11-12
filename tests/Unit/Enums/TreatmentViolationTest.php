<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Livewire\Person\Records\PatientCompositions;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class TreatmentViolationTest extends TestCase
{
    use UsesCompositionDictionaries;

    public function test_resolves_known_codes_to_ukrainian_descriptions(): void
    {
        $this->assertSame(
            'відмова від госпіталізації',
            (new PatientCompositions)->treatmentViolationLabel('reject_hospitalization')
        );
    }

    public function test_returns_the_raw_code_when_unknown(): void
    {
        $this->assertSame(
            'unknown_violation_code',
            (new PatientCompositions)->treatmentViolationLabel('unknown_violation_code')
        );
    }

    public function test_blank_codes_render_as_a_dash(): void
    {
        $this->assertSame('-', (new PatientCompositions)->treatmentViolationLabel(null));
        $this->assertSame('-', (new PatientCompositions)->treatmentViolationLabel(''));
    }
}

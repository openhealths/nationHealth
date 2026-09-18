<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\TestCase;

class CompositionTreatmentViolationLabelTest extends TestCase
{
    public function test_resolves_known_codes_to_ukrainian_descriptions(): void
    {
        $this->assertSame(
            'відмова від госпіталізації',
            compositionTreatmentViolationLabel('reject_hospitalization')
        );
    }

    public function test_returns_the_raw_code_when_unknown(): void
    {
        $this->assertSame(
            'unknown_violation_code',
            compositionTreatmentViolationLabel('unknown_violation_code')
        );
    }

    public function test_blank_codes_render_as_a_dash(): void
    {
        $this->assertSame('-', compositionTreatmentViolationLabel(null));
        $this->assertSame('-', compositionTreatmentViolationLabel(''));
    }
}

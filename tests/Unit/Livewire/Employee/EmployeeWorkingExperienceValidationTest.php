<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class EmployeeWorkingExperienceValidationTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function workingExperienceRules(): array
    {
        return [
            'party.workingExperience' => ['required', 'integer', 'gt:0'],
        ];
    }

    public function test_working_experience_rejects_empty_value(): void
    {
        $validator = Validator::make(
            ['party' => ['workingExperience' => null]],
            $this->workingExperienceRules()
        );

        $this->assertTrue($validator->fails());
    }

    public function test_working_experience_rejects_zero(): void
    {
        $validator = Validator::make(
            ['party' => ['workingExperience' => 0]],
            $this->workingExperienceRules()
        );

        $this->assertTrue($validator->fails());
    }

    public function test_working_experience_rejects_negative_values(): void
    {
        $validator = Validator::make(
            ['party' => ['workingExperience' => -3]],
            $this->workingExperienceRules()
        );

        $this->assertTrue($validator->fails());
    }

    public function test_working_experience_accepts_positive_integer(): void
    {
        $validator = Validator::make(
            ['party' => ['workingExperience' => 5]],
            $this->workingExperienceRules()
        );

        $this->assertFalse($validator->fails());
    }
}

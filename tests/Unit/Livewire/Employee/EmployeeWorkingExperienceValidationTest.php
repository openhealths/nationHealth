<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Livewire\Employee\Forms\EmployeeForm;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeWorkingExperienceValidationTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function workingExperienceRules(): array
    {
        $form = new EmployeeForm(new class extends Component
        {
            public function render(): string
            {
                return '';
            }
        }, 'form');

        $rules = (new ReflectionMethod(EmployeeForm::class, 'partyRules'))->invoke($form);

        return [
            'party.workingExperience' => $rules['party.workingExperience'],
        ];
    }

    public function test_working_experience_accepts_empty_value(): void
    {
        $validator = Validator::make(
            ['party' => ['workingExperience' => null]],
            $this->workingExperienceRules()
        );

        $this->assertFalse($validator->fails());
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

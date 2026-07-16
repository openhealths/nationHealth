<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\EHealth\EHealthValidationException;
use Tests\TestCase;

class EHealthValidationExceptionSpecialityMismatchTest extends TestCase
{
    public function test_speciality_mismatch_message_is_translated(): void
    {
        $exception = new EHealthValidationException([
            'error' => [
                'invalid' => [
                    [
                        'entry' => '$.employee_role',
                        'rules' => [
                            [
                                'description' => 'employee speciality mismatch for healthcare service',
                                'rule' => 'invalid',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $translated = $exception->getTranslatedMessage();

        $this->assertStringContainsString(
            __('validation.attributes.employeeRole.constraint.specialityMismatch'),
            $translated
        );
    }

    public function test_speciality_not_allowed_message_uses_mismatch_translation(): void
    {
        $exception = new EHealthValidationException([
            'error' => [
                'invalid' => [
                    [
                        'entry' => '$.employee_role',
                        'rules' => [
                            [
                                'description' => 'speciality THERAPIST not_allowed for this role',
                                'rule' => 'invalid',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $translated = $exception->getTranslatedMessage();

        $this->assertStringContainsString(
            __('validation.attributes.employeeRole.constraint.specialityMismatch'),
            $translated
        );
    }
}

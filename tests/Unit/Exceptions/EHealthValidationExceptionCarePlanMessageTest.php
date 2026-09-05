<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\EHealth\EHealthValidationException;
use Tests\TestCase;

class EHealthValidationExceptionCarePlanMessageTest extends TestCase
{
    public function test_already_cancelled_message_is_translated(): void
    {
        $exception = new EHealthValidationException([
            'error' => [
                'message' => 'Care plan in status cancelled cannot be cancelled',
            ],
        ]);

        $this->assertTrue($exception->isCarePlanAlreadyCancelled());
        $this->assertStringContainsString(
            __('errors.ehealth.messages.care_plan_cannot_cancel_in_status', [
                'status' => __('care-plan.status.cancelled'),
            ]),
            $exception->getFormattedMessage()
        );
        $this->assertStringNotContainsString('cannot be cancelled', $exception->getFormattedMessage());
    }

    public function test_unfinished_activities_message_stays_translated(): void
    {
        $exception = new EHealthValidationException([
            'error' => [
                'message' => 'Care plan has unfinished activities',
            ],
        ]);

        $this->assertStringContainsString(
            __('errors.ehealth.messages.care_plan_has_unfinished_activities'),
            $exception->getFormattedMessage()
        );
    }

    public function test_category_mismatch_message_is_translated_with_explanation(): void
    {
        $exception = new EHealthValidationException([
            'error' => [
                'type' => 'validation_failed',
                'invalid' => [
                    [
                        'entry' => '$.code.identifier.value',
                        'entry_type' => 'json_data_property',
                        'rules' => [
                            [
                                'description' => 'Category mismatch',
                                'rule' => 'invalid',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $message = $exception->getFormattedMessage();
        $this->assertStringContainsString('Категорія послуги', $message);
        $this->assertStringContainsString('не відповідає вказаній категорії в ЕСОЗ', $message);
        $this->assertStringNotContainsString('Category mismatch', $message);
    }
}

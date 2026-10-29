<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\MedicalEvents\ApprovalVerificationException;
use Tests\TestCase;

class ApprovalVerificationExceptionTest extends TestCase
{
    public function test_exception_without_arguments_uses_a_general_message(): void
    {
        $exception = new ApprovalVerificationException();

        $this->assertSame(__('patients.errors.approval_verification_failed'), $exception->getMessage());
    }

    public function test_unrecognized_error_type_uses_a_general_message(): void
    {
        $exception = new ApprovalVerificationException(999);

        $this->assertSame(__('patients.errors.approval_verification_failed'), $exception->getMessage());
    }

    public function test_custom_message_can_be_used_without_an_error_type(): void
    {
        $exception = new ApprovalVerificationException(message: 'Custom approval verification message.');

        $this->assertSame('Custom approval verification message.', $exception->getMessage());
    }

    public function test_custom_message_overrides_the_default_message(): void
    {
        $exception = new ApprovalVerificationException(
            ApprovalVerificationException::NOT_FOUND,
            message: 'Custom approval lookup message.'
        );

        $this->assertSame('Custom approval lookup message.', $exception->getMessage());
    }

    public function test_custom_creation_failure_message_preserves_translated_error_details(): void
    {
        $error = ['type' => 'validation_failed', 'invalid' => [[
            'entry' => '$.granted_to.identifier.value',
            'rules' => [['description' => 'Invalid employee type']],
        ]]];
        $exception = new ApprovalVerificationException(
            ApprovalVerificationException::CREATION_FAILED,
            error: $error,
            message: 'Custom creation failure message.'
        );

        $this->assertStringStartsWith('Custom creation failure message. ', $exception->getMessage());
        $this->assertStringContainsString(__('errors.ehealth.messages.approval_invalid_employee_type'), $exception->getMessage());
        $this->assertStringNotContainsString(__('patients.errors.approval_not_created'), $exception->getMessage());
    }

    public function test_empty_errors_do_not_add_details(): void
    {
        foreach ([null, []] as $error) {
            $exception = new ApprovalVerificationException(ApprovalVerificationException::CREATION_FAILED, error: $error);

            $this->assertSame(__('patients.errors.approval_not_created'), $exception->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions\MedicalEvents;

use Exception;
use App\Exceptions\EHealth\EHealthValidationException;

class ApprovalVerificationException extends Exception
{
    public const int GENERAL_ERROR = 0;

    public const int NOT_FOUND = 1;

    public const int CREATION_FAILED = 2;

    public const int CREATION_PENDING = 3;

    public const int UNAVAILABLE = 4;

    public const int EXPIRED = 5;

    public const int ALREADY_VERIFIED = 6;

    public const int EMPLOYEE_UNAVAILABLE = 7;

    /**
     * Build an approval verification exception with a translated default or a custom message.
     *
     * The error type selects the default message; GENERAL_ERROR and unknown types use the
     * general verification error. For CREATION_FAILED, non-empty eHealth error details
     * are formatted and appended to either the default or custom message.
     *
     * @param  int  $errorType  One of this class's error constants; defaults to GENERAL_ERROR.
     * @param  array|null  $error  Inner eHealth "error" payload (e.g. $link->error), used only for CREATION_FAILED.
     * @param  string  $status  Approval status substituted into :status in the default UNAVAILABLE message.
     * @param  string|null  $message  Custom base message used as-is; null selects the translated default.
     */
    public function __construct(
        int $errorType = self::GENERAL_ERROR,
        ?array $error = null,
        string $status = '',
        ?string $message = null
    ) {
        $message ??= $this->resolveErrorMessage($errorType, $status);

        if ($errorType === self::CREATION_FAILED && $error) {
            $message .= ' '.(new EHealthValidationException(['error' => $error]))->getFormattedMessage();
        }

        parent::__construct($message);
    }

    /**
     * Select the default translation; GENERAL_ERROR and unknown types use the general message.
     */
    protected function resolveErrorMessage(int $errorType, string $status): string
    {
        return match ($errorType) {
            self::NOT_FOUND => __('patients.errors.approval_not_found'),
            self::CREATION_FAILED => __('patients.errors.approval_not_created'),
            self::CREATION_PENDING => __('patients.errors.approval_creation_pending'),
            self::UNAVAILABLE => __('patients.errors.approval_unavailable', ['status' => $status]),
            self::EXPIRED => __('patients.errors.approval_expired'),
            self::ALREADY_VERIFIED => __('patients.errors.approval_already_verified'),
            self::EMPLOYEE_UNAVAILABLE => __('patients.errors.approval_employee_unavailable'),
            default => __('patients.errors.approval_verification_failed'),
        };
    }
}

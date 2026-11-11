<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

/** Translate referral-specific refusals in the API resource that owns them. */
class ServiceRequestErrorTranslator extends BaseErrorTranslator
{
    protected function messages(): array
    {
        return [
            'Not allowed for this category' => __('referrals.messages.completion_type_not_allowed'),
        ];
    }
}

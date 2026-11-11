<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

/** Translate medical conclusion errors for both HTTP failures and async job results. */
class CompositionErrorTranslator extends BaseErrorTranslator
{
    protected function messages(): array
    {
        return [
            'Same end date for new and existing composition' => __('errors.ehealth.rules.1103'),
            'Encounter type is not allowed for composition type' => __('errors.ehealth.rules.1038'),
            "Can't get phone number" => __('errors.ehealth.rules.1017'),
            '1103: Same end date for new and existing composition' => __('errors.ehealth.rules.1103'),
            '1038: Encounter type is not allowed for composition type' => __('errors.ehealth.rules.1038'),
            "1017: Can't get phone number" => __('errors.ehealth.rules.1017'),
        ];
    }
}

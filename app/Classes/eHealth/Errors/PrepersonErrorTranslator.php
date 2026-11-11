<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

/**
 * Translates the errors of the preperson resource.
 */
class PrepersonErrorTranslator extends BaseErrorTranslator
{
    /**
     * Messages of the preperson resource: original message => translation.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'Legal entity does not have appropriate healthcare services' => __('preperson.api_errors.no_appropriate_healthcare_services'),
            "Birth date can't be in the future" => __('preperson.api_errors.birth_date_in_future'),
            'External_id should not be empty' => __('preperson.api_errors.external_id_empty'),
            'Preperson is not active' => __('preperson.api_errors.preperson_not_active'),
            'Death date can not be in future' => __('preperson.api_errors.death_date_in_future'),
            'Death date should equal or greater than birth_date' => __('preperson.api_errors.death_date_before_birth_date'),
            'Check if the preperson_id exists failed' => __('preperson.api_errors.preperson_existence_check_failed')
        ];
    }
}

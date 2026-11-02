<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

use App\Contracts\ApiErrorTranslator;

/**
 * Translates the errors every API resource can return. A resource with its own errors extends this class and lists
 * them in messages(), they are checked before the base ones.
 */
class BaseErrorTranslator implements ApiErrorTranslator
{
    /**
     * Get the Ukrainian text of an error message the API returned, or null when the message is unknown.
     *
     * @param  string  $message
     * @return string|null
     */
    public function translate(string $message): ?string
    {
        $message = trim($message);

        foreach ($this->messages() + $this->baseMessages() as $original => $translation) {
            if (preg_match($this->pattern($original), $message, $matches) === 1) {
                $placeholders = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);

                return str_replace(
                    array_map(static fn (string $name): string => ":$name", array_keys($placeholders)),
                    $placeholders,
                    $translation
                );
            }
        }

        return null;
    }

    /**
     * Messages of the resource: original message => translation. A {name} in the message matches any text and
     * replaces :name in the translation.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Messages every resource can return: original message => translation.
     *
     * @return array
     */
    private function baseMessages(): array
    {
        return [
            'Invalid access token' => __('errors.ehealth.messages.invalid_access_token'),
            'Access token validation failed' => __('errors.ehealth.messages.access_token_validation_failed'),
            'Access token validation fails' => __('errors.ehealth.messages.access_token_validation_failed'),
            'Invalid scope(s)' => __('errors.ehealth.messages.access_denied'),
            'Invalid scope' => __('errors.ehealth.messages.invalid_scope'),
            'Invalid scopes' => __('errors.ehealth.messages.invalid_scope'),
            'Forbidden' => __('errors.ehealth.messages.access_denied'),
            'User is not allowed to perform this action' => __('errors.ehealth.messages.action_not_allowed'),
            'Access denied. Party is not verified' => __('errors.ehealth.messages.party_not_verified'),
            'Access denied. Party is deceased' => __('errors.ehealth.messages.party_deceased'),
            'Legal entity not found' => __('errors.ehealth.messages.legal_entity_not_found'),
            'Legal entity with such id is not found' => __('errors.ehealth.messages.legal_entity_not_found'),
            'client_id refers to legal entity that is not active' => __('errors.ehealth.messages.legal_entity_not_active'),
            'Legal entity must be ACTIVE' => __('errors.ehealth.messages.legal_entity_must_be_active'),
            'Legal entity is not active' => __('errors.ehealth.messages.legal_entity_is_not_active'),
            'Invalid legal entity type' => __('errors.ehealth.messages.invalid_legal_entity_type'),
            'Division not found' => __('errors.ehealth.messages.division_not_found'),
            'Division is not active' => __('errors.ehealth.messages.division_not_active'),
            'value is not allowed in enum' => __('errors.ehealth.messages.value_not_allowed_in_enum'),
            'Request validation fails' => __('errors.ehealth.messages.validation_error'),
            'Validation error' => __('errors.ehealth.messages.request_parameters_validation_error'),
            'Employee not found' => __('errors.ehealth.messages.employee_not_found'),
            "Employee doesn't match with user" => __('errors.ehealth.messages.employee_user_mismatch'),
            'Employee is not active' => __('errors.ehealth.messages.employee_not_active'),
            'Employee does not belong to legal entity from token' => __('errors.ehealth.messages.employee_of_another_legal_entity'),
            'Employee is not verified' => __('errors.ehealth.messages.employee_not_verified'),
            'Employee is from another legal entity' => __('errors.ehealth.messages.employee_from_another_legal_entity'),
            'Device definition not found' => __('errors.ehealth.messages.device_definition_not_found'),
            'Invalid transition' => __('errors.ehealth.messages.invalid_transition'),
            'Invalid signature' => __('errors.ehealth.messages.invalid_signature'),
            'Invalid verification code' => __('errors.ehealth.messages.verification_code_invalid'),
            'Invalid drfo' => __('errors.ehealth.messages.invalid_drfo'),
            'Signed content does not match the previously created content' => __('errors.ehealth.messages.signed_content_mismatch'),
            'Does not match the signer drfo' => __('errors.ehealth.messages.signer_drfo_mismatch'),
            'retrieving a timestamp of data from an envelope with signed data failed' => __('errors.ehealth.messages.signature_timestamp_retrieving_failed'),
            'loading information about the response with a timestamp failed' => __('errors.ehealth.messages.signature_timestamp_loading_failed'),
            'retrieving certificate from signed data container failed' => __('errors.ehealth.messages.signature_certificate_retrieving_failed'),
            'processing certificate information from signed data failed' => __('errors.ehealth.messages.signature_certificate_processing_failed'),
            'matching ROOT certificate not found' => __('errors.ehealth.messages.signature_root_certificate_not_found'),
            'ROOT certificate signature verification failed' => __('errors.ehealth.messages.signature_root_certificate_verification_failed'),
            'loading ROOT certificate information failed' => __('errors.ehealth.messages.signature_root_certificate_loading_failed'),
            'matching TSP certificate not found' => __('errors.ehealth.messages.signature_tsp_certificate_not_found'),
            'checking the signature of a response with a timestamp failed' => __('errors.ehealth.messages.signature_timestamp_verification_failed'),
            'verification of data siganture for a given subscriber failed' => __('errors.ehealth.messages.signature_verification_failed'),
            'error processing signed data' => __('errors.ehealth.messages.signed_data_processing_failed'),
            'Failed to decode signed content' => __('errors.ehealth.messages.signed_content_decode_failed'),
            'Invalid signed content' => __('errors.ehealth.messages.invalid_signed_content'),
            'Failed to save signed content' => __('errors.ehealth.messages.signed_content_save_failed'),
            "string matches pattern '{pattern}' but it should not" => __('errors.ehealth.messages.string_matches_forbidden_pattern'),
            'string does not match pattern' => __('errors.ehealth.messages.string_does_not_match_pattern'),
            'string does not match pattern {pattern}' => __('errors.ehealth.messages.string_does_not_match_pattern'),
            'schema does not allow additional properties' => __('errors.ehealth.messages.schema_additional_properties_not_allowed'),
            'type mismatch. Expected number but got {actual}' => __('errors.ehealth.messages.type_mismatch_number'),
            'type mismatch. Expected boolean but got {actual}' => __('errors.ehealth.messages.type_mismatch_boolean'),
            'type mismatch. Expected string but got {actual}' => __('errors.ehealth.messages.type_mismatch_string'),
            'type mismatch. Expected object but got {actual}' => __('errors.ehealth.messages.type_mismatch_object'),
            'expected "{actual}" to be a valid ISO 8601 date' => __('errors.ehealth.messages.invalid_iso8601_date'),
            'Invalid. Expected "{actual}" to be a valid ISO 8601 date' => __('errors.ehealth.messages.invalid_iso8601_date'),
            'expected "{actual}" to be a valid ISO 8601 date-time' => __('errors.ehealth.messages.invalid_iso8601_date_time')
        ];
    }

    /**
     * Turn the original message into a regular expression that captures each {name} under that name, e.g.
     * 'has {status} status' becomes '/^has (?<status>.+?) status\.?$/iu'. Letter case and a trailing period are ignored.
     *
     * @param  string  $original
     * @return string
     */
    private function pattern(string $original): string
    {
        $escaped = preg_quote($original, '/');
        $withPlaceholders = str_replace(['\{', '\}'], ['(?<', '>.+?)'], $escaped);

        return '/^' . $withPlaceholders . '\.?$/iu';
    }
}

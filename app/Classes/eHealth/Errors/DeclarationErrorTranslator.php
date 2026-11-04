<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

/**
 * Translates the errors of the declaration and declaration request resources.
 */
final class DeclarationErrorTranslator extends BaseErrorTranslator
{
    /**
     * Messages of the declaration resources: original message => translation.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'Authentication method with type THIRD_PERSON must be submitted for this person' => __('declarations.api_errors.third_person_authentication_required'),
            "Declaration request can't be create for person with document issuing country {issuing_country}" => __('declarations.api_errors.document_issuing_country_not_allowed'),
            "Declaration request can't be create for person with such document type, need one of {types}" => __('declarations.validation.document_type_not_allowed'),
            'For person <{years}years declaration request can be create after added birth certificate' => __('declarations.api_errors.birth_certificate_required'),
            'A person has more than one active declaration' => __('declarations.api_errors.several_active_declarations'),
            'Declaration is not active' => __('declarations.api_errors.not_active'),
            'Declaration not found' => __('declarations.api_errors.not_found'),
            'Can not be empty' => __('declarations.api_errors.cannot_be_empty'),
            'Declaration request should be approved by Doctor' => __('declarations.api_errors.request_not_approved'),
            "declaration_request sequence doesn't return a number" => __('declarations.api_errors.request_number_not_generated'),
            "Doctor speciality doesn't match patient's age" => __('declarations.api_errors.speciality_age_mismatch'),
            'Employee does not belong to legal entity' => __('errors.ehealth.messages.employee_of_another_legal_entity'),
            'Employee ID must reference a doctor' => __('declarations.validation.employee_not_doctor'),
            'Invalid employee status' => __('declarations.api_errors.invalid_employee_status'),
            'Invalid person' => __('declarations.api_errors.invalid_person'),
            'Legal entity is not verified' => __('declarations.api_errors.legal_entity_not_verified'),
            'one and only one residence address is required' => __('patients.api_errors.single_residence_address_required'),
            'Patient must sign declaration form' => __('declarations.api_errors.patient_signature_required'),
            'person is not active' => __('patients.api_errors.person_not_active'),
            'person is not found' => __('patients.api_errors.person_not_found'),
            "Person's tax ID in not valid" => __('errors.ehealth.messages.invalid_tax_id'),
            'required property phone_number was not present' => __('declarations.api_errors.phone_number_missing'),
            'Such person already exists' => __('declarations.api_errors.person_already_exists'),
            'Active parent declaration was not found' => __('declarations.validation.parent_declaration_not_active'),
            'Parent declaration does not belong to this person' => __('declarations.api_errors.parent_declaration_of_another_person'),
            'Legal entities of parent declaration and current are not in reorganization process' => __('declarations.api_errors.legal_entities_not_in_reorganization'),
            'Employee of parent declaration and current employee are not the same' => __('declarations.api_errors.parent_declaration_employee_mismatch')
        ];
    }
}

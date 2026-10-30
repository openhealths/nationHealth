<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

/**
 * Translates the errors of the person and person request resources.
 */
final class PersonErrorTranslator extends BaseErrorTranslator
{
    /**
     * Messages of the person resources: original message => translation.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'Birth certificate number is not valid' => __('patients.api_errors.birth_certificate_number_invalid'),
            'Confidant person is mandatory for children' => __('patients.api_errors.confidant_person_required_for_children'),
            'Error during OTP Verification interaction. Result from OTP Verification: {result}' => __('patients.api_errors.otp_verification_failed'),
            'Failed to save signed content' => __('patients.api_errors.signed_content_save_failed'),
            'Invalid birth date' => __('patients.api_errors.birth_date_invalid'),
            'Must be one and only one authentication method' => __('patients.api_errors.one_authentication_method_allowed'),
            'Must contain required item' => __('patients.api_errors.required_item_missing'),
            'Person can have only new passport NATIONAL_ID or old PASSPORT' => __('patients.api_errors.national_id_or_passport_only'),
            'Person not found' => __('patients.api_errors.person_not_found'),
            'Such person exists. Update this person' => __('patients.api_errors.person_already_exists'),
            'Incorrect person age for such an action' => __('patients.api_errors.person_age_not_allowed'),
            'Person that logged in, person that is changed and person that sign should be the same' => __('patients.api_errors.logged_changed_signed_person_mismatch'),
            'The phone number is not verified' => __('patients.api_errors.phone_number_not_verified'),
            'This fiduciary person is present more than {count} times in the system' => __('patients.api_errors.third_person_limit_exceeded'),
            "Such person can't be updated. Deduplication update score is lower than system value (less changes should be made)" => __('patients.api_errors.deduplication_update_score_too_low'),
            'This phone number is present more than {count} times in the system' => __('patients.api_errors.phone_number_limit_exceeded'),
            "Phone number doesn't belong to third person" => __('patients.api_errors.phone_number_not_of_third_person'),
            "Such person id is already used in existing person's authorization methods" => __('patients.api_errors.person_id_used_in_authentication_methods'),            'Your scope does not allow to access this resource. Missing allowances: person:read' => __('patients.api_errors.person_read_not_allowed'),
            'Your scope does not allow to access this resource. Missing allowances: person_verification:details' => __('patients.api_errors.person_verification_details_not_allowed'),            'document_number and document_type or tax_id should be present' => __('patients.api_errors.document_or_tax_id_required'),
            "Such person isn't active" => __('patients.api_errors.person_not_active'),
            "Authentication method isn't active" => __('patients.api_errors.authentication_method_not_active'),
            'Limit of authentication methods with THIRD_PERSON type is exhausted' => __('patients.api_errors.third_person_authentication_methods_limit_exhausted'),
            'This phone number is present more than {limit}' => __('patients.api_errors.phone_number_authentication_limit_exceeded'),
            'Only OTP authentication method can be created for person who has relationship with other patients as confidant' => __('patients.api_errors.only_otp_for_confidant_of_other_patients'),
            "Person can't be authorized with NA authentication method" => __('patients.api_errors.na_authentication_method_not_allowed'),
            'Such a phone already exists more {count} times' => __('patients.api_errors.phone_number_limit_exhausted'),
            'Such authentication method does not belong to this person' => __('patients.api_errors.authentication_method_of_another_person'),
            'Third person must be active' => __('patients.api_errors.third_person_not_active'),
            'Only confidants can be set as third persons' => __('patients.api_errors.only_confidant_as_third_person'),
            "Such person doesn't exist" => __('patients.api_errors.person_not_found'),
            'Only THIRD_PERSON authentication method type could be deactivated' => __('patients.api_errors.only_third_person_can_be_deactivated'),
            'Person already has auth method OFFLINE' => __('patients.api_errors.person_already_has_offline_auth_method'),
            'Only THIRD_PERSON authentication method can be created for person who has confidants' => __('patients.api_errors.only_third_person_for_person_with_confidants'),
            'Person cannot set OFFLINE auth method if person had OTP' => __('patients.api_errors.offline_auth_method_not_allowed_with_otp'),
            'Such person cannot have self authentication method' => __('patients.api_errors.self_authentication_method_not_allowed'),
            "You can't deactivate the last authentication method" => __('patients.api_errors.last_authentication_method_deactivation_not_allowed'),
            'Your scope does not allow to access this resource. Missing allowances: authentication_method_request:write' => __('patients.api_errors.authentication_method_request_write_not_allowed'),
            'Cannot be confirmed by method with not approved confidant person relationship' => __('patients.api_errors.confidant_relationship_not_approved'),
            'Person is not found' => __('patients.api_errors.person_not_found'),
            'Confidant Person relationship request is not found' => __('patients.api_errors.confidant_relationship_request_not_found'),
            'Your scope does not allow to access this resource. Missing allowances: confidant_person_relationship_request:write' => __('patients.api_errors.confidant_relationship_request_write_not_allowed'),
            'Tax_id is already used by another person' => __('patients.api_errors.tax_id_already_used'),
            'Persons who refused the tax_id should be without tax_id' => __('patients.api_errors.refused_tax_id_must_be_empty'),
            'Only persons who refused the tax_id could be without tax_id' => __('patients.api_errors.only_refused_tax_id_can_be_empty'),
            'One and only one residence address is required' => __('patients.api_errors.single_residence_address_required'),
            'Confidant person is mandatory for minor patients' => __('patients.api_errors.confidant_person_required_for_minor'),
            'Confidant can not be submitted for person who has document that proves legal capacity' => __('patients.api_errors.confidant_prohibited_for_legally_capable_person'),
            'Invalid relationship document type for person' => __('validation.custom.person.invalid_relationship_document_for_age'),
            'Your scope does not allow to access this resource. Missing allowances: person_request:write' => __('patients.api_errors.person_request_write_not_allowed'),
            'Person request not found' => __('patients.api_errors.person_request_not_found'),
            'Documents {documents} is not uploaded' => __('patients.api_errors.documents_not_uploaded'),
            'Client is not allowed to approve person_request' => __('patients.api_errors.person_request_approve_not_allowed'),
            'Only person request with MIS channel can be approved' => __('patients.api_errors.only_mis_channel_can_be_approved'),            'This phone number is present more then {count} times in the system' => __('patients.api_errors.phone_number_limit_exceeded'),
            'Your scope does not allow to access this resource. Missing allowances: person_request:sign' => __('patients.api_errors.person_request_sign_not_allowed'),
            'Client is not allowed to sign person_request' => __('patients.api_errors.person_request_client_sign_not_allowed'),            'Only person request with MIS channel can be signed' => __('patients.api_errors.only_mis_channel_can_be_signed'),
            'Client is not allowed to reject person_request' => __('patients.api_errors.person_request_client_reject_not_allowed'),
            'Auth method is not OTP' => __('patients.api_errors.auth_method_not_otp'),
            'Client is not allowed to get person_request' => __('errors.ehealth.messages.action_not_allowed'),
            'Your scope does not allow to access this resource. Missing allowances: person_request:read' => __('patients.api_errors.person_request_read_not_allowed'),
            'Your scope does not allow to access this resource. Missing allowances: person_verification:write' => __('patients.api_errors.person_verification_write_not_allowed'),
            'Only one of the parameters must be present' => __('patients.api_errors.only_one_parameter_allowed'),
            'Verification details for person in {status} status can not be updated' => __('patients.api_errors.verification_details_not_updatable'),
            'Verification reason ({reason}) is not allowed for person DRACS death status' => __('patients.api_errors.verification_reason_not_allowed_for_death_status'),
            'Death date must not be in the future' => __('patients.api_errors.death_date_in_future'),
            'Death date must not be present with MANUAL_NOT_CONFIRMED verification_reason' => __('patients.api_errors.death_date_with_manual_not_confirmed'),
            'Your scope does not allow to access this resource. Missing allowances: person_verification:read' => __('patients.api_errors.person_verification_read_not_allowed')
        ];
    }
}

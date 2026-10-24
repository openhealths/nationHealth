<?php

declare(strict_types=1);

return [
    'signed_details_pending' => 'ESOZ accepted the signature. Full details are pending. Use Synchronize signed prescriptions in the patient registry; do not sign again.',
    'signed_details_synchronized' => 'Signed prescription details synchronized.',
    'synchronize_signed_details' => 'Synchronize signed prescriptions',

    'signing_details_wrong_request' => 'eHealth returned a different request. Signing was stopped. Refresh the request details and try again.',
    'signing_details_wrong_patient' => 'The patient in the eHealth response does not match this request. Signing was stopped.',
    'signing_details_not_new' => 'The eHealth request is no longer NEW. Check its status before trying to sign again.',
    'signing_details_incomplete' => 'eHealth returned incomplete request details. Refresh and try again; a list summary cannot be signed.',
    'signing_details_invalid_seed' => 'eHealth returned an invalid signing seed. Refresh the request details and try again.',
    'resume_signing' => 'Continue signing',
    'pending_signing_note' => 'The request already exists. Continuing signs that request without creating a duplicate; editing the form does not change the existing request.',
];

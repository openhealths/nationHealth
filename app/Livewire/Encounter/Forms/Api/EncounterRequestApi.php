<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms\Api;

class EncounterRequestApi
{
    /**
     * Build an array of parameters for a service request list.
     *
     * @param  string  $requisition  A shared identifier common to all service requests that were authorized more or less simultaneously by a single author, representing the composite or group identifier. Example: AX654-654T.
     * @param  string  $status  The status of the service request. Default: active.
     * @param  int  $page  Page number. Default: 1.
     * @param  int  $pageSize  A limit on the number of objects to be returned, between 1 and 100. Default: 50.
     * @return array
     */
    public static function buildGetServiceRequestList(
        string $requisition,
        string $status = 'active',
        int $page = 1,
        int $pageSize = 50
    ): array {
        return [
            'requisition' => $requisition,
            'status' => $status,
            'page' => $page,
            'page_size' => $pageSize
        ];
    }

    /**
     * Build an array of parameters for a conditions list in episode context.
     *
     * @param  string  $patientUuid  Patient identifier Example: 70a9e15b-b71b-4caf-8f2e-ff247e8a5677.
     * @param  string  $episodeUuid  Episode identifier Example: a10aeafb-0df2-4091-bc83-f07e92a100ae.
     * @param  string|null  $code  Example: A20.
     * @param  string|null  $onsetDateFrom  Example: 1990-01-01.
     * @param  string|null  $onsetDateTo  Example: 2000-01-01.
     * @param  int  $page  Page number. Default: 1. Example: 2.
     * @param  int  $pageSize  A limit on the number of objects to be returned, between 1 and 100. Default: 50. Example: 50.
     * @return array
     */
    public static function buildGetConditionsInEpisodeContext(
        string $patientUuid,
        string $episodeUuid,
        ?string $code = null,
        ?string $onsetDateFrom = null,
        ?string $onsetDateTo = null,
        int $page = 1,
        int $pageSize = 50
    ): array {
        return [
            'patient_id' => $patientUuid,
            'episode_id' => $episodeUuid,
            'code' => $code,
            'onset_date_from' => $onsetDateFrom,
            'onset_date_to' => $onsetDateTo,
            'page' => $page,
            'page_size' => $pageSize
        ];
    }

    /**
     * Build an array of parameters for an observations list in episode context.
     *
     * @param  string  $patientUuid  Patient identifier Example: 70a9e15b-b71b-4caf-8f2e-ff247e8a5677.
     * @param  string  $episodeUuid  Episode identifier Example: a10aeafb-0df2-4091-bc83-f07e92a100ae.
     * @param  string|null  $code  Example: 10569-2.
     * @param  string|null  $issuedFrom  Example: 1990-01-01.
     * @param  string|null  $issuedTo  Example: 2000-01-01.
     * @param  int  $page  Page number. Default: 1. Example: 2.
     * @param  int  $pageSize  A limit on the number of objects to be returned, between 1 and 100. Default: 50. Example: 50.
     * @return array
     */
    public static function buildGetObservationsInEpisodeContext(
        string $patientUuid,
        string $episodeUuid,
        ?string $code = null,
        ?string $issuedFrom = null,
        ?string $issuedTo = null,
        int $page = 1,
        int $pageSize = 50
    ): array {
        return [
            'patient_id' => $patientUuid,
            'episode_id' => $episodeUuid,
            'code' => $code,
            'issued_from' => $issuedFrom,
            'issued_to' => $issuedTo,
            'page' => $page,
            'page_size' => $pageSize
        ];
    }

    /**
     * Build an array of parameters for getting clinical impressions using a search parameters list.
     *
     * @param  string  $patientUuid  MPI identifier of the patient. Example: 7c3da506-804d-4550-8993-bf17f9ee0402
     * @param  string|null  $encounterUuid  Identifier of the encounter in clinical impression. Example: 7c3da506-804d-4550-8993-bf17f9ee0400
     * @param  string|null  $episodeUuid  Example: f48d1b6c-a021-4d6a-a5a4-aee93e152ecc
     * @param  string|null  $code  Clinical impression's code. Example: insulin_1
     * @param  string|null  $status  Clinical impression's status. Example: completed
     * @param  string|null  $effectiveDateTo  Date of clinical impression. Example: 2017-09-01
     * @param  string|null  $effectiveDateFrom  Date of clinical impression. Example: 2017-09-02
     * @param  int  $page  Page number. Default: 1
     * @param  int  $pageSize  A limit on the number of objects to be returned, between 1 and 100. Default: 50.
     * @return array
     */
    public static function buildGetClinicalImpressionBySearchParams(
        string $patientUuid,
        ?string $encounterUuid = null,
        ?string $episodeUuid = null,
        ?string $code = null,
        ?string $status = null,
        ?string $effectiveDateTo = null,
        ?string $effectiveDateFrom = null,
        int $page = 1,
        int $pageSize = 50
    ): array {
        return [
            'patient_id' => $patientUuid,
            'encounter_id' => $encounterUuid,
            'episode_id' => $episodeUuid,
            'code' => $code,
            'status' => $status,
            'effective_date_to' => $effectiveDateTo,
            'effective_date_from' => $effectiveDateFrom,
            'page' => $page,
            'page_size' => $pageSize
        ];
    }
}

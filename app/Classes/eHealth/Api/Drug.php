<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class Drug extends Request
{
    public const string URL = '/api/v3/drugs';

    /**
     * Receives a list of drugs.
     *
     * @param  array{
     *     innm_id?: string,
     *     innm_name?: string,
     *     innm_sctid?: string,
     *     innm_dosage_form?: string,
     *     innm_dosage_id?: string,
     *     innm_dosage_name?: string,
     *     medication_code_atc?: string,
     *     medical_program_id?: string,
     *     mr_blank_type?: string,
     *     dosage_form_is_dosed?: bool,
     *     medication_request_allowed?: bool,
     *     care_plan_activity_allowed?: bool,
     *     page?: int,
     *     page_size?: int
     * }  $filters
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-reimbursement/drugs/get-drugs-list-v3
     */
    public function getMany(array $filters = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $filters
        );

        return $this->get(self::URL, $mergedQuery);
    }
}
